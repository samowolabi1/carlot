<?php

namespace App\Console\Commands;

use App\Domain\Accounts\Models\OtpCode;
use Illuminate\Console\Command;

class PruneOtpCodes extends Command
{
    protected $signature = 'otp:prune';

    protected $description = 'Delete one-time codes older than a day';

    public function handle(): int
    {
        $deleted = OtpCode::where('created_at', '<', now()->subDay())->delete();
        $this->info("Deleted {$deleted} old codes.");

        return self::SUCCESS;
    }
}
