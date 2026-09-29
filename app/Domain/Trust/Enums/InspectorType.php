<?php

namespace App\Domain\Trust\Enums;

enum InspectorType: string
{
    case Dealer = 'dealer';
    case ThirdParty = 'third_party';

    public function label(): string
    {
        return match ($this) {
            self::Dealer => 'Inspected by the lot',
            self::ThirdParty => 'Independently inspected',
        };
    }
}
