<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Engagement\Models\Broadcast;
use App\Filament\Resources\BroadcastResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/** Saved as a draft; "Send now" or "Schedule" from the list. */
class CreateBroadcast extends CreateRecord
{
    protected static string $resource = BroadcastResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'audience' => BroadcastResource::audience((array) ($data['audience'] ?? [])), 'channels' => array_values((array) ($data['channels'] ?? [])), 'status' => 'draft', 'created_by' => Auth::id()];
    }

    protected function afterCreate(): void
    {
        /** @var Broadcast $broadcast */
        $broadcast = $this->record;
        AuditLog::record('admin.broadcast_created', $broadcast, ['title' => $broadcast->title]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
