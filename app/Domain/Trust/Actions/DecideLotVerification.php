<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Models\LotVerification;
use App\Domain\Trust\Notifications\VerificationDecided;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** An admin approves (the seller gets verified_at and the badge) or rejects with a note. */
class DecideLotVerification
{
    public function approve(LotVerification $verification, User $admin, ?string $notes = null): LotVerification
    {
        return $this->decide($verification, $admin, VerificationStatus::Approved, $notes);
    }

    public function reject(LotVerification $verification, User $admin, string $notes): LotVerification
    {
        if (trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => 'Tell the seller what to fix.']);
        }

        return $this->decide($verification, $admin, VerificationStatus::Rejected, $notes);
    }

    /** Takes the badge away, e.g. after a report shows the business has closed. */
    public function revoke(Lot $lot, User $admin, string $reason): void
    {
        $lot->forceFill(['verified_at' => null])->save();
        AuditLog::record('lot.verification_revoked', $lot, ['reason' => $reason], $admin, $lot->id);
    }

    private function decide(LotVerification $verification, User $admin, VerificationStatus $status, ?string $notes): LotVerification
    {
        if ($verification->status !== VerificationStatus::Submitted) {
            throw ValidationException::withMessages(['status' => 'This verification has already been decided.']);
        }

        DB::transaction(function () use ($verification, $admin, $status, $notes): void {
            $verification->update(['status' => $status, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'notes' => $notes ?: null]);

            $lot = Lot::whereKey($verification->lot_id)->firstOrFail();
            if ($status === VerificationStatus::Approved) {
                $lot->forceFill(['verified_at' => now()])->save();
            }

            AuditLog::record('lot.verification_'.$status->value, $verification, ['notes' => $notes], $admin, $lot->id);
            $lot->owner->notify(new VerificationDecided($verification));
        });

        return $verification;
    }
}
