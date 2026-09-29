<?php

namespace App\Domain\Admin;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Log in as" a dealer for support (TDD M17). The admin's id is kept in the session so they
 * can switch back; both ends are written to the audit log.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $admin, User $target): void
    {
        if (! $admin->isAdmin() || $target->isAdmin() || session()->has(self::SESSION_KEY)) {
            throw ValidationException::withMessages(['user' => 'You can only log in as a non-admin user, one at a time.']);
        }

        AuditLog::record('admin.impersonation_started', $target, ['as' => $target->name], $admin);

        Auth::login($target);
        session()->regenerate();
        session()->put(self::SESSION_KEY, $admin->id);
    }

    /** @return User|null the admin, logged back in */
    public function stop(): ?User
    {
        $adminId = session()->pull(self::SESSION_KEY);
        $admin = $adminId ? User::find($adminId) : null;

        if ($admin === null || ! $admin->isAdmin()) {
            return null;
        }

        /** @var User|null $was */
        $was = Auth::user();
        AuditLog::record('admin.impersonation_ended', $was, [], $admin);

        Auth::login($admin);
        session()->regenerate();

        return $admin;
    }

    public static function active(): bool
    {
        return session()->has(self::SESSION_KEY);
    }
}
