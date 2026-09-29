<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Filament\Resources\SupportTicketResource;
use Filament\Resources\Pages\ListRecords;

class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;

    protected ?string $subheading = 'Tickets from lots. Open ones need a reply; lots see replies as "LotLink Support" with your first name.';
}
