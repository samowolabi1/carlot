<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Actions\StartTrial;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotReferral;
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
    public function run(User $owner, array $data, ?string $referralCode = null): Lot
    {
        return DB::transaction(function () use ($owner, $data, $referralCode): Lot {
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

            $lot->referralCode();

            // Signed up with another lot's code (not one of the owner's own lots): TDD M19 referrals.
            $referrer = filled($referralCode) ? Lot::where('referral_code', strtoupper((string) $referralCode))->where('owner_id', '!=', $owner->getKey())->first() : null;
            if ($referrer !== null) {
                LotReferral::create(['referrer_lot_id' => $referrer->id, 'referred_lot_id' => $lot->id, 'status' => 'signed_up']);
            }

            app(SaveLotHours::class)->run($lot, SaveLotHours::defaults());
            app(StartTrial::class)->run($lot);

            return $lot;
        });
    }
}
