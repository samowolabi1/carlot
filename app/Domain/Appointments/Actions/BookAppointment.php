<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\BookingNotice;
use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookAppointment
{
    public function __construct(
        private readonly SlotGenerator $slots,
        private readonly NotifyLot $notifyLot,
    ) {}

    /**
     * Books a slot. The lot row is locked while the capacity check and insert run, so two
     * buyers can't take the last place in a slot at the same moment (TDD M7).
     *
     * @param  array{type: string, starts_at: string, vehicle?: ?string, notes?: ?string, whatsapp_reminders?: bool}  $data
     */
    public function run(Lot $lot, User $customer, array $data): Appointment
    {
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $vehicle = null;

        if (filled($data['vehicle'] ?? null)) {
            $vehicle = Vehicle::query()->marketplace()->where('vehicles.ulid', $data['vehicle'])->where('vehicles.lot_id', $lot->id)->first()
                ?? throw ValidationException::withMessages(['vehicle' => 'That car is no longer available.']);
        }

        $appointment = DB::transaction(function () use ($lot, $customer, $data, $start, $vehicle): Appointment {
            Lot::whereKey($lot->id)->lockForUpdate()->first();

            $slot = $this->slots->find($lot, $start)
                ?? throw ValidationException::withMessages(['starts_at' => 'Sorry, that time was just taken. Please pick another.']);

            $duplicate = Appointment::withoutGlobalScopes()
                ->where('lot_id', $lot->id)
                ->where('customer_id', $customer->id)
                ->active()
                ->where('starts_at', $slot['starts_at'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['starts_at' => 'You already have a booking at this time.']);
            }

            $auto = $lot->booking_auto_confirm;

            return Appointment::withoutGlobalScopes()->create([
                'lot_id' => $lot->id,
                'vehicle_id' => $vehicle?->id,
                'customer_id' => $customer->id,
                'type' => AppointmentType::from($data['type']),
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['starts_at']->addMinutes($slot['minutes']),
                'status' => $auto ? AppointmentStatus::Confirmed : AppointmentStatus::Pending,
                'confirmed_at' => $auto ? now() : null,
                'notes' => $data['notes'] ?? null,
                'whatsapp_reminders' => $data['whatsapp_reminders'] ?? true,
            ]);
        });

        // Lead capture from bookings arrives with the lead manager (S8).
        $customer->notify(new BookingNotice($appointment, BookingNotice::RECEIVED));
        $this->notifyLot->run($appointment, $appointment->status === AppointmentStatus::Confirmed ? 'new' : 'needs_confirmation');

        return $appointment;
    }
}
