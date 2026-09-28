<?php

namespace App\Domain\Appointments\Policies;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Support\CurrentLot;

class AppointmentPolicy
{
    /** The buyer, with their own booking. */
    public function view(User $user, Appointment $appointment): bool
    {
        return $user->id === $appointment->customer_id || $user->hasLotRole($this->lot($appointment));
    }

    public function cancelAsCustomer(User $user, Appointment $appointment): bool
    {
        return $user->id === $appointment->customer_id;
    }

    /**
     * Lot staff working a booking. Sales reps handle their own and unassigned bookings
     * (product spec: "manage own appointments"); owners and managers handle all.
     */
    public function manage(User $user, Appointment $appointment): bool
    {
        $role = $user->roleIn($this->lot($appointment));

        return $role !== null && ($role !== LotRole::Sales || $appointment->staff_id === null || $appointment->staff_id === $user->id);
    }

    public function assign(User $user, Appointment $appointment): bool
    {
        return $user->hasLotRole($this->lot($appointment), LotRole::Owner, LotRole::Manager);
    }

    private function lot(Appointment $appointment): Lot
    {
        $current = app(CurrentLot::class)->get();

        return $current !== null && $current->id === $appointment->lot_id ? $current : Lot::findOrFail($appointment->lot_id);
    }
}
