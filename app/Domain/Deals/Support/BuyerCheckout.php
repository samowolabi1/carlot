<?php

namespace App\Domain\Deals\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Eloquent\Model;

/** A buyer paying a deposit through the same gateway, verification and webhook as billing. */
class BuyerCheckout
{
    public const CHANNELS = ['card' => 'card', 'transfer' => 'bank_transfer', 'ussd' => 'ussd'];

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function payment(Model $payable, User $buyer, Lot $lot, PaymentPurpose $purpose, int $amount, string $currency, string $description, ?string $channel = null): Payment
    {
        return Payment::create([
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'user_id' => $buyer->id,
            'lot_id' => $lot->id,
            'purpose' => $purpose,
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'provider' => $this->gateway->name(),
            'reference' => Payment::newReference($purpose === PaymentPurpose::Reservation ? 'res' : 'dep'),
            'meta' => array_filter([
                'subaccount' => $lot->paystack_subaccount,
                'channels' => $channel && isset(self::CHANNELS[$channel]) ? [self::CHANNELS[$channel]] : null,
            ]),
        ]);
    }

    public function url(Payment $payment, User $buyer, string $callback): string
    {
        return $this->gateway->checkout($payment, self::email($buyer), $callback);
    }

    /** Paystack needs an email; buyers sign in by phone, so a stand-in is used when they have none. */
    public static function email(User $buyer): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        // Paystack rejects addresses at an IP or at localhost.
        $domain = str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) ? $host : 'lotlink.app';

        return $buyer->email ?: 'buyer-'.$buyer->ulid.'@'.$domain;
    }
}
