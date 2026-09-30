<?php

namespace App\Domain\Finance\Support;

use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderType;
use App\Domain\Finance\Models\Lender;
use App\Domain\Support\Fields;
use Illuminate\Validation\Rule;

/** Validation for a lender's details, shared by the sign-up form, the lender's settings and the admin panel. */
final class LenderRules
{
    public const MIN_LOAN = 100_000; // naira

    /** @return array<string, mixed> */
    public static function profile(): array
    {
        return [
            'name' => Fields::businessName(),
            'licence_type' => ['required', Rule::enum(LenderType::class)],
            'licence_number' => Fields::reference(required: true, max: 40),
            'contact_name' => Fields::personName(),
            'contact_email' => Fields::email(),
            'contact_phone' => Fields::phone(),
            'website' => ['nullable', 'url:https,http', 'max:190'],
            'about' => Fields::text(600),
        ];
    }

    /** @return array<string, mixed> */
    public static function product(): array
    {
        return [
            'rate' => ['required', 'numeric', 'min:1', 'max:99', 'decimal:0,2'],
            'min_amount' => Fields::money(min: self::MIN_LOAN),
            'max_amount' => Fields::money(min: self::MIN_LOAN),
            'min_deposit_percent' => Fields::count(0, 90),
            'tenors' => ['required', 'array', 'min:1'],
            'tenors.*' => ['integer', Rule::in(Lender::TENORS)],
            'states' => ['nullable', 'array'],
            'states.*' => ['string', Rule::in(config('lotlink.regions'))],
        ];
    }

    /** @return array<string, mixed> */
    public static function integration(): array
    {
        return [
            'integration' => ['required', Rule::in([LenderIntegration::Portal->value, LenderIntegration::Api->value])],
            'api_url' => ['nullable', 'required_if:integration,api', 'url:https', 'max:255'],
            'api_key' => Fields::text(255),
        ];
    }

    /** @return list<string> */
    public static function moneyKeys(): array
    {
        return ['min_amount', 'max_amount'];
    }
}
