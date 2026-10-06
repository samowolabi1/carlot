<?php

namespace Database\Seeders;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Actions\AttachVehicleMedia;
use App\Domain\Inventory\Actions\PublishVehicle;
use App\Domain\Inventory\Actions\SaveVehicleDetails;
use App\Domain\Inventory\Actions\SaveVehicleIdentity;
use App\Domain\Inventory\Actions\SaveVehiclePrice;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\MediaUploads;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Demo marketplace for local development: three demo lots and ten cars with generated
 * placeholder photos, pushed through the real add-car pipeline.
 *
 *     php artisan db:seed --class=DemoMarketplaceSeeder
 */
class DemoMarketplaceSeeder extends Seeder
{
    private const LOTS = [
        ['name' => 'Demo Seller Ikeja', 'phone' => '+2348000000101', 'city' => 'Ikeja', 'state' => 'Lagos', 'lat' => 6.6018, 'lng' => 3.3515, 'color' => '#16302B'],
        ['name' => 'Demo Seller Lekki', 'phone' => '+2348000000102', 'city' => 'Lekki', 'state' => 'Lagos', 'lat' => 6.4474, 'lng' => 3.4723, 'color' => '#1E3A8A'],
        ['name' => 'Demo Seller Abuja', 'phone' => '+2348000000103', 'city' => 'Wuse', 'state' => 'FCT', 'lat' => 9.0765, 'lng' => 7.3986, 'color' => '#7C2D12'],
    ];

    // lot index, make, model, year, trim, price (naira), km, body colour (RGB)
    private const CARS = [
        [0, 'Toyota', 'Camry', 2018, 'SE', 12_500_000, 62_400, [150, 155, 160]],
        [0, 'Lexus', 'RX 350', 2019, null, 34_500_000, 48_200, [30, 30, 35]],
        [0, 'Honda', 'Accord', 2017, 'EX-L', 9_800_000, 81_000, [120, 20, 30]],
        [0, 'Toyota', 'Corolla', 2016, 'LE', 7_900_000, 91_000, [240, 240, 240]],
        [1, 'Toyota', 'RAV4', 2017, 'XLE', 14_200_000, 70_500, [40, 70, 120]],
        [1, 'Toyota', 'Highlander', 2015, 'Limited', 11_300_000, 98_000, [90, 90, 95]],
        [1, 'Hyundai', 'Elantra', 2020, 'SEL', 11_000_000, 39_000, [200, 200, 205]],
        [1, 'Mercedes-Benz', 'GLE', 2018, 'GLE 350', 29_000_000, 55_000, [20, 20, 22]],
        [2, 'Toyota', 'Sienna', 2014, 'XLE', 8_700_000, 120_000, [180, 160, 130]],
        [2, 'Kia', 'Sorento', 2017, 'LX', 9_800_000, 88_000, [110, 25, 25]],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder is for local development only.');
        }

        // Photos process inline so the demo is ready when the command finishes.
        config(['queue.default' => 'sync', 'scout.queue' => false]);
        Queue::setDefaultDriver('sync');

        $this->call(DemoLenderSeeder::class);

        $lots = array_map(fn (array $data) => $this->lot($data), self::LOTS);

        foreach (self::CARS as $i => [$lotIndex, $make, $model, $year, $trim, $naira, $km, $rgb]) {
            $this->car($lots[$lotIndex], $make, $model, $year, $trim, $naira, $km, $rgb, $i);
        }
    }

    private function lot(array $data): Lot
    {
        $owner = User::firstOrCreate(['phone' => $data['phone']], ['name' => "{$data['name']} owner", 'phone_verified_at' => now()]);
        if ($owner->name === str_replace('Seller', 'Lot', $data['name']).' owner') {
            $owner->update(['name' => "{$data['name']} owner"]);
        }
        // Databases seeded before the CarYard rename have "Demo Lot …": rename those rather than add a second set.
        $lot = Lot::whereIn('name', [$data['name'], str_replace('Seller', 'Lot', $data['name'])])->first()
            ?? app(CreateLot::class)->run($owner, ['name' => $data['name'], 'phone' => $data['phone'], 'tagline' => 'Demo seller for trying CarYard locally']);

        $lot->update([
            'name' => $data['name'], 'tagline' => 'Demo seller for trying CarYard locally',
            'status' => LotStatus::Active, 'whatsapp' => $data['phone'], 'city' => $data['city'], 'state' => $data['state'], 'address' => 'Demo address',
            'latitude' => $data['lat'], 'longitude' => $data['lng'], 'brand_color' => $data['color'],
        ]);
        $lot->forceFill(['submitted_at' => now(), 'verified_at' => now()])->save();

        return $lot;
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function car(Lot $lot, string $make, string $model, int $year, ?string $trim, int $naira, int $km, array $rgb, int $n): void
    {
        $makeRow = Make::where('name', $make)->firstOrFail();
        $modelRow = $makeRow->models()->where('name', $model)->firstOrFail();

        if (Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('vehicle_model_id', $modelRow->id)->where('year', $year)->exists()) {
            return;
        }

        $owner = $lot->owner()->firstOrFail();
        $vehicle = app(SaveVehicleIdentity::class)->run($lot, $owner, [
            'make_id' => $makeRow->id, 'vehicle_model_id' => $modelRow->id, 'year' => $year, 'trim' => $trim,
        ]);

        app(SaveVehicleDetails::class)->run($vehicle, [
            'mileage_km' => $km, 'condition' => $n % 4 === 3 ? 'locally_used' : 'foreign_used', 'transmission' => 'automatic',
            'fuel' => 'petrol', 'engine_cc' => 2500, 'duty_status' => 'paid', 'colour' => 'Demo colour',
            'description' => 'Demo listing for local development.',
        ]);

        $uploads = app(MediaUploads::class);

        foreach ([0, 1] as $shot) {
            $key = $uploads->newKey($vehicle, 'image/jpeg');
            $uploads->disk()->put($key, $this->photo($rgb, $shot));
            app(AttachVehicleMedia::class)->run($vehicle, $key);
        }

        app(SaveVehiclePrice::class)->run($vehicle, $owner, $naira * 100, true);
        app(PublishVehicle::class)->run($vehicle->refresh());
        $vehicle->forceFill(['listed_at' => now()->subDays($n * 3)])->save();
    }

    /** A plain illustrated placeholder: sky, road and a car silhouette in the car's colour. */
    private function photo(array $rgb, int $shot): string
    {
        $w = 1600;
        $h = 1000;
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $shot ? 214 : 228, $shot ? 222 : 233, 236));
        imagefilledrectangle($im, 0, (int) ($h * 0.72), $w, $h, imagecolorallocate($im, 205, 199, 188));

        $body = imagecolorallocate($im, ...$rgb);
        $glass = imagecolorallocate($im, 60, 75, 90);
        $tyre = imagecolorallocate($im, 25, 25, 28);
        $hub = imagecolorallocate($im, 170, 170, 175);

        $x = $shot ? 260 : 200;
        imagefilledpolygon($im, [$x, 700, $x, 580, $x + 180, 540, $x + 380, 420, $x + 800, 420, $x + 1020, 540, $x + 1180, 580, $x + 1180, 700], $body);
        imagefilledpolygon($im, [$x + 420, 440, $x + 780, 440, $x + 950, 545, $x + 330, 545], $glass);
        foreach ([$x + 270, $x + 930] as $cx) {
            imagefilledellipse($im, $cx, 710, 230, 230, $tyre);
            imagefilledellipse($im, $cx, 710, 110, 110, $hub);
        }

        ob_start();
        imagejpeg($im, null, 88);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
