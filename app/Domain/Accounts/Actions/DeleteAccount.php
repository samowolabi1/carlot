<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use Illuminate\Validation\ValidationException;

/**
 * "Delete my account" (TDD Privacy, NDPA): the account closes now and personal data is
 * anonymised after 30 days (AnonymiseAccount). Signing in before then cancels it.
 */
class DeleteAccount
{
    public const GRACE_DAYS = 30;

    public function run(User $user): void
    {
        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['account' => 'Admin accounts are closed by another admin.']);
        }

        $owned = $user->lots()->wherePivot('role', LotRole::Owner->value)->pluck('name');
        if ($owned->isNotEmpty()) {
            throw ValidationException::withMessages(['account' => 'You own '.$owned->implode(', ').'. Hand the lot to another owner or ask us to close it before deleting your account.']);
        }

        $user->forceFill(['deletion_requested_at' => now()])->save();
        $user->tokens()->delete();
        $user->pushSubscriptions()->delete();
        $user->delete();
    }
}
