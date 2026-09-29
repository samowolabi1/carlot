<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\OtpPurpose;
use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Exceptions\OtpException;
use App\Domain\Accounts\Models\OtpCode;
use App\Domain\Accounts\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VerifyOtp
{
    /**
     * Checks the code and returns the user for that phone, creating a customer
     * account on first sign-in.
     *
     * @param  string  $phone  E.164 number
     */
    public function run(string $phone, string $code, OtpPurpose $purpose = OtpPurpose::Login): User
    {
        $matched = DB::transaction(function () use ($phone, $code, $purpose): bool {
            $otp = OtpCode::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null || ! $otp->isUsable()) {
                throw OtpException::expired();
            }

            $otp->increment('attempts');

            if (! Hash::check($code, $otp->code_hash)) {
                return false;
            }

            $otp->update(['consumed_at' => now()]);

            return true;
        });

        // Thrown outside the transaction so the attempt count is kept.
        if (! $matched) {
            throw OtpException::invalid();
        }

        $user = User::withTrashed()->firstOrNew(['phone' => $phone]);

        // Signing in again within 30 days of asking to delete the account cancels the deletion.
        if ($user->trashed() && $user->deletion_requested_at !== null && $user->anonymised_at === null) {
            $user->restore();
            $user->deletion_requested_at = null;
        } elseif ($user->trashed()) {
            throw OtpException::expired();
        }

        if (! $user->exists) {
            $user->role = UserRole::Customer;
        }

        $user->phone_verified_at ??= now();
        $user->save();

        return $user;
    }
}
