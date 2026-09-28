<?php

namespace App\Domain\Support;

/**
 * For backed enums with a label(): the value/label list the frontend renders as options.
 */
trait HasOptions
{
    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
