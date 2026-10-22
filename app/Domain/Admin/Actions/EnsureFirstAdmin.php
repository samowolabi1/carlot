<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminRole;
use App\Domain\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * The first platform admin, from config('lotlink.first_admin') (ADMIN_EMAIL / ADMIN_PASSWORD / ADMIN_PHONE in .env).
 *
 * - Seeding (`run()`): creates the admin if missing; never changes an existing admin's password.
 * - The cron job after .env was saved (`run(fromEnvChange: true)`): also puts a corrected ADMIN_PASSWORD in place, but only
 *   while the admin still has the password that came from .env (remembered as its hash), so a password changed in the app
 *   is never overwritten.
 * - A deliberate reset: an empty file storage/app/reset-admin-password (made in cPanel's File Manager) sets the password
 *   from .env again and clears two-step sign-in so it can be set up afresh; the file is then removed.
 * Never a guessable admin on a real server: outside local/testing it needs an email and a 12+ character password.
 */
final class EnsureFirstAdmin
{
    /** @return array{status: 'created'|'updated'|'reset'|'kept'|'skipped', message: string} */
    public function run(bool $fromEnvChange = false): array
    {
        $local = app()->environment('local', 'testing');
        $email = Str::lower(trim((string) config('lotlink.first_admin.email'))) ?: ($local ? 'admin@lotlink.test' : '');
        $password = (string) config('lotlink.first_admin.password') ?: ($local ? 'password' : '');
        $resetFile = storage_path('app/reset-admin-password');
        $reset = is_file($resetFile);

        if ($email === '' || (! $local && mb_strlen($password) < 12)) {
            return ['status' => 'skipped', 'message' => self::why($email, $password)];
        }

        $admin = User::where('email', $email)->first();
        if (! $admin) {
            $phone = PhoneNumber::tryNormalize(config('lotlink.first_admin.phone')) ?? ($local ? '+2348000000000' : null);
            $phone = $phone && User::withTrashed()->where('phone', $phone)->exists() ? null : $phone;
            $admin = User::create([
                'email' => $email,
                'name' => 'CarYard Admin',
                'phone' => $phone,
                'password' => $password,
                'role' => UserRole::Admin,
                'phone_verified_at' => $phone ? now() : null,
            ]);
            $admin->forceFill(['email_verified_at' => now()]);
            $status = 'created';
        } elseif ($reset) {
            $admin->forceFill(['password' => $password, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
            $status = 'reset';
        } elseif ($fromEnvChange && $this->stillFromEnv($admin) && ! password_verify($password, $admin->password)) {
            $admin->forceFill(['password' => $password]);
            $status = 'updated';
        } else {
            $status = 'kept';
        }

        // The first admin owns the admin team (invites the rest from /admin → System → Admin team).
        $admin->forceFill(['role' => UserRole::Admin, 'admin_role' => $admin->admin_role ?? AdminRole::Owner])->save();
        if ($status !== 'kept') {
            @file_put_contents($this->rememberFile(), $admin->password, LOCK_EX);
        }
        if ($reset) {
            @unlink($resetFile);
        }

        return ['status' => $status, 'message' => match ($status) {
            'created' => "Admin account created: sign in at /admin with {$email} and ADMIN_PASSWORD.",
            'updated' => "Admin password for {$email} updated from ADMIN_PASSWORD in .env.",
            'reset' => "Admin password for {$email} reset from ADMIN_PASSWORD, and two-step sign-in cleared: sign in at /admin and set it up again.",
            'kept' => "Admin account {$email} already exists; its password is unchanged (change it in the app, or create storage/app/reset-admin-password to reset it from .env).",
        }];
    }

    /** True while the admin's password is still the one this class set from .env (it was never changed in the app). */
    private function stillFromEnv(User $admin): bool
    {
        $remembered = @file_get_contents($this->rememberFile());

        return is_string($remembered) && $remembered !== '' && hash_equals($remembered, (string) $admin->password);
    }

    private function rememberFile(): string
    {
        return storage_path('app/first-admin-password');
    }

    /** Why no admin was made, with the usual .env mistakes spelled out. */
    public static function why(string $email, string $password, ?string $envFile = null): string
    {
        if ($email === '') {
            return 'No admin account yet: set ADMIN_EMAIL in .env.';
        }
        $message = 'No admin account yet: ADMIN_PASSWORD in .env needs at least 12 characters (it has '.mb_strlen($password).').';
        $raw = self::rawEnvValue('ADMIN_PASSWORD', $envFile ?? base_path('.env'));
        if ($raw !== null && ! preg_match('/^["\']/', $raw) && preg_match('/[#\s]/', $raw)) {
            $message .= ' It contains # or a space: put the password inside double quotes, like ADMIN_PASSWORD="my#password 2026", or everything after # is ignored.';
        }

        return $message.' Save .env and the cron job picks it up within a minute.';
    }

    /** The value exactly as typed in .env (before any parsing), or null. */
    private static function rawEnvValue(string $key, string $envFile): ?string
    {
        $env = @file_get_contents($envFile);
        if (! is_string($env) || ! preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/m', $env, $m)) {
            return null;
        }

        return trim($m[1]);
    }
}
