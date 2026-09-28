<?php

namespace App\Domain\Lots\Policies;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;

class LotPolicy
{
    /** Open the dealer dashboard for this lot. */
    public function view(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot);
    }

    /** Profile, branding, location, hours and booking rules. */
    public function update(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner, LotRole::Manager);
    }

    /** Invite, change and remove staff; submit the lot for approval. */
    public function manageStaff(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner);
    }

    public function submit(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner);
    }
}
