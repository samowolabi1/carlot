<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use Illuminate\Support\Facades\DB;

class CreateLot
{
    /**
     * Onboarding step 1: creates a pending lot with the user as owner, plus
     * default opening hours they can change in step 4.
     *
     * @param  array{name: string, phone: string, whatsapp?: ?string, email?: ?string, tagline?: ?string, about?: ?string}  $data
     */
    public function run(User $owner, array $data): Lot
    {
        return DB::transaction(function () use ($owner, $data): Lot {
            $lot = Lot::create([
                ...$data,
                'owner_id' => $owner->getKey(),
                'slug' => Lot::uniqueSlug($data['name']),
                'status' => LotStatus::Pending,
                'timezone' => config('lotlink.timezone'),
                'plan_id' => Plan::default()?->getKey(),
            ]);

            $lot->members()->attach($owner->getKey(), [
                'role' => LotRole::Owner->value,
                'accepted_at' => now(),
            ]);

            if ($owner->role === UserRole::Customer) {
                $owner->update(['role' => UserRole::Staff]);
            }

            app(SaveLotHours::class)->run($lot, SaveLotHours::defaults());

            return $lot;
        });
    }
}
