<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Notifications\LenderAlert;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A lender's admin adds someone to the team by email (their account is made if they don't have one). */
class AddLenderMember
{
    public function run(Lender $lender, string $email, string $name, LenderRole $role, User $by): User
    {
        $email = Str::lower(trim($email));
        $user = User::query()->where('email', $email)->first();
        if ($user?->isAdmin()) {
            throw ValidationException::withMessages(['email' => 'That is a CarYard admin account.']);
        }
        if ($user !== null && $lender->roleOf($user) !== null) {
            throw ValidationException::withMessages(['email' => "{$user->name} is already on the team."]);
        }
        $user ??= User::query()->create(['name' => trim($name), 'email' => $email, 'role' => UserRole::Customer]);

        $lender->members()->attach($user->id, ['role' => $role->value]);
        AuditLog::record('lender.member_added', $lender, ['user' => $user->ulid, 'role' => $role->value], $by);

        $user->notify(new LenderAlert(
            "{$by->name} added you to {$lender->name} on CarYard. Sign in with this email address to work car loan applications.",
            route('lender.home'),
        ));

        return $user;
    }

    public function remove(Lender $lender, User $member, User $by): void
    {
        $role = $lender->roleOf($member) ?? throw ValidationException::withMessages(['member' => 'They are not on the team.']);
        if ($role === LenderRole::Admin && $lender->memberships()->where('role', LenderRole::Admin->value)->count() <= 1) {
            throw ValidationException::withMessages(['member' => 'Every lender needs at least one admin. Make someone else an admin first.']);
        }

        $lender->members()->detach($member->id);
        $lender->applications()->where('assigned_to', $member->id)->update(['assigned_to' => null]);
        AuditLog::record('lender.member_removed', $lender, ['user' => $member->ulid], $by);
    }
}
