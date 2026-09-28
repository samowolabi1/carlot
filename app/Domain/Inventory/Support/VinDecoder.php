<?php

namespace App\Domain\Inventory\Support;

use Illuminate\Http\Client\ConnectionException;

interface VinDecoder
{
    /**
     * Decodes a 17-character VIN. Returns null when the VIN isn't recognised.
     *
     * @throws ConnectionException when the service can't be reached
     */
    public function decode(string $vin): ?DecodedVin;
}
