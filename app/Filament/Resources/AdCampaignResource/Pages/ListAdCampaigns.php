<?php

namespace App\Filament\Resources\AdCampaignResource\Pages;

use App\Filament\Resources\AdCampaignResource;
use Filament\Resources\Pages\ListRecords;

class ListAdCampaigns extends ListRecords
{
    protected static string $resource = AdCampaignResource::class;

    protected ?string $subheading = 'Paid adverts wait here until someone checks them. Approve to schedule; reject to refund.';
}
