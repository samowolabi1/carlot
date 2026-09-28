<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\VehicleModel;
use Illuminate\Support\Str;

class ResolveVehicleModel
{
    /**
     * Finds a model of this make by name, or adds it for admin review. Dealers can list
     * a model we haven't seeded without waiting; an admin tidies the name later.
     */
    public function run(Make $make, string $name, ?User $user = null, ?BodyType $bodyType = null): VehicleModel
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return VehicleModel::firstOrCreate(
            ['make_id' => $make->id, 'slug' => Str::slug($name)],
            ['name' => $name, 'body_type' => $bodyType, 'created_by' => $user?->id],
        );
    }

    /**
     * Matches a decoded model name ("RX") to a seeded one: exact slug first, then a
     * seeded name that starts with it ("RX 350").
     */
    public function match(Make $make, ?string $name): ?VehicleModel
    {
        if (blank($name)) {
            return null;
        }

        $slug = Str::slug($name);

        return VehicleModel::where('make_id', $make->id)->where('slug', $slug)->first()
            ?? VehicleModel::where('make_id', $make->id)->approved()->where('slug', 'like', "{$slug}-%")->orderBy('slug')->first();
    }
}
