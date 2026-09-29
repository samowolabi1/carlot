<?php

namespace App\Domain\Trust\Enums;

enum CheckResult: string
{
    case Pass = 'pass';
    case Advisory = 'advisory';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Advisory => 'Advisory',
            self::Fail => 'Fail',
        };
    }

    /** Points towards the 0–100 score: an advisory counts half. */
    public function points(): float
    {
        return match ($this) {
            self::Pass => 1.0,
            self::Advisory => 0.5,
            self::Fail => 0.0,
        };
    }
}
