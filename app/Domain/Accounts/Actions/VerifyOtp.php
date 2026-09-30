<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\OtpPurpose;
use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Exceptions\OtpException;
use App\Domain\Accounts\Models\OtpCode;
use App\Domain\Accounts\Models\User;
use App\Domain\Legal\Actions\AcceptTerms;
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
        $this->check('phone', $phone, $code, $purpose);

        return $this->account('phone', $phone);
    }

    /** The same for a code sent by email: the account is found (or made) by its email address. */
    public function forEmail(string $email, string $code, OtpPurpose $purpose = OtpPurpose::Login): User
    {
        $this->check('email', $email, $code, $purpose);

        return $this->account('email', $email);
    }

    /** @param  'phone'|'email'  $field */
    private function check(string $field, string $value, string $code, OtpPurpose $purpose): void
    {
        $matched = DB::transaction(function () use ($field, $value, $code, $purpose): bool {
            $otp = OtpCode::query()
                ->where($field, $value)
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
    }

    /** @param  'phone'|'email'  $field */
    private function account(string $field, string $value): User
    {
        $user = User::withTrashed()->firstOrNew([$field => $value]);

        if (! $user->reopenForSignIn()) {
            throw OtpException::expired();
        }

        $new = ! $user->exists;
        if ($new) {
            $user->role = UserRole::Customer;
        }

        if ($field === 'phone') {
            $user->phone_verified_at ??= now();
        } else {
            $user->email_verified_at ??= now();
        }
        $user->save();

        // A new account: the sign-in page (and the app's) says that continuing accepts the Terms and Privacy Policy.
        if ($new) {
            app(AcceptTerms::class)->run($user);
        }

        return $user;
    }
}
