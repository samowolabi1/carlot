<?php

namespace App\Domain\Inventory\Imports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** The bulk-import template (TDD M3): the columns ImportVehicles reads, with two example rows. */
class ImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    /** @return list<string> */
    public function headings(): array
    {
        return VehicleRow::COLUMNS;
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        return [
            ['4T1B11HK8JU654821', 'Toyota', 'Camry', 2018, 'SE', 'Sedan', 62400, 'Foreign used', 'Automatic', 'Petrol', 2500, 'Silver', 'Paid', 12500000, 'Yes', 'Clean Tokunbo Camry, reverse camera.'],
            ['', 'Honda', 'Accord', 2016, 'EX-L', 'Sedan', 91000, 'Nigerian used', 'Automatic', 'Petrol', 2400, 'Black', 'Paid', 7900000, 'No', ''],
        ];
    }
}
