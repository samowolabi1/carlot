<?php

namespace App\Domain\LotManager\Policies;

use App\Domain\Accounts\Models\User;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Support\CurrentLot;

/**
 * Any lot member records walk-ins, orders and payments at the gate. Voiding a payment
 * and cancelling an order are for owners and managers; handing over a car that isn't
 * fully paid is for the owner (checked in ChangeOrderStatus).
 */
class SalesOrderPolicy
{
    public function view(User $user, SalesOrder $order): bool
    {
        return $user->hasLotRole($this->lot($order));
    }

    public function recordPayment(User $user, SalesOrder $order): bool
    {
        return $user->hasLotRole($this->lot($order));
    }

    public function changeStatus(User $user, SalesOrder $order): bool
    {
        return $user->hasLotRole($this->lot($order));
    }

    public function voidPayment(User $user, SalesOrder $order): bool
    {
        return $user->hasLotRole($this->lot($order), LotRole::Owner, LotRole::Manager);
    }

    public function cancel(User $user, SalesOrder $order): bool
    {
        return $user->hasLotRole($this->lot($order), LotRole::Owner, LotRole::Manager);
    }

    private function lot(SalesOrder $order): Lot
    {
        $current = app(CurrentLot::class)->get();

        return $current !== null && $current->id === $order->lot_id ? $current : Lot::findOrFail($order->lot_id);
    }
}
