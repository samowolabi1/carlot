<?php

namespace App\Rules;

use App\Domain\Lots\Support\CurrentLot;
use App\Domain\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A real, dialable number, checked with libphonenumber. Local numbers ("0803…") are read for the given region,
 * else the current lot's country (seller forms), else the platform default (Nigeria).
 */
final class PhoneNumberRule implements ValidationRule
{
    public function __construct(private readonly ?string $region = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::tryNormalize($value, $this->region ?? (app(CurrentLot::class)->get()?->country ?: null)) === null) {
            $fail(':Attribute isn\'t a valid phone number. Check the digits, e.g. 0803 123 4567.');
        }
    }
}
