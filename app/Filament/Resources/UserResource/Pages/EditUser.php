<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $inspector = (bool) ($data['is_inspector'] ?? false);
        unset($data['is_inspector']);

        $record->fill(collect($data)->except('inspector_company')->all());

        if ($inspector !== $record->isInspector()) {
            AuditLog::record($inspector ? 'admin.inspector_added' : 'admin.inspector_removed', $record);
        }

        $record->forceFill([
            'inspector_since' => $inspector ? ($record->inspector_since ?? now()) : null,
            'inspector_company' => $inspector ? ($data['inspector_company'] ?? null) : null,
        ])->save();

        return $record;
    }
}
