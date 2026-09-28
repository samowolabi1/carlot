<?php

namespace App\Console\Commands;

use App\Domain\Location\Actions\EndLocationSession;
use App\Domain\Location\Models\LocationSession;
use Illuminate\Console\Command;

/** Every minute (TDD: location:end-expired): sessions past their time end and forget the point. */
class EndExpiredLocationSessions extends Command
{
    protected $signature = 'location:end-expired';

    protected $description = 'End live location sessions that have run out and clear their last point';

    public function handle(EndLocationSession $end): int
    {
        $count = 0;
        LocationSession::withoutGlobalScopes()->whereNull('ended_at')->where('expires_at', '<=', now())
            ->each(function (LocationSession $s) use ($end, &$count): void {
                $end->run($s, 'expired');
                $count++;
            });

        $this->info("Ended {$count} location sessions.");

        return self::SUCCESS;
    }
}
