<?php

namespace App\Console\Commands;

use App\Domain\Engagement\Actions\RunEngagementRules;
use Illuminate\Console\Command;

/** Hourly: automated emails to lot owners at the send hour in each lot's time zone, and any scheduled broadcasts that are due. */
class RunEngagementEmails extends Command
{
    protected $signature = 'engagement:run {--now : Ignore the send hour} {--rule= : Only this rule}';

    protected $description = 'Send automated engagement emails to lot owners';

    public function handle(RunEngagementRules $rules): int
    {
        $sent = $rules->run((bool) $this->option('now'), $this->option('rule') ?: null);
        $this->info('Sent: '.collect($sent)->map(fn (int $n, string $rule) => "{$rule} {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
