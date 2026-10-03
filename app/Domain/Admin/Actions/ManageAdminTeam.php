<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Notifications\AdminInvitation;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The admin team, run by owners: invite someone (a new account for their work email), change their role, resend the
 * invite, or remove them (signed out everywhere at once). There is always at least one owner, nobody changes or
 * removes themselves, and every step is audit-logged.
 */
class ManageAdminTeam
{
    public function invite(User $owner, string $name, string $email, AdminRole $role): User
    {
        $this->authorise($owner);
        $email = Str::lower(trim($email));

        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'That email already has a CarYard account. Use a separate work email for admin access.']);
        }

        $admin = DB::transaction(function () use ($owner, $name, $email, $role) {
            $admin = new User(['name' => trim($name), 'email' => $email]);
            $admin->forceFill(['role' => UserRole::Admin, 'admin_role' => $role, 'invited_by' => $owner->id, 'invited_at' => now()])->save();
            AuditLog::record('admin.team_invited', $admin, ['email' => $email, 'role' => $role->value], $owner);

            return $admin;
        });

        $admin->notify(new AdminInvitation($owner, $role));

        return $admin;
    }

    public function resend(User $owner, User $admin): void
    {
        $this->authorise($owner);
        if (! $admin->isAdmin() || $admin->password !== null) {
            throw ValidationException::withMessages(['admin' => 'They have already joined.']);
        }
        $admin->forceFill(['invited_at' => now()])->save();
        $admin->notify(new AdminInvitation($owner, $admin->adminRole() ?? AdminRole::Viewer));
        AuditLog::record('admin.team_invite_resent', $admin, [], $owner);
    }

    public function changeRole(User $owner, User $admin, AdminRole $role): void
    {
        $this->authorise($owner);
        $this->notSelf($owner, $admin);
        $from = $admin->adminRole();
        if ($from === $role) {
            return;
        }
        if ($from === AdminRole::Owner) {
            $this->keepAnOwner($admin);
        }

        $admin->forceFill(['admin_role' => $role])->save();
        AuditLog::record('admin.team_role_changed', $admin, ['from' => $from?->value, 'to' => $role->value], $owner);
    }

    public function remove(User $owner, User $admin): void
    {
        $this->authorise($owner);
        $this->notSelf($owner, $admin);
        if (! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => 'They are not on the admin team.']);
        }
        if ($admin->adminRole() === AdminRole::Owner) {
            $this->keepAnOwner($admin);
        }

        DB::transaction(function () use ($owner, $admin) {
            $role = $admin->adminRole();
            // No admin access left: no password, no 2FA, no remembered sessions or app tokens.
            $admin->forceFill([
                'role' => UserRole::Customer,
                'admin_role' => null,
                'password' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => Str::random(60),
            ])->save();
            $admin->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $admin->id)->delete();
            }
            AuditLog::record('admin.team_removed', $admin, ['role' => $role?->value], $owner);
        });
    }

    private function authorise(User $owner): void
    {
        if (! $owner->adminCan(AdminArea::Team)) {
            throw ValidationException::withMessages(['admin' => 'Only owners can manage the admin team.']);
        }
    }

    private function notSelf(User $owner, User $admin): void
    {
        if ($owner->is($admin)) {
            throw ValidationException::withMessages(['admin' => 'Ask another owner to change your own access.']);
        }
    }

    private function keepAnOwner(User $admin): void
    {
        $others = User::query()->where('role', UserRole::Admin)->where('admin_role', AdminRole::Owner->value)->whereKeyNot($admin->id)->whereNotNull('password')->exists();
        if (! $others) {
            throw ValidationException::withMessages(['admin' => 'CarYard always needs at least one owner who has joined. Make someone else an owner first.']);
        }
    }
}
