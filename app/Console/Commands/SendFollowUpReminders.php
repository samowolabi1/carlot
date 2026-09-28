<?php

namespace App\Console\Commands;

use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Notifications\FollowUpDue;
use Illuminate\Console\Command;

/** Every 15 minutes: tell staff about follow-ups that have come due (TDD M19). */
class SendFollowUpReminders extends Command
{
    protected $signature = 'manager:follow-up-reminders';

    protected $description = 'Remind staff about follow-up calls that are due';

    public function handle(): int
    {
        $sent = 0;

        FollowUpTask::withoutGlobalScopes()
            ->with(['assignee', 'customer'])
            ->whereNull('done_at')
            ->whereNull('reminded_at')
            ->where('due_at', '<=', now())
            // Old tasks are shown on the Today page, not messaged a day late.
            ->where('due_at', '>', now()->subDay())
            ->each(function (FollowUpTask $task) use (&$sent): void {
                if ($task->assignee !== null && $task->customer !== null) {
                    $task->assignee->notify(new FollowUpDue($task));
                    $sent++;
                }
                $task->forceFill(['reminded_at' => now()])->save();
            });

        $this->info("Queued {$sent} follow-up reminders.");

        return self::SUCCESS;
    }
}
