<?php

namespace App\Domain\Support;

use App\Rules\FieldPattern;
use App\Rules\PhoneNumberRule;
use Illuminate\Validation\Rules\Password;

/**
 * One place for what each kind of form field may hold: its type, length and characters.
 * Controllers, Form Requests and the API build their rules from these, and the browser
 * mirrors them in resources/js/lib/fields.ts (FieldsTest keeps the two in step).
 *
 * The patterns are written so they work unchanged in PHP (/u) and JavaScript (u flag).
 */
final class Fields
{
    /** Letters (any language), spaces, apostrophes, hyphens and dots: "Chioma Okafor", "O'Neil", "Adé-Bàyọ̀". */
    public const PERSON_NAME = "/^[\\p{L}\\p{M}][\\p{L}\\p{M}'’. \\-]*$/u";

    /** A business or bank name: needs a letter; digits and & ' . , ( ) / - allowed ("AutoHub 24/7 Ltd", "GTBank"). */
    public const BUSINESS_NAME = "/^(?=.*\\p{L})[\\p{L}\\p{M}\\p{N}&'’.,()\\/ \\-]+$/u";

    /** A place or colour: "Ikeja", "Port Harcourt", "Ado-Ekiti", "Pearl white". */
    public const PLACE = "/^[\\p{L}\\p{M}][\\p{L}\\p{M}'’. \\-]*$/u";

    /** A car model or trim: "RAV4", "C-Class", "3 Series", "E 350 4Matic", "Land Cruiser V8". */
    public const MODEL = '/^[\\p{L}\\p{N}][\\p{L}\\p{N}\\/.+&() \\-]*$/u';

    /** A payment or bank reference: "TRF/2026/00341", "POS-8812", "FT23#991". */
    public const REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9\\/_.#: \\-]*$/u';

    /** A coupon code: letters, digits and hyphens. */
    public const CODE = '/^[A-Za-z0-9][A-Za-z0-9\\-]*$/u';

    /** What a phone field may contain before it is checked properly: digits, +, spaces, brackets, dashes. */
    public const PHONE_CHARS = '/^\\+?[0-9 ()\\-]{7,20}$/u';

    /** Largest amount any money field takes, in whole naira (₦5bn); kobo fits comfortably in bigint. */
    public const MONEY_MAX = 5_000_000_000;

    /** bcrypt only reads the first 72 bytes of a password. */
    public const PASSWORD_MAX = 72;

    /** @return list<mixed> */
    public static function personName(bool $required = true, int $max = 80): array
    {
        return [...self::presence($required), 'string', 'min:2', "max:{$max}", new FieldPattern('person_name')];
    }

    /** @return list<mixed> */
    public static function businessName(bool $required = true, int $max = 120): array
    {
        return [...self::presence($required), 'string', 'min:2', "max:{$max}", new FieldPattern('business_name')];
    }

    /** @return list<mixed> */
    public static function place(bool $required = true, int $max = 80): array
    {
        return [...self::presence($required), 'string', 'min:2', "max:{$max}", new FieldPattern('place')];
    }

    /** @return list<mixed> */
    public static function model(bool $required = true, int $max = 60): array
    {
        return [...self::presence($required), 'string', "max:{$max}", new FieldPattern('model')];
    }

    /** @return list<mixed> */
    public static function reference(bool $required = false, int $max = 64): array
    {
        return [...self::presence($required), 'string', "max:{$max}", new FieldPattern('reference')];
    }

    /** @return list<mixed> */
    public static function code(bool $required = true, int $max = 32): array
    {
        return [...self::presence($required), 'string', "max:{$max}", new FieldPattern('code')];
    }

    /** A phone number the platform can message: checked with libphonenumber for the seller's region. @return list<mixed> */
    public static function phone(bool $required = true, ?string $region = null): array
    {
        return [...self::presence($required), 'string', 'max:20', new FieldPattern('phone'), new PhoneNumberRule($region)];
    }

    /** @return list<mixed> */
    public static function email(bool $required = true, int $max = 190): array
    {
        return [...self::presence($required), 'string', "max:{$max}", 'email:rfc,strict'];
    }

    /** Free text (notes, messages, descriptions): a string with a length limit. @return list<mixed> */
    public static function text(int $max, bool $required = false, int $min = 0): array
    {
        return [...self::presence($required), 'string', ...($min > 0 ? ["min:{$min}"] : []), "max:{$max}"];
    }

    /** Whole naira; pass the value through cleanMoney() first so "₦1,500,000" arrives as 1500000. @return list<mixed> */
    public static function money(bool $required = true, int $min = 1, int $max = self::MONEY_MAX): array
    {
        return [...self::presence($required), 'integer', "min:{$min}", "max:{$max}"];
    }

    /** A whole number in a range (mileage, counts, days). @return list<mixed> */
    public static function count(int $min, int $max, bool $required = true): array
    {
        return [...self::presence($required), 'integer', "between:{$min},{$max}"];
    }

    /** A new password: 8–72 characters with letters and numbers. @return list<mixed> */
    public static function newPassword(): array
    {
        return ['required', 'string', 'max:'.self::PASSWORD_MAX, 'confirmed', Password::min(8)->letters()->numbers()];
    }

    /** A public ULID (26 characters) sent back by a form. @return list<mixed> */
    public static function ulid(bool $required = true): array
    {
        return [...self::presence($required), 'string', 'size:26', 'alpha_num:ascii'];
    }

    /**
     * Money as people type it: "₦1,500,000", "1 500 000", "NGN 1500000" or "1,500,000.00" become "1500000".
     * Anything else (letters, kobo like "1500.50") is left as typed, so `integer` rejects it instead of
     * silently turning "1500.50" into 150,050.
     */
    public static function cleanMoney(mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }
        $clean = preg_replace('/^\s*(₦|NGN|N)\s*/iu', '', trim((string) $value)) ?? '';
        $clean = preg_replace('/[,\s]/u', '', $clean) ?? '';
        $clean = preg_replace('/\.0+$/', '', $clean) ?? '';

        return $clean === '' ? null : $clean;
    }

    /** @return list<string> */
    private static function presence(bool $required): array
    {
        return [$required ? 'required' : 'nullable'];
    }
}
