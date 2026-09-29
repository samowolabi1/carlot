<?php

namespace App\Filament\Resources\MessageTemplateResource\Pages;

use App\Domain\Messaging\MessageCatalogue;
use App\Filament\Resources\MessageTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListMessageTemplates extends ListRecords
{
    protected static string $resource = MessageTemplateResource::class;

    protected ?string $subheading = 'WhatsApp first, SMS if WhatsApp fails. WhatsApp wording is set in Meta\'s approved templates; the SMS wording can be changed here.';

    public function mount(): void
    {
        MessageCatalogue::sync();
        parent::mount();
    }
}
