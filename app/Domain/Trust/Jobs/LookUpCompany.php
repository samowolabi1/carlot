<?php

namespace App\Domain\Trust\Jobs;

use App\Domain\Trust\Actions\CheckCompanyRegistry;
use App\Domain\Trust\Models\LotVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Looks up a submitted CAC number in the background, trying again if the registry is down. */
class LookUpCompany implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Something about a record that has since been deleted is dropped, not retried into failed_jobs. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public function __construct(public readonly int $verificationId) {}

    public function handle(CheckCompanyRegistry $check): void
    {
        $verification = LotVerification::withoutGlobalScopes()->with('lot')->find($this->verificationId);

        if ($verification !== null) {
            $check->run($verification);
        }
    }
}
