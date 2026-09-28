<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use Illuminate\Support\Facades\DB;

class DowngradeToFree
{
    public function __construct(private readonly VehicleStateMachine $stateMachine) {}

    /**
     * Moves a lot to the Free plan. Cars above the Free listing limit are hidden, not
     * deleted: the newest listings stay live, reserved cars always stay (TDD M16: Dunning).
     *
     * @return int how many cars were hidden
     */
    public function run(Lot $lot, string $reason): int
    {
        $free = Plan::free();

        $hidden = DB::transaction(function () use ($lot, $free, $reason): int {
            Subscription::updateOrCreate(['lot_id' => $lot->id], [
                'plan_id' => $free->id,
                'status' => SubscriptionStatus::Cancelled,
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'cancel_at_period_end' => null,
                'current_period_end' => null,
            ]);
            $lot->forceFill(['plan_id' => $free->id])->save();

            $hidden = 0;
            if ($free->listing_limit !== null) {
                $reserved = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', VehicleStatus::Reserved)->count();
                $keep = max(0, $free->listing_limit - $reserved);

                $extra = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', VehicleStatus::Available)
                    ->orderByDesc('spotlight_until')->orderByDesc('listed_at')->orderByDesc('id')
                    ->skip($keep)->take(PHP_INT_MAX)->get();

                foreach ($extra as $vehicle) {
                    $this->stateMachine->transition($vehicle, VehicleStatus::Hidden);
                    $hidden++;
                }
            }

            AuditLog::record('billing.downgraded', $lot, ['reason' => $reason, 'hidden' => $hidden], lotId: $lot->id);

            return $hidden;
        });

        $lot->owner?->notify(new BillingNotice($lot, $hidden > 0
            ? "You're on the Free plan now ({$reason}). {$hidden} cars were hidden to fit its {$free->listing_limit}-car limit. Choose a plan to show them again."
            : "You're on the Free plan now ({$reason}). Choose a plan any time for more cars and staff."));

        return $hidden;
    }
}
