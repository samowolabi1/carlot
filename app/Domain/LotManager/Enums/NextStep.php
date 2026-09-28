<?php

namespace App\Domain\LotManager\Enums;

use App\Domain\Support\HasOptions;

enum NextStep: string
{
    use HasOptions;

    case None = 'none';
    case CallBack = 'call_back';
    case TestDrive = 'test_drive';
    case Order = 'order';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Nothing yet',
            self::CallBack => 'Call back',
            self::TestDrive => 'Test drive',
            self::Order => 'Start an order',
        };
    }
}
