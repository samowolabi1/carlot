<?php

namespace App\Filament\Resources\FinanceApplicationResource\Pages;

use App\Filament\Resources\FinanceApplicationResource;
use App\Filament\Widgets\CarLoanStats;
use Filament\Resources\Pages\ListRecords;

class ListFinanceApplications extends ListRecords
{
    protected static string $resource = FinanceApplicationResource::class;

    protected ?string $subheading = 'Every car loan application, across all lenders. Buyers\' income and work details are only for the lender they chose.';

    protected function getHeaderWidgets(): array
    {
        return [CarLoanStats::class];
    }
}
