<?php

namespace App\Domain\Finance\Enums;

use App\Domain\Support\HasOptions;

/** How a lender receives applications: in the CarYard lender portal, by its own API, or the local demo. */
enum LenderIntegration: string
{
    use HasOptions;

    case Portal = 'portal';
    case Api = 'api';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Portal => 'In the CarYard lender portal',
            self::Api => 'Sent to our own system (API)',
            self::Demo => 'Demo (instant answer, nothing sent)',
        };
    }
}
