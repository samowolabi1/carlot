<?php

namespace App\Domain\Admin;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Log in as" a dealer for support (TDD M17). The admin's id is kept in the session so they
 * can switch back; both ends are written to the audit log, and everything done in between is
 * logged with the admin as `impersonator_id`.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $admin, User $target): void
    {
        if (! $admin->adminCan(AdminArea::Support)) {
            throw ValidationException::withMessages(['user' => 'Only Owners and Support can use "Log in as".']);
        }
        if (! $admin->isAdmin() || $target->isAdmin() || session()->has(self::SESSION_KEY)) {
            throw ValidationException::withMessages(['user' => 'You can only log in as a non-admin user, one at a time.']);
        }
        if ($target->trashed()) {
            throw ValidationException::withMessages(['user' => 'That account is closed.']);
        }

        AuditLog::record('admin.impersonation_started', $target, ['as' => $target->name], $admin);

        Auth::login($target);
        session()->regenerate();
        self::forgetPasswordHash();
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
        self::forgetPasswordHash();

        return $admin;
    }

    public static function active(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /** The admin behind the current "Log in as", if any. */
    public static function admin(): ?User
    {
        $id = session()->get(self::SESSION_KEY);

        return $id ? User::find($id) : null;
    }

    /**
     * The admin panel's AuthenticateSession keeps the signed-in user's password hash in the session and
     * logs out on a mismatch. Switching user leaves the other person's hash there, which logged the admin
     * out on "Back to admin"; forgetting it lets the panel store the right one on the next request.
     */
    private static function forgetPasswordHash(): void
    {
        session()->forget('password_hash_'.Auth::getDefaultDriver());
    }
}
