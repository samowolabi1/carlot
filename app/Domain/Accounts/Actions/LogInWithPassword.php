<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Email (or WhatsApp number) and password, for people who added a password on their account page.
 * Never creates accounts: new people sign up with a code or Google. One message for every
 * failure, so the form doesn't reveal who has an account.
 */
class LogInWithPassword
{
    public const FAILED = 'That email or number and password don\'t match. Check them, or sign in with a code instead.';

    /** A hash to check against when there is no account, so both cases take about as long. */
    private const DUMMY = '$2y$12$YcTK92SyFzDqNAX.TyUiEev0gfMl3o7tD9LMWLQWyFnTWPfYQ.C3u';

    public function run(string $login, string $password): User
    {
        $user = $this->find($login);

        $hash = $user !== null && $user->password !== null ? $user->password : self::DUMMY;
        $matches = Hash::check($password, $hash); // checked even with no account, so both take about as long

        if ($user === null || $hash === self::DUMMY || ! $matches || ! $user->reopenForSignIn()) {
            throw ValidationException::withMessages(['login' => self::FAILED]);
        }
        $user->save();

        return $user;
    }

    private function find(string $login): ?User
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return User::withTrashed()->where('email', Str::lower($login))->first();
        }

        try {
            return User::withTrashed()->where('phone', PhoneNumber::normalize($login))->first();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
