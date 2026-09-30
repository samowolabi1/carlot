<?php

namespace App\Rules;

use App\Domain\Support\Fields;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Checks a text field against one of the Fields patterns and says, in plain words, what it may contain. */
final class FieldPattern implements ValidationRule
{
    /** @var array<string, array{0: string, 1: string}> kind => [pattern, what it may contain] */
    public const KINDS = [
        'person_name' => [Fields::PERSON_NAME, 'can only contain letters, spaces, hyphens and apostrophes'],
        'business_name' => [Fields::BUSINESS_NAME, 'needs letters, and can only contain letters, numbers, spaces and & \' . , ( ) / -'],
        'place' => [Fields::PLACE, 'can only contain letters, spaces, hyphens and apostrophes'],
        'model' => [Fields::MODEL, 'can only contain letters, numbers, spaces and - / . + &'],
        'reference' => [Fields::REFERENCE, 'can only contain letters, numbers, spaces and - / _ . # :'],
        'code' => [Fields::CODE, 'can only contain letters, numbers and hyphens'],
        'phone' => [Fields::PHONE_CHARS, 'must look like 0803 123 4567 or +234 803 123 4567'],
    ];

    public function __construct(private readonly string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$pattern, $what] = self::KINDS[$this->kind];

        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $fail(":Attribute {$what}.");
        }
    }
}
