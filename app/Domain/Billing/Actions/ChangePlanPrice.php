<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Money;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * An admin changes a plan's monthly price. Each provider with the plan (Paystack, Flutterwave) is
 * updated first (its plan is what renews cards), so CarYard and the providers never disagree about
 * what a new subscriber pays. Current subscribers either keep their price or pay the new one from
 * their next renewal, and are told. Flutterwave can't reprice a plan, so it gets a new plan for new
 * subscribers, and "everyone" is refused while it bills anyone on this plan.
 */
class ChangePlanPrice
{
    public function __construct(private readonly PaymentGateways $gateways) {}

    /** @return int how many paying lots were told about the change */
    public function run(Plan $plan, int $naira, bool $existingSubscribers, User $admin): int
    {
        $new = Money::fromMajor($naira);
        $old = $plan->price;

        if ($plan->isFree() && $new !== 0) {
            throw ValidationException::withMessages(['price' => 'The free plan stays free. Change a paid plan instead.']);
        }
        if (! $plan->isFree() && $new <= 0) {
            throw ValidationException::withMessages(['price' => 'A paid plan needs a price. Sellers move to the free plan by downgrading.']);
        }
        if ($new === $old) {
            return 0;
        }

        $codes = [];
        foreach (PaymentGateways::PROVIDERS as $provider => $label) {
            $code = $plan->codeFor($provider);
            if ($code === null) {
                continue;
            }
            // Only matters where that provider actually bills someone on this plan.
            $existing = $existingSubscribers && ($provider === 'paystack' || $this->billed($plan, $provider));
            try {
                $codes[$provider] = $this->gateways->for($provider)->updatePlan($code, $new, $existing);
            } catch (Throwable $e) {
                throw ValidationException::withMessages(['price' => "{$label} didn't accept the change, so nothing was changed: {$e->getMessage()}"]);
            }
        }

        $plan->update(['price' => $new]);
        foreach ($codes as $provider => $code) {
            if ($code !== $plan->codeFor($provider)) {
                $plan->setCodeFor($provider, $code);
            }
        }
        AuditLog::record('admin.plan_price_changed', $plan, [
            'before' => $old, 'after' => $new, 'existing_subscribers' => $existingSubscribers,
        ], $admin);

        return $existingSubscribers ? $this->tell($plan, $old, $new) : 0;
    }

    private function billed(Plan $plan, string $provider): bool
    {
        return Subscription::withoutGlobalScopes()->where('plan_id', $plan->id)->where('provider', $provider)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])->exists();
    }

    /** Paying lots on this plan hear about it before their next renewal. */
    private function tell(Plan $plan, int $old, int $new): int
    {
        $subscriptions = Subscription::withoutGlobalScopes()->where('plan_id', $plan->id)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNull('cancel_at_period_end')
            ->get();

        foreach ($subscriptions as $subscription) {
            $lot = Lot::withoutGlobalScopes()->find($subscription->lot_id);
            if ($lot === null) {
                continue;
            }

            $when = $subscription->current_period_end?->copy()->setTimezone($lot->timezone)->format('j M Y');
            $text = "Your {$plan->name} plan changes from ".Money::format($old, $plan->currency).' to '.Money::format($new, $plan->currency)
                .' a month'.($when ? " from your next renewal on {$when}." : ' from your next renewal.').' You can change or cancel your plan any time before then.';
            $owners = $lot->members()->wherePivotIn('role', [LotRole::Owner->value])->get();
            Notification::send($owners, new BillingNotice($lot, $text));
        }

        return $subscriptions->count();
    }
}
