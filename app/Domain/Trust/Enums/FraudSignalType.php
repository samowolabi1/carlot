<?php

namespace App\Domain\Trust\Enums;

enum FraudSignalType: string
{
    case DuplicateVin = 'duplicate_vin';
    case DuplicatePhoto = 'duplicate_photo';
    case LowPrice = 'low_price';
    case ListingBurst = 'listing_burst';
}
