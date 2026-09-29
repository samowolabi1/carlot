<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use Illuminate\Support\Carbon;

/**
 * What happens when a provider renews (or fails to renew, or stops renewing) a subscription,
 * shared by the Paystack and Flutterwave webhooks. Each renewal is recorded once (by reference).
 */
class RecordRenewal
{
    public function paid(Subscription $subscription, string $provider, string $reference, int $amount, string $currency, ?Carbon $paidAt): void
    {
        if (Payment::where('reference', $reference)->exists()) {
            return;
        }
        $subscription->loadMissing('plan');

        Payment::create([
            'payable_type' => $subscription->getMorphClass(),
            'payable_id' => $subscription->id,
            'lot_id' => $subscription->lot_id,
            'purpose' => PaymentPurpose::Renewal,
            'description' => "{$subscription->plan->name} plan renewal",
            'amount' => $amount,
            'currency' => $currency,
            'provider' => $provider,
            'reference' => $reference,
            'status' => PaymentStatus::Success,
            'paid_at' => $paidAt ?? now(),
        ]);

        $from = $subscription->current_period_end !== null && $subscription->current_period_end->isFuture() ? $subscription->current_period_end : now();
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_end' => $from->copy()->addMonth(),
            'grace_ends_at' => null,
        ])->save();
    }

    public function failed(Subscription $subscription, ?int $amount): void
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            return;
        }

        $grace = now()->addDays((int) config('lotlink.billing.grace_days'));
        $subscription->forceFill(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => $grace])->save();

        $lot = Lot::findOrFail($subscription->lot_id);
        $what = $amount !== null ? Money::format($amount) : 'your plan';
        $lot->owner?->notify(new BillingNotice($lot, "We couldn't charge your card for {$what}. Update your card by {$grace->timezone($lot->timezone)->format('j M')} to keep all your cars live."));
    }

    public function stopped(Subscription $subscription): void
    {
        if ($subscription->status === SubscriptionStatus::Active && $subscription->cancel_at_period_end === null) {
            $subscription->forceFill(['cancel_at_period_end' => now()])->save();
        }
    }
}
