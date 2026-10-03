<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * Whole-naira amounts in the admin: shown grouped as typed ("₦1,500,000") and saved as plain digits.
 * A text input (a number input can't show commas); the commas are stripped before validation and saving.
 */
final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->prefix('₦')
            ->type('text')
            ->inputMode('numeric')
            ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
            ->stripCharacters([',', ' '])
            ->integer();
    }

    /** The plain number from a form value that may still carry commas ("15,000" → 15000), for live helper text. */
    public static function value(mixed $state): int
    {
        return (int) str_replace([',', ' '], '', (string) $state);
    }
}
