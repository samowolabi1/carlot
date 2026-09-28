<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\CostType;
use App\Domain\LotManager\Models\VehicleCost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordVehicleCost
{
    /**
     * The purchase price or an extra cost against a car (TDD M19). Amounts in minor units.
     *
     * @param  array{type: string, amount: int, supplier?: ?string, note?: ?string, incurred_at: string}  $data
     */
    public function run(Vehicle $vehicle, User $user, array $data, ?UploadedFile $receipt = null): VehicleCost
    {
        $cost = VehicleCost::withoutGlobalScopes()->create([
            'lot_id' => $vehicle->lot_id,
            'vehicle_id' => $vehicle->id,
            'type' => CostType::from($data['type']),
            'amount' => $data['amount'],
            'currency' => $vehicle->currency,
            'supplier' => $data['supplier'] ?? null,
            'note' => $data['note'] ?? null,
            'incurred_at' => $data['incurred_at'],
            'receipt_path' => $receipt?->storeAs('vehicle-costs/'.$vehicle->ulid, Str::lower((string) Str::ulid()).'.'.strtolower($receipt->getClientOriginalExtension() ?: 'pdf'), ['disk' => VehicleCost::DISK]) ?: null,
            'created_by' => $user->id,
        ]);

        AuditLog::record('vehicle.cost_added', $cost, ['type' => $cost->type->value, 'amount' => $cost->amount], $user, $cost->lot_id);

        return $cost;
    }

    public function delete(VehicleCost $cost, User $user): void
    {
        AuditLog::record('vehicle.cost_removed', $cost, ['type' => $cost->type->value, 'amount' => $cost->amount], $user, $cost->lot_id);

        if ($cost->receipt_path) {
            Storage::disk(VehicleCost::DISK)->delete($cost->receipt_path);
        }

        $cost->delete();
    }
}
