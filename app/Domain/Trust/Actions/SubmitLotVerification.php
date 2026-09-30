<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Jobs\LookUpCompany;
use App\Domain\Trust\Models\LotVerification;
use App\Domain\Trust\Registry\CompanyRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The owner sends the CAC certificate, CAC number and a photo of the lot frontage (TDD M14).
 * Files go to the private disk; an admin approves or rejects in the review queue.
 */
class SubmitLotVerification
{
    public function run(Lot $lot, User $owner, string $cacNumber, UploadedFile $certificate, UploadedFile $frontage): LotVerification
    {
        if ($lot->isVerified()) {
            throw ValidationException::withMessages(['cac_number' => 'Your lot is already verified.']);
        }

        $pending = LotVerification::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', VerificationStatus::Submitted)->first();

        $disk = Storage::disk(LotVerification::DISK);
        $folder = "verifications/{$lot->ulid}";
        $certificatePath = $disk->putFileAs($folder, $certificate, 'cac-'.Str::ulid().'.'.$certificate->extension());
        $photoPath = $disk->putFileAs($folder, $frontage, 'frontage-'.Str::ulid().'.'.$frontage->extension());

        $data = [
            'cac_number' => self::normalise($cacNumber),
            'documents' => [$certificatePath],
            'address_photo_path' => $photoPath,
            'submitted_by' => $owner->id,
        ];

        // A second submission while the first is still waiting replaces its files.
        if ($pending !== null) {
            $disk->delete([...$pending->documents, $pending->address_photo_path]);
            $pending->update($data);
            $verification = $pending;
        } else {
            $verification = LotVerification::create([...$data, 'lot_id' => $lot->id, 'status' => VerificationStatus::Submitted]);
        }

        AuditLog::record('lot.verification_submitted', $verification, ['cac_number' => $verification->cac_number], $owner, $lot->id);

        // Ask the CAC registry in the background (if a lookup is set up); the admin sees its answer when reviewing.
        if (app(CompanyRegistry::class)->enabled()) {
            $verification->forceFill(['registry_result' => null, 'registry_checked_at' => null])->save();
            LookUpCompany::dispatch($verification->id)->afterCommit();
        }

        return $verification;
    }

    /** "rc 123 4567" → "1234567"; business names keep their "BN" prefix. */
    public static function normalise(string $cac): string
    {
        $cac = strtoupper((string) preg_replace('/[\s\-]/', '', $cac));

        return str_starts_with($cac, 'RC') ? substr($cac, 2) : $cac;
    }
}
