<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use Illuminate\Validation\ValidationException;

class RefundPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** Admin refunds through Paystack's Refund API; a refunded spotlight stops. */
    public function run(Payment $payment, User $admin): Payment
    {
        if ($payment->status !== PaymentStatus::Success) {
            throw ValidationException::withMessages(['payment' => 'Only a paid payment can be refunded.']);
        }

        $this->gateway->refund($payment->reference, $payment->amount);
        $payment->forceFill(['status' => PaymentStatus::Refunded, 'refunded_at' => now()])->save();

        if ($payment->purpose === PaymentPurpose::Spotlight) {
            $spotlight = Spotlight::withoutGlobalScopes()->find($payment->payable_id);
            if ($spotlight !== null) {
                $spotlight->forceFill(['status' => 'cancelled'])->save();
                $this->recompute($spotlight);
            }
        }

        AuditLog::record('billing.refunded', $payment, ['amount' => $payment->amount, 'reference' => $payment->reference], $admin, $payment->lot_id);

        return $payment;
    }

    /** The car or lot keeps whatever other spotlights it still has. */
    private function recompute(Spotlight $spotlight): void
    {
        $until = Spotlight::withoutGlobalScopes()->where('lot_id', $spotlight->lot_id)->where('placement', $spotlight->placement)
            ->where('vehicle_id', $spotlight->vehicle_id)->where('status', 'paid')->where('ends_at', '>', now())->max('ends_at');

        if ($spotlight->placement === SpotlightPlacement::Car) {
            $vehicle = Vehicle::withoutGlobalScopes()->find($spotlight->vehicle_id);
            if ($vehicle !== null) {
                $vehicle->spotlight_until = $until;
                $vehicle->save();
            }
        } else {
            Lot::whereKey($spotlight->lot_id)->first()?->forceFill(['featured_until' => $until])->save();
        }
    }
}
