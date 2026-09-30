<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Audit\AuditLog;
use App\Domain\Trust\Models\LotVerification;
use App\Domain\Trust\Registry\CompanyRegistry;
use App\Domain\Trust\Registry\NameMatch;
use App\Domain\Trust\Registry\RegistryUnavailable;

/**
 * Asks the CAC registry about a submitted number and keeps its answer on the verification, with how well
 * the registered name matches the lot's name. It never approves or rejects: that stays an admin's call.
 */
class CheckCompanyRegistry
{
    public function __construct(private readonly CompanyRegistry $registry) {}

    public function run(LotVerification $verification): LotVerification
    {
        if (! $this->registry->enabled()) {
            return $verification;
        }

        try {
            $record = $this->registry->lookup($verification->cac_number);
        } catch (RegistryUnavailable $e) {
            report($e);
            $verification->forceFill(['registry_result' => 'unavailable', 'registry_checked_at' => now()])->save();

            throw $e; // the queued job tries again
        }

        $verification->forceFill($record === null ? [
            'registry_result' => 'not_found', 'registry_name' => null, 'registry_status' => null,
            'registry_registered_on' => null, 'registry_address' => null, 'registry_name_match' => null,
            'registry_checked_at' => now(),
        ] : [
            'registry_result' => 'found',
            'registry_name' => mb_substr($record->name, 0, 200),
            'registry_status' => $record->status !== null ? mb_substr($record->status, 0, 40) : null,
            'registry_registered_on' => $record->registeredOn,
            'registry_address' => $record->address,
            'registry_name_match' => NameMatch::score($verification->lot->name, $record->name),
            'registry_checked_at' => now(),
        ])->save();

        AuditLog::record('lot.verification_registry_checked', $verification, [
            'result' => $verification->registry_result, 'name' => $verification->registry_name, 'match' => $verification->registry_name_match,
        ], null, $verification->lot_id);

        return $verification;
    }
}
