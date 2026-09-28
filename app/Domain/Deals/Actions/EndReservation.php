<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Actions\RefundPayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Notifications\DealUpdate;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EndReservation
{
    public function __construct(
        private readonly VehicleStateMachine $stateMachine,
        private readonly RefundPayment $refund,
        private readonly DealTimeline $timeline,
    ) {}

    /**
     * Expiry (reservations:expire) or the lot cancelling: the car goes back on sale unless an
     * order holds it, and the deposit is refunded when the lot's policy says so (a lot
     * cancelling always refunds).
     */
    public function run(Reservation $reservation, ReservationStatus $end, string $reason, ?User $by = null): Reservation
    {
        $refund = $end === ReservationStatus::Cancelled || Lot::findOrFail($reservation->lot_id)->reservation_refundable;

        $reservation = DB::transaction(function () use ($reservation, $end, $reason, $by): Reservation {
            $locked = Reservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== ReservationStatus::Active) {
                throw ValidationException::withMessages(['reservation' => 'This reservation has already ended.']);
            }

            $locked->forceFill(['status' => $end, 'ended_at' => now(), 'end_reason' => $reason])->save();

            $vehicle = Vehicle::withoutGlobalScopes()->lockForUpdate()->find($locked->vehicle_id);
            $heldByOrder = SalesOrder::withoutGlobalScopes()->where('vehicle_id', $locked->vehicle_id)
                ->whereIn('status', [OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady])->exists();

            if ($vehicle !== null && $vehicle->status === VehicleStatus::Reserved && ! $heldByOrder) {
                $this->stateMachine->transition($vehicle, VehicleStatus::Available);
            }

            AuditLog::record("reservation.{$end->value}", $locked, ['reason' => $reason, 'amount' => $locked->amount], $by, $locked->lot_id);

            return $locked;
        });

        $payment = $reservation->payment_id ? Payment::find($reservation->payment_id) : null;
        $refunded = $refund && $payment?->status === PaymentStatus::Success;
        if ($refunded) {
            $this->refund->run($payment, $by);
        }

        $lot = Lot::findOrFail($reservation->lot_id);
        $car = $reservation->vehicle->title();
        $money = $reservation->money();
        $update = match ($end) {
            ReservationStatus::Expired => 'Your reservation has ended and the car is back on sale.'.($refunded ? " Your {$money} deposit is being refunded." : " The {$money} deposit is kept, as the lot's terms say."),
            default => "{$lot->name} cancelled your reservation ({$reason}). Your {$money} deposit is being refunded.",
        };

        $this->timeline->post(Lead::withoutGlobalScopes()->find($reservation->lead_id), ($end === ReservationStatus::Expired ? 'Reservation expired' : "Reservation cancelled: {$reason}").($refunded ? " · {$money} refunded" : ''));
        $reservation->customer->notify(new DealUpdate('reservation', $car, $lot->name, $update, DealLinks::buyer()));

        return $reservation;
    }
}
