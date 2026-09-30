<?php

namespace App\Console\Commands;

use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Daily (Privacy Policy, retention): 24 months after a car loan application closed, delete what the buyer shared (the
 * encrypted details, messages and documents), keeping only that it existed and its outcome (status and amounts).
 */
class PruneFinanceApplications extends Command
{
    public const MONTHS = 24;

    protected $signature = 'finance:prune';

    protected $description = 'Delete the details and documents of car loan applications closed more than 24 months ago';

    public function handle(): int
    {
        $closed = [FinanceStatus::Declined, FinanceStatus::Withdrawn, FinanceStatus::Failed, FinanceStatus::Disbursed];
        $count = 0;

        FinanceApplication::query()->whereIn('status', $closed)->where('updated_at', '<', now()->subMonths(self::MONTHS))
            ->chunkById(100, function ($applications) use (&$count) {
                foreach ($applications as $application) {
                    if ($application->applicant === []) {
                        continue;
                    }
                    $disk = Storage::disk(FinanceMessage::DISK);
                    FinanceMessage::where('finance_application_id', $application->id)->whereNotNull('attachment_path')->pluck('attachment_path')
                        ->each(fn (string $path) => $disk->delete($path));
                    FinanceMessage::where('finance_application_id', $application->id)->delete();
                    $application->forceFill(['applicant' => [], 'partner_message' => null, 'next_steps' => null])->saveQuietly();
                    $count++;
                }
            });

        $this->info("Cleared {$count} old car loan applications.");

        return self::SUCCESS;
    }
}
