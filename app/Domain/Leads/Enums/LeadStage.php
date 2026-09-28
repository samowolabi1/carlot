<?php

namespace App\Domain\Leads\Enums;

use App\Domain\Support\HasOptions;

enum LeadStage: string
{
    use HasOptions;

    case New = 'new';
    case Contacted = 'contacted';
    case TestDrive = 'test_drive';
    case Negotiating = 'negotiating';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::TestDrive => 'Test drive',
            self::Negotiating => 'Negotiating',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    /** How far along the funnel: a lead only moves forward on its own (e.g. a booking). */
    public function rank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Contacted => 1,
            self::TestDrive => 2,
            self::Negotiating => 3,
            self::Won, self::Lost => 4,
        };
    }
}
