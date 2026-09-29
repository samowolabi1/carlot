<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Domain\Engagement\Models\Broadcast;
use App\Filament\Resources\BroadcastResource;
use Filament\Resources\Pages\EditRecord;

class EditBroadcast extends EditRecord
{
    protected static string $resource = BroadcastResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        /** @var Broadcast $broadcast */
        $broadcast = $this->record;
        abort_unless($broadcast->isEditable(), 403, 'Sent broadcasts can\'t be changed.');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [...$data, 'audience' => BroadcastResource::audience((array) ($data['audience'] ?? [])), 'channels' => array_values((array) ($data['channels'] ?? []))];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
