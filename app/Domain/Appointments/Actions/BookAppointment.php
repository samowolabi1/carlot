<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Analytics\Support\Tracker;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\BookingNotice;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Actions\SendMessage;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookAppointment
{
    public function __construct(
        private readonly SlotGenerator $slots,
        private readonly NotifyLot $notifyLot,
        private readonly CaptureLead $captureLead,
        private readonly SendMessage $sendMessage,
    ) {}

    /**
     * Books a slot. The seller row is locked while the capacity check and insert run, so two
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
                ->holdingSlot()
                ->where('starts_at', $slot['starts_at'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['starts_at' => 'You already have a booking at this time.']);
            }

            // CarYard takes no deposits: a booking is confirmed or waits for the seller, nothing else.
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

        $this->announce($appointment, $lot, $customer, $vehicle);

        return $appointment;
    }

    /** The lead, the chat line and the messages, once the booking stands. */
    public function announce(Appointment $appointment, Lot $lot, User $customer, ?Vehicle $vehicle): void
    {
        Tracker::record('booking', $lot->id, $vehicle?->id, $appointment->type->value);

        // Every booking creates or updates a lead (TDD M7), and shows in its chat if there is one.
        $lead = $this->captureLead->run($lot, $customer, LeadSource::Booking, $vehicle);
        if ($conversation = $lead->conversation()->first()) {
            $this->sendMessage->run($conversation, null, Message::SYSTEM, AppointmentText::what($appointment).' booked · '.AppointmentText::when($appointment, $lot));
        }

        // A trade-in valuation visit is linked to the buyer's latest trade-in at this seller (TDD M12).
        if ($appointment->type === AppointmentType::TradeIn) {
            TradeIn::withoutGlobalScopes()->where('lot_id', $lot->id)->where('customer_id', $customer->id)->whereNull('appointment_id')
                ->whereIn('status', [TradeInStatus::Submitted, TradeInStatus::Valued, TradeInStatus::Accepted])->latest('id')->first()
                ?->forceFill(['appointment_id' => $appointment->id])->save();
        }

        $customer->notify(new BookingNotice($appointment, BookingNotice::RECEIVED));
        $this->notifyLot->run($appointment, $appointment->status === AppointmentStatus::Confirmed ? 'new' : 'needs_confirmation');
    }
}
