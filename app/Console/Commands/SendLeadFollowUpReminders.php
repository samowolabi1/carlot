<?php

namespace App\Console\Commands;

use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Notifications\LeadFollowUpDue;
use App\Domain\Lots\Models\Lot;
use Illuminate\Console\Command;

/** Every 15 minutes (TDD: leads:follow-up-reminders): tell reps about follow-ups that are due. */
class SendLeadFollowUpReminders extends Command
{
    protected $signature = 'leads:follow-up-reminders';

    protected $description = 'Remind assigned reps (or the owner) of lead follow-ups that are due';

    public function handle(): int
    {
        $sent = 0;

        Lead::withoutGlobalScopes()->with(['assignee', 'customer'])
            ->whereNotIn('stage', [LeadStage::Won, LeadStage::Lost])
            ->whereNull('follow_up_reminded_at')
            ->where('next_follow_up_at', '<=', now())
            ->where('next_follow_up_at', '>', now()->subDay())
            ->each(function (Lead $lead) use (&$sent): void {
                $to = $lead->assignee ?? Lot::with('owner')->find($lead->lot_id)?->owner;
                $to?->notify(new LeadFollowUpDue($lead));
                $lead->forceFill(['follow_up_reminded_at' => now()])->save();
                $sent++;
            });

        $this->info("Sent {$sent} lead follow-up reminders.");

        return self::SUCCESS;
    }
}
