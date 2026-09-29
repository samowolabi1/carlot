<?php

namespace App\Console\Commands;

use App\Domain\Engagement\Actions\SendBroadcast;
use Illuminate\Console\Command;

/** Every minute: scheduled broadcasts whose time has come. */
class SendDueBroadcasts extends Command
{
    protected $signature = 'engagement:send-broadcasts';

    protected $description = 'Send scheduled broadcasts that are due';

    public function handle(SendBroadcast $send): int
    {
        $this->info("Started {$send->due()} broadcasts.");

        return self::SUCCESS;
    }
}
