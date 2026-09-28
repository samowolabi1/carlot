<?php

namespace App\Domain\LotManager\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One row per person per lot, matched by E.164 phone (TDD M19: Walk-in register). A
 * match adds to that customer's history instead of creating a duplicate.
 */
final class CustomerBook
{
    /**
     * @param  array{name?: ?string, phone: string, email?: ?string, source?: ?string, budget_max?: ?int, consent_whatsapp?: ?bool}  $data
     */
    public static function match(Lot $lot, array $data, ?Carbon $seenAt = null): LotCustomer
    {
        try {
            $phone = PhoneNumber::normalize($data['phone'], $lot->country ?: null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $customer = LotCustomer::withoutGlobalScopes()->withTrashed()->createOrFirst(
            ['lot_id' => $lot->id, 'phone' => $phone],
            [
                'name' => filled($data['name'] ?? null) ? $data['name'] : PhoneNumber::display($phone),
                'source' => $data['source'] ?? CustomerSource::WalkIn->value,
            ],
        );

        if ($customer->trashed()) {
            $customer->restore();
        }

        if (! $customer->wasRecentlyCreated && filled($data['name'] ?? null)) {
            $customer->name = $data['name'];
        }

        if (filled($data['email'] ?? null)) {
            $customer->email = $data['email'];
        }

        if (($data['budget_max'] ?? null) !== null) {
            $customer->budget_max = $data['budget_max'];
        }

        // Consent is only ever added here; customers withdraw it by replying STOP or
        // asking the lot, which clears it on their record.
        if ($data['consent_whatsapp'] ?? false) {
            $customer->consent_whatsapp = true;
        }

        $customer->user_id ??= User::where('phone', $phone)->value('id');

        if ($seenAt !== null && ($customer->last_seen_at === null || $seenAt->greaterThan($customer->last_seen_at))) {
            $customer->last_seen_at = $seenAt;
        }

        $customer->save();

        return $customer;
    }
}
