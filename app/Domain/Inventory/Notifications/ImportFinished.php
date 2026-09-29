<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\Inventory\Models\VehicleImport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In the notification centre: a bulk import has finished. */
class ImportFinished extends Notification
{
    use Queueable;

    public function __construct(public readonly VehicleImport $import) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $bad = count(array_filter($this->import->errors ?? [], fn ($e) => $e['row'] > 0));
        $lot = $this->import->lot;

        return [
            'kind' => 'import',
            'text' => "Import finished: {$this->import->imported_rows} ".str('car')->plural($this->import->imported_rows).' added as drafts'.($bad ? ", {$bad} ".str('row')->plural($bad).' to fix' : '').'. Add photos to publish them.',
            'url' => route('dealer.vehicles.import', $lot),
        ];
    }
}
