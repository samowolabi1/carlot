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
     * Expiry (reservations:expire) or the seller cancelling: the car goes back on sale unless an
     * order holds it. The deposit is owed back when the seller's policy says so (a seller cancelling
     * always owes it); the seller refunds from its own account and records it. Only deposits paid
     * online before CarYard stopped taking buyer payments are refunded through the gateway.
     */
    public function run(Reservation $reservation, ReservationStatus $end, string $reason, ?User $by = null): Reservation
    {
        $refund = $end === ReservationStatus::Cancelled || Lot::findOrFail($reservation->lot_id)->reservation_refundable;

        $reservation = DB::transaction(function () use ($reservation, $end, $reason, $by, $refund): Reservation {
            $locked = Reservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== ReservationStatus::Active) {
                throw ValidationException::withMessages(['reservation' => 'This reservation has already ended.']);
            }

            $legacy = $locked->payment_id !== null && Payment::find($locked->payment_id)?->status === PaymentStatus::Success;
            $locked->forceFill(['status' => $end, 'ended_at' => now(), 'end_reason' => $reason, 'refund_due' => $refund && ! $legacy])->save();

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
        $legacyRefund = $refund && $payment?->status === PaymentStatus::Success;
        if ($legacyRefund) {
            $this->refund->run($payment, $by);
        }
        $refunded = $legacyRefund || $reservation->refund_due;

        $lot = Lot::findOrFail($reservation->lot_id);
        $car = $reservation->vehicle->title();
        $money = $reservation->money();
        $update = match ($end) {
            ReservationStatus::Expired => 'Your reservation has ended and the car is back on sale.'.($refunded ? " {$lot->name} will refund your {$money} deposit." : " The {$money} deposit is kept, as the seller's terms say."),
            default => "{$lot->name} cancelled your reservation ({$reason}). They will refund your {$money} deposit.",
        };

        $this->timeline->post(Lead::withoutGlobalScopes()->find($reservation->lead_id), ($end === ReservationStatus::Expired ? 'Reservation expired' : "Reservation cancelled: {$reason}").($refunded ? " · {$money} to refund" : ''));
        $reservation->customer->notify(new DealUpdate('reservation', $car, $lot->name, $update, DealLinks::buyer()));

        return $reservation;
    }
}
