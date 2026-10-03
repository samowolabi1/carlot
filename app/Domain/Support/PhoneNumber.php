<?php

namespace App\Domain\Support;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalises phone numbers to E.164 (+2348031234567) so one person is one row
 * however they typed their number.
 */
final class PhoneNumber
{
    public static function normalize(string $input, ?string $region = null): string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse(trim($input), $region ?? config('lotlink.default_region'));
        } catch (NumberParseException) {
            throw new InvalidArgumentException('That phone number is not valid.');
        }

        if (! $util->isValidNumber($number)) {
            throw new InvalidArgumentException('That phone number is not valid.');
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    public static function tryNormalize(?string $input, ?string $region = null): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        try {
            return self::normalize($input, $region);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** "+2348031234412" → "0803 123 4412" in the number's own country format ("" when there's no number). */
    public static function display(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            return $util->format($util->parse($e164), PhoneNumberFormat::NATIONAL);
        } catch (NumberParseException) {
            return $e164;
        }
    }

    /** "+2348031234412" → "+234 803 *** 4412", as shown in the seller UI ("" when there's no number). */
    public static function mask(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($e164);
        } catch (NumberParseException) {
            return $e164;
        }

        $national = (string) $number->getNationalNumber();

        return sprintf('+%d %s *** %s', $number->getCountryCode(), substr($national, 0, 3), substr($national, -4));
    }
}
