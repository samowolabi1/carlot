<?php

namespace App\Console\Commands;

use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Sharing\Models\ShareLink;
use Illuminate\Console\Command;

/** Daily: remove share links nobody ever opened after 90 days (TDD: housekeeping). */
class PruneShareLinks extends Command
{
    protected $signature = 'share-links:prune';

    protected $description = 'Delete unused share links older than 90 days';

    public function handle(): int
    {
        $deleted = ShareLink::query()
            ->where('clicks', 0)
            ->where('platform', '!=', SharePlatform::Qr) // printed QR codes must keep working
            ->where('created_at', '<', now()->subDays(90))
            ->delete();

        $this->info("Deleted {$deleted} unused share links.");

        return self::SUCCESS;
    }
}
