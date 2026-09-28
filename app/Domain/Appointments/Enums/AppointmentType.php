<?php

namespace App\Domain\Appointments\Enums;

use App\Domain\Support\HasOptions;

enum AppointmentType: string
{
    use HasOptions;

    case Viewing = 'viewing';
    case TestDrive = 'test_drive';
    case Inspection = 'inspection';
    case TradeIn = 'trade_in';

    public function label(): string
    {
        return match ($this) {
            self::Viewing => 'Viewing',
            self::TestDrive => 'Test drive',
            self::Inspection => 'Inspection',
            self::TradeIn => 'Trade-in valuation',
        };
    }
}
