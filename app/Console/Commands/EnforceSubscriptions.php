<?php

namespace App\Console\Commands;

use App\Domain\Billing\Actions\DowngradeToFree;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use Illuminate\Console\Command;

/**
 * Daily (TDD: subscriptions:enforce-limits): trial reminders, trials and missed renewals
 * moving to past due with a 7-day grace period, and lots whose grace period or paid month
 * has ended moving to the Free plan.
 */
class EnforceSubscriptions extends Command
{
    protected $signature = 'subscriptions:enforce-limits';

    protected $description = 'Remind, mark overdue and downgrade subscriptions';

    public function handle(DowngradeToFree $downgrade): int
    {
        $grace = (int) config('lotlink.billing.grace_days');
        $counts = ['reminded' => 0, 'past_due' => 0, 'downgraded' => 0];

        Subscription::with(['lot.owner', 'plan'])->whereIn('status', [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->each(function (Subscription $s) use ($downgrade, $grace, &$counts): void {
                $lot = $s->lot;
                if ($lot === null) {
                    return;
                }

                // Trial ends in the next 3 days: one reminder.
                if ($s->status === SubscriptionStatus::Trialing && $s->trial_ends_at?->isFuture() && $s->trial_ends_at->lte(now()->addDays(3)) && $s->trial_reminded_at === null) {
                    $lot->owner?->notify(new BillingNotice($lot, "Your free {$s->plan->name} trial ends on {$s->trial_ends_at->timezone($lot->timezone)->format('j M')}. Choose a plan to keep your cars and staff."));
                    $s->forceFill(['trial_reminded_at' => now()])->save();
                    $counts['reminded']++;
                }

                $trialOver = $s->status === SubscriptionStatus::Trialing && $s->trial_ends_at !== null && $s->trial_ends_at->isPast();
                // Paystack renews by webhook; a day without one means the charge didn't happen.
                $renewalMissed = $s->status === SubscriptionStatus::Active && $s->cancel_at_period_end === null
                    && $s->current_period_end !== null && $s->current_period_end->lt(now()->subDay());

                if ($trialOver || $renewalMissed) {
                    $s->forceFill(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->addDays($grace)])->save();
                    $lot->owner?->notify(new BillingNotice($lot, ($trialOver ? 'Your free trial has ended.' : 'Your plan was not renewed.')." Your cars stay live until {$s->grace_ends_at?->timezone($lot->timezone)->format('j M')}; choose a plan to keep them all."));
                    $counts['past_due']++;

                    return;
                }

                $stopped = $s->status === SubscriptionStatus::Active && $s->cancel_at_period_end !== null && $s->current_period_end?->isPast();
                $graceOver = $s->status === SubscriptionStatus::PastDue && $s->grace_ends_at?->isPast();

                if ($stopped || $graceOver) {
                    $downgrade->run($lot, $stopped ? 'your paid month ended' : 'the plan was not paid');
                    $counts['downgraded']++;
                }
            });

        $this->info("Reminded {$counts['reminded']}, past due {$counts['past_due']}, moved to Free {$counts['downgraded']}.");

        return self::SUCCESS;
    }
}
