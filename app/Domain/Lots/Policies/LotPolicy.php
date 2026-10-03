<?php

namespace App\Domain\Lots\Policies;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;

class LotPolicy
{
    /** Open the seller dashboard for this seller. */
    public function view(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot);
    }

    /** Profile, branding, location, hours and booking rules. */
    public function update(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner, LotRole::Manager);
    }

    /** Invite, change and remove staff; submit the seller for approval. */
    public function manageStaff(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner);
    }

    /** Choose and pay for a plan, cancel it, use a coupon. */
    public function manageBilling(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner);
    }

    /** Buy spotlights and featured-lot slots. */
    public function buySpotlight(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner, LotRole::Manager);
    }

    /** Car costs and profit (TDD M19): owners and managers on a plan with them; never sales. */
    public function viewCosts(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner, LotRole::Manager) && $lot->planAllows('costs');
    }

    /** Sales Manager reports; the staff report and Excel export also need viewCosts' plan. */
    public function viewReports(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner, LotRole::Manager);
    }

    public function submit(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner);
    }

    /** The bank accounts customers pay into: only the owner changes them (everyone can share them). */
    public function manageBankAccounts(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot, LotRole::Owner);
    }
}
