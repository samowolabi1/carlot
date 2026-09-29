<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Notifications\SignInChanged;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An optional password on top of one-time codes. Adding the first one only needs the signed-in
 * session (the person proved who they are with a code or Google); changing or removing one needs
 * the current password. Forgot it? Sign in with a code and set a new one: codes always work.
 */
class SetPassword
{
    public function run(User $user, string $password, ?string $current = null): void
    {
        $first = $user->password === null;
        if (! $first) {
            $this->checkCurrent($user, $current);
        }

        // A new remember token signs out "remember me" sessions on other devices.
        $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'remember_token' => Str::random(60)])->save();

        AuditLog::record($first ? 'account.password_added' : 'account.password_changed', $user, [], $user);
        $user->notify(new SignInChanged($first ? 'a password was added to your account' : 'your password was changed'));
    }

    /** From an emailed reset link (the person proved they own the email): no current password needed. */
    public function reset(User $user, string $password): void
    {
        $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'remember_token' => Str::random(60)])->save();
        // Someone else may have had the old password: sign the mobile app out everywhere too.
        $user->tokens()->delete();

        AuditLog::record('account.password_reset', $user, [], $user);
        $user->notify(new SignInChanged('your password was reset from the emailed link'));
    }

    /** Back to codes (and Google) only. Admins keep theirs: the admin panel signs in with a password. */
    public function remove(User $user, ?string $current): void
    {
        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['current_password' => 'Admin accounts need a password.']);
        }
        if ($user->password === null) {
            return;
        }
        $this->checkCurrent($user, $current);

        $user->forceFill(['password' => null, 'password_changed_at' => now(), 'remember_token' => Str::random(60)])->save();

        AuditLog::record('account.password_removed', $user, [], $user);
        $user->notify(new SignInChanged('your password was removed; you sign in with a one-time code'));
    }

    private function checkCurrent(User $user, ?string $current): void
    {
        if ($current === null || $current === '' || ! Hash::check($current, (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That isn\'t your current password.']);
        }
    }
}
