<?php

namespace App\Domain\Inventory\Jobs;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Actions\SaveVehicleDetails;
use App\Domain\Inventory\Actions\SaveVehicleIdentity;
use App\Domain\Inventory\Actions\SaveVehiclePrice;
use App\Domain\Inventory\Imports\VehicleRow;
use App\Domain\Inventory\Imports\VehicleRows;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleImport;
use App\Domain\Inventory\Notifications\ImportFinished;
use App\Domain\Lots\Models\Lot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * ImportVehicles (TDD M3): validates the file row by row and creates a draft for each good row
 * through the same actions as the add-car flow. Bad rows are listed with their problems;
 * a car already in stock with the same VIN is skipped.
 */
class ImportVehicles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('default');
    }

    public function handle(SaveVehicleIdentity $identity, SaveVehicleDetails $details, SaveVehiclePrice $price): void
    {
        $import = VehicleImport::withoutGlobalScopes()->find($this->importId);

        if ($import === null || $import->status !== 'queued') {
            return;
        }

        $import->update(['status' => 'processing']);
        $lot = Lot::findOrFail($import->lot_id);
        $user = $import->user ?? $lot->owner;

        try {
            $reader = new VehicleRows;
            Excel::import($reader, $import->file_path, VehicleImport::DISK);
            $rows = array_values(array_filter($reader->rows, fn (array $r) => array_filter($r, fn ($v) => $v !== null && $v !== '') !== []));
        } catch (Throwable) {
            $import->update(['status' => 'failed', 'errors' => [['row' => 0, 'messages' => ["We couldn't read that file. Use the template, saved as .xlsx or .csv."]]], 'finished_at' => now()]);

            return;
        }

        $errors = [];
        $imported = 0;

        if (count($rows) > VehicleImport::MAX_ROWS) {
            $errors[] = ['row' => 0, 'messages' => ['Only the first '.VehicleImport::MAX_ROWS.' rows were read. Split bigger files.']];
            $rows = array_slice($rows, 0, VehicleImport::MAX_ROWS);
        }

        foreach ($rows as $i => $raw) {
            $line = $i + 2; // the heading is row 1
            $row = new VehicleRow($raw);

            if ($row->valid() && $row->identity['vin'] && Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('vin', $row->identity['vin'])->exists()) {
                $row->errors[] = 'A car with this VIN is already in your stock.';
            }

            if (! $row->valid()) {
                $errors[] = ['row' => $line, 'messages' => $row->errors];

                continue;
            }

            DB::transaction(function () use ($identity, $details, $price, $lot, $user, $row): void {
                $vehicle = $identity->run($lot, $user, $row->identity);
                $details->run($vehicle, $row->details);
                if ($row->price !== null) {
                    $price->run($vehicle, $user, $row->price * 100, $row->negotiable);
                }
            });
            $imported++;
        }

        $import->update([
            'status' => 'done',
            'total_rows' => count($rows),
            'imported_rows' => $imported,
            'errors' => $errors ?: null,
            'finished_at' => now(),
        ]);

        if ($user instanceof User) {
            $user->notify(new ImportFinished($import));
        }
    }

    public function failed(?Throwable $e): void
    {
        VehicleImport::withoutGlobalScopes()->whereKey($this->importId)->update(['status' => 'failed', 'finished_at' => now()]);
    }
}
