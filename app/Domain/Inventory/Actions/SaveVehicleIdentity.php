<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Jobs\RenderShareCard;

class SaveVehicleIdentity
{
    public function __construct(private readonly ResolveVehicleModel $resolveModel) {}

    /**
     * Step 1 of the add-car flow: VIN, make, model, year and trim. Creates the draft on
     * first save.
     *
     * @param  array{vin?: ?string, make_id: int, vehicle_model_id?: ?int, model_name?: ?string, year: int, trim?: ?string, decoded?: array<string, mixed>|null}  $data
     */
    public function run(Lot $lot, User $user, array $data, ?Vehicle $vehicle = null): Vehicle
    {
        $make = Make::findOrFail($data['make_id']);

        $modelId = $data['vehicle_model_id'] ?? null;

        if ($modelId === null && filled($data['model_name'] ?? null)) {
            $modelId = $this->resolveModel->run($make, $data['model_name'], $user)->id;
        }

        $vehicle ??= new Vehicle([
            'lot_id' => $lot->id,
            'created_by' => $user->id,
            'currency' => config('lotlink.currency'),
        ]);

        $vehicle->fill([
            'vin' => filled($data['vin'] ?? null) ? strtoupper($data['vin']) : null,
            'make_id' => $make->id,
            'vehicle_model_id' => $modelId,
            'year' => $data['year'],
            'trim' => $data['trim'] ?? null,
        ]);

        // Prefill details from the VIN decode, without overwriting what the dealer entered.
        foreach (['engine_cc', 'fuel', 'drivetrain', 'body_type'] as $field) {
            if ($vehicle->{$field} === null && filled($data['decoded'][$field] ?? null)) {
                $vehicle->{$field} = $data['decoded'][$field];
            }
        }

        if ($vehicle->body_type === null && $modelId !== null) {
            $vehicle->body_type = $vehicle->model()->value('body_type');
        }

        $vehicle->save();
        RenderShareCard::refresh($vehicle->id);

        return $vehicle;
    }
}
