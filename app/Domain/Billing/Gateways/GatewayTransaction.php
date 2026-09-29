<?php

namespace App\Domain\Billing\Gateways;

use Illuminate\Support\Carbon;

/** What the payment provider says happened to a transaction, after server-side verification. */
final class GatewayTransaction
{
    public function __construct(
        public readonly string $reference,
        public readonly bool $successful,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?Carbon $paidAt = null,
        public readonly ?string $customerCode = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $cardLast4 = null,
        public readonly ?string $message = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $providerId = null,
    ) {}
}
