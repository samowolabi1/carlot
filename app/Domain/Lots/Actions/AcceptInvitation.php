<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    public function run(LotInvitation $invitation, User $user): Lot
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has expired or was already used.']);
        }

        if (! $invitation->isFor($user)) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was sent to a different phone number or email. Sign in with that one to accept it.']);
        }

        return DB::transaction(function () use ($invitation, $user): Lot {
            $lot = Lot::findOrFail($invitation->lot_id);

            $lot->members()->syncWithoutDetaching([
                $user->getKey() => [
                    'role' => $invitation->role->value,
                    'invited_by' => $invitation->invited_by,
                    'accepted_at' => now(),
                ],
            ]);

            $invitation->update(['accepted_at' => now()]);

            if ($user->role === UserRole::Customer) {
                $user->update(['role' => UserRole::Staff]);
            }

            return $lot;
        });
    }
}
