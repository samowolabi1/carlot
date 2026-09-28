<?php

namespace Database\Seeders;

use App\Domain\Inventory\Enums\FeatureGroup;
use App\Domain\Inventory\Models\Feature;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VehicleCatalogueSeeder extends Seeder
{
    private const FEATURES = [
        'comfort' => ['Air conditioning', 'Climate control', 'Leather seats', 'Heated seats', 'Power seats', 'Third-row seating', 'Sunroof', 'Panoramic roof', 'Keyless entry', 'Push-button start', 'Cruise control', 'Power tailgate', 'Tinted windows'],
        'safety' => ['ABS', 'Airbags', 'Reverse camera', '360° camera', 'Parking sensors', 'Blind-spot monitor', 'Lane-keep assist', 'Adaptive cruise control', 'Alarm system', 'Tracker installed'],
        'tech' => ['Touchscreen', 'Apple CarPlay / Android Auto', 'Bluetooth', 'Navigation', 'Premium sound', 'Rear entertainment', 'Wireless charging', 'Alloy wheels', 'LED headlights', 'Fog lights'],
    ];

    public function run(): void
    {
        /** @var array<string, array<string, string>> $catalogue */
        $catalogue = require database_path('data/vehicle_catalogue.php');

        foreach ($catalogue as $makeName => $models) {
            $make = Make::updateOrCreate(['slug' => Str::slug($makeName)], ['name' => $makeName]);

            foreach ($models as $modelName => $bodyType) {
                VehicleModel::updateOrCreate(
                    ['make_id' => $make->id, 'slug' => Str::slug($modelName)],
                    ['name' => $modelName, 'body_type' => $bodyType, 'approved_at' => now()],
                );
            }
        }

        foreach (self::FEATURES as $group => $names) {
            foreach ($names as $name) {
                Feature::updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'group' => FeatureGroup::from($group)]);
            }
        }
    }
}
