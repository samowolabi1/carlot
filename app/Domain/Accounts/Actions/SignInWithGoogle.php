<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Notifications\SignInChanged;
use App\Domain\Audit\AuditLog;
use App\Domain\Legal\Actions\AcceptTerms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as GoogleUser;

/**
 * "Continue with Google": finds the account linked to the Google account, else the one with the
 * same (Google-verified) email, else creates a customer account. Signed in already, it links
 * Google to that account instead (Account → Sign-in and security), which is how people who signed
 * up with WhatsApp add Google.
 */
class SignInWithGoogle
{
    public const TAKEN = 'That Google account is already linked to another CarYard account.';

    public function run(GoogleUser $google, ?User $current = null): User
    {
        $id = (string) $google->getId();
        $email = $this->verifiedEmail($google);

        return DB::transaction(function () use ($google, $id, $email, $current): User {
            $linked = User::withTrashed()->where('google_id', $id)->lockForUpdate()->first();

            if ($current !== null) {
                if ($linked !== null && ! $linked->is($current)) {
                    throw ValidationException::withMessages(['google' => self::TAKEN]);
                }

                return $this->link($current, $id, $email);
            }

            $user = $linked;
            if ($user === null && $email !== null) {
                $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
            }

            if ($user === null) {
                $user = new User(['name' => Str::limit(trim((string) $google->getName()), 80, '') ?: null, 'email' => $email]);
                $user->role = UserRole::Customer;
                $user->forceFill(['google_id' => $id, 'email_verified_at' => $email ? now() : null])->save();
                AuditLog::record('account.created_with_google', $user, [], $user);
                // The sign-in page says that continuing accepts the Terms and Privacy Policy.
                app(AcceptTerms::class)->run($user);

                return $user;
            }

            if (! $user->reopenForSignIn()) {
                throw ValidationException::withMessages(['google' => 'That account was closed. Contact CarYard support.']);
            }

            return $user->google_id === $id ? $this->fillIn($user, $google, $email) : $this->link($user, $id, $email, $google);
        });
    }

    public function disconnect(User $user): void
    {
        if ($user->google_id === null) {
            return;
        }

        $user->forceFill(['google_id' => null])->save();
        AuditLog::record('account.google_disconnected', $user, [], $user);
        $user->notify(new SignInChanged('your Google account was disconnected'));
    }

    private function link(User $user, string $id, ?string $email, ?GoogleUser $google = null): User
    {
        if ($user->google_id === $id) {
            return $user;
        }

        $user->forceFill(['google_id' => $id]);
        // People who signed up with WhatsApp get Google's (verified) email too, if nobody else has it.
        if ($user->email === null && $email !== null && ! User::withTrashed()->where('email', $email)->exists()) {
            $user->forceFill(['email' => $email, 'email_verified_at' => now()]);
        }
        $google ? $this->fillIn($user, $google, $email) : $user->save();

        AuditLog::record('account.google_connected', $user, [], $user);
        $user->notify(new SignInChanged('your Google account was connected; you can use "Continue with Google" to sign in'));

        return $user;
    }

    private function fillIn(User $user, GoogleUser $google, ?string $email): User
    {
        if (blank($user->name) && filled($google->getName())) {
            $user->name = Str::limit(trim((string) $google->getName()), 80, '');
        }
        if ($email !== null && $user->email === $email) {
            $user->email_verified_at ??= now();
        }
        $user->save();

        return $user;
    }

    /** Google's email, lower-cased, only when Google says it's verified (every Gmail address is). */
    private function verifiedEmail(GoogleUser $google): ?string
    {
        $raw = method_exists($google, 'getRaw') ? (array) $google->getRaw() : [];
        $verified = filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $email = Str::lower(trim((string) $google->getEmail()));

        return $verified && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
