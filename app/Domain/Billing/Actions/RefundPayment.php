<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use Illuminate\Validation\ValidationException;

class RefundPayment
{
    public function __construct(private readonly PaymentGateways $gateways) {}

    /**
     * Refunds through the provider that took the payment (Paystack or Flutterwave): by an admin, or by the system for buyer deposits
     * (a reservation that ends, a test-drive deposit after the visit). A refunded spotlight stops.
     */
    public function run(Payment $payment, ?User $by = null): Payment
    {
        if ($payment->status !== PaymentStatus::Success) {
            throw ValidationException::withMessages(['payment' => 'Only a paid payment can be refunded.']);
        }

        $this->gateways->for($payment->provider)->refund($payment->reference, $payment->amount);
        $payment->forceFill(['status' => PaymentStatus::Refunded, 'refunded_at' => now()])->save();

        // A refunded advert stops: one in review counts as rejected, a running one as removed.
        if ($payment->purpose === PaymentPurpose::Advert) {
            $campaign = AdCampaign::withoutGlobalScopes()->find($payment->payable_id);
            if ($campaign !== null && in_array($campaign->status, AdStatus::holding(), true)) {
                $campaign->forceFill(['status' => $campaign->status === AdStatus::InReview ? AdStatus::Rejected : AdStatus::Removed])->save();
            }
        }

        if ($payment->purpose === PaymentPurpose::Spotlight) {
            $spotlight = Spotlight::withoutGlobalScopes()->find($payment->payable_id);
            if ($spotlight !== null) {
                $spotlight->forceFill(['status' => 'cancelled'])->save();
                $this->recompute($spotlight);
            }
        }

        AuditLog::record('billing.refunded', $payment, ['amount' => $payment->amount, 'reference' => $payment->reference], $by, $payment->lot_id);

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
