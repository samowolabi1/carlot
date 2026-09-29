<?php

namespace App\Domain\Inventory\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/** Reads a CSV or XLSX into rows keyed by the (slugged) heading row. */
class VehicleRows implements ToArray, WithHeadingRow
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @param array<int, array<string, mixed>> $array */
    public function array(array $array): void
    {
        $this->rows = array_values($array);
    }
}
