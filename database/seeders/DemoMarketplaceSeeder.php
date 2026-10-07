<?php

namespace Database\Seeders;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Actions\ArrangeVehicleMedia;
use App\Domain\Inventory\Actions\AttachVehicleMedia;
use App\Domain\Inventory\Actions\PublishVehicle;
use App\Domain\Inventory\Actions\SaveVehicleDetails;
use App\Domain\Inventory\Actions\SaveVehicleIdentity;
use App\Domain\Inventory\Actions\SaveVehiclePrice;
use App\Domain\Inventory\Models\Feature;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\MediaUploads;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo marketplace for local development and screenshots: four sellers in Lagos, Abuja and Port Harcourt with
 * real makes and models at realistic prices, pushed through the real add-car pipeline. The people and businesses
 * are made up. Photos come from database/seeders/demo-photos/{year}-{make}-{model}/ (jpg, png or webp, the first
 * is the cover; credits in CREDITS.md there) when that folder exists, otherwise a drawn placeholder in the car's colour.
 *
 *     php artisan db:seed --class=DemoMarketplaceSeeder
 */
class DemoMarketplaceSeeder extends Seeder
{
    // Phones match the earlier demo, so existing logins keep working (0800 000 0101, password lagos2026cars).
    private const LOTS = [
        ['name' => 'Ade Motors', 'owner' => 'Adebayo Ogunleye', 'phone' => '+2348000000101', 'city' => 'Ikeja', 'state' => 'Lagos', 'address' => '14 Allen Avenue', 'lat' => 6.6018, 'lng' => 3.3515, 'color' => '#16302B',
            'tagline' => 'Clean Tokunbo and Nigerian-used cars on Allen Avenue since 2009', 'old' => ['Demo Seller Ikeja', 'Demo Lot Ikeja']],
        ['name' => 'Lekki Prestige Autos', 'owner' => 'Chukwuemeka Obi', 'phone' => '+2348000000102', 'city' => 'Lekki', 'state' => 'Lagos', 'address' => '22 Admiralty Way', 'lat' => 6.4474, 'lng' => 3.4723, 'color' => '#1E3A8A',
            'tagline' => 'SUVs and executive cars, every one inspected', 'old' => ['Demo Seller Lekki', 'Demo Lot Lekki']],
        ['name' => 'Capital Car Mart', 'owner' => 'Hauwa Abdullahi', 'phone' => '+2348000000103', 'city' => 'Wuse', 'state' => 'FCT', 'address' => 'Plot 9, Aminu Kano Crescent', 'lat' => 9.0765, 'lng' => 7.3986, 'color' => '#7C2D12',
            'tagline' => 'Family cars and 4x4s in the heart of Abuja', 'old' => ['Demo Seller Abuja', 'Demo Lot Abuja']],
        ['name' => 'Garden City Autos', 'owner' => 'Tamunotonye Briggs', 'phone' => '+2348000000104', 'city' => 'Port Harcourt', 'state' => 'Rivers', 'address' => '5 Aba Road', 'lat' => 4.8156, 'lng' => 7.0498, 'color' => '#0F766E',
            'tagline' => 'Pickups, SUVs and work vehicles for the South-South', 'old' => []],
    ];

    // seller, make, model, year, trim, price (naira), km, colour, RGB (placeholder), body, condition, engine cc, drivetrain, features, description
    private const CARS = [
        [0, 'Lexus', 'RX 350', 2019, 'F Sport', 34_500_000, 48_200, 'Nightfall Blue', [30, 30, 35], 'suv', 'foreign_used', 3500, 'awd',
            ['leather-seats', 'sunroof', 'reverse-camera', 'navigation', 'push-button-start', 'blind-spot-monitor'], 'Accident-free US import, full service history. Duty paid, customs papers and plate ready. Smooth V6, no warning lights.'],
        [0, 'Toyota', 'Camry', 2018, 'SE', 12_500_000, 62_400, 'Silver', [150, 155, 160], 'sedan', 'foreign_used', 2500, 'fwd',
            ['reverse-camera', 'bluetooth', 'keyless-entry', 'alloy-wheels'], 'Clean Tokunbo Camry, first body. Cold AC, new tyres, registered in Lagos.'],
        [0, 'Honda', 'Accord', 2017, 'EX-L', 9_800_000, 81_000, 'White', [120, 20, 30], 'sedan', 'foreign_used', 2400, 'fwd',
            ['leather-seats', 'sunroof', 'apple-carplay-android-auto'], 'Leather interior, sunroof, Apple CarPlay. Engine and gear in perfect condition.'],
        [0, 'Toyota', 'Corolla', 2016, 'LE', 7_900_000, 91_000, 'White', [240, 240, 240], 'sedan', 'locally_used', 1800, 'fwd',
            ['air-conditioning', 'bluetooth'], 'One owner since 2019, buy and drive. Service records available.'],
        [1, 'Toyota', 'RAV4', 2017, 'XLE', 14_200_000, 70_500, 'Silver', [40, 70, 120], 'suv', 'foreign_used', 2500, 'awd',
            ['reverse-camera', 'sunroof', 'push-button-start'], 'AWD, sunroof, push start. Very clean inside and out.'],
        [1, 'Toyota', 'Highlander', 2015, 'Limited', 11_300_000, 98_000, 'Burgundy', [90, 90, 95], 'suv', 'foreign_used', 3500, 'awd',
            ['third-row-seating', 'leather-seats', 'reverse-camera'], 'Seven seats, captain chairs, rear camera. Ideal family car.'],
        [1, 'Hyundai', 'Elantra', 2020, 'SEL', 11_000_000, 39_000, 'Silver', [220, 220, 225], 'sedan', 'foreign_used', 2000, 'fwd',
            ['apple-carplay-android-auto', 'blind-spot-monitor', 'reverse-camera'], 'Low mileage, very economical, CarPlay and blind-spot monitor.'],
        [1, 'Mercedes-Benz', 'GLE', 2018, 'GLE 350 4MATIC', 29_000_000, 55_000, 'Iridium Silver', [20, 20, 22], 'suv', 'foreign_used', 3500, 'awd',
            ['leather-seats', 'panoramic-roof', 'navigation', 'premium-sound', 'parking-sensors'], 'Panoramic roof, premium sound, 4MATIC. Inspected, no faults.'],
        [2, 'Toyota', 'Sienna', 2014, 'XLE', 8_700_000, 120_000, 'Black', [180, 160, 130], 'van', 'locally_used', 3500, 'fwd',
            ['third-row-seating', 'rear-entertainment'], 'Eight seats, rear DVD, power doors. Perfect for school runs and church.'],
        [2, 'Kia', 'Sorento', 2017, 'LX', 9_800_000, 88_000, 'Silver', [110, 25, 25], 'suv', 'foreign_used', 2400, 'fwd',
            ['reverse-camera', 'bluetooth'], 'Economical SUV, clean title, duty paid.'],
        [2, 'Toyota', 'Land Cruiser Prado', 2018, 'TX-L', 48_000_000, 64_000, 'White', [235, 235, 235], 'suv', 'foreign_used', 2700, 'awd',
            ['leather-seats', 'reverse-camera', 'navigation', 'tracker-installed'], 'Gulf spec Prado, tracker installed, ready for any road.'],
        [2, 'Toyota', 'Venza', 2015, 'XLE', 10_500_000, 96_000, 'Black', [100, 70, 50], 'wagon', 'foreign_used', 2700, 'fwd',
            ['panoramic-roof', 'leather-seats'], 'Panoramic roof and leather seats. Strong engine, smooth drive.'],
        [3, 'Toyota', 'Hilux', 2019, 'SR5', 32_000_000, 72_000, 'White', [175, 175, 180], 'pickup', 'foreign_used', 2800, 'awd',
            ['tracker-installed', 'reverse-camera'], 'Diesel 4x4 double cabin, tracker installed. Built for work.'],
        [3, 'Honda', 'CR-V', 2018, 'EX', 16_500_000, 58_000, 'White', [100, 105, 110], 'suv', 'foreign_used', 1500, 'awd',
            ['sunroof', 'apple-carplay-android-auto', 'reverse-camera'], 'Turbo engine, sunroof, CarPlay. Very fuel efficient.'],
        [3, 'Lexus', 'ES 350', 2016, null, 13_500_000, 83_000, 'White', [25, 25, 28], 'sedan', 'foreign_used', 3500, 'fwd',
            ['leather-seats', 'navigation', 'premium-sound'], 'Comfortable executive saloon, quiet cabin, clean leather.'],
        [3, 'Ford', 'Edge', 2016, 'SEL', 9_500_000, 90_000, 'Magnetic Grey', [238, 238, 238], 'suv', 'foreign_used', 3500, 'fwd',
            ['reverse-camera', 'keyless-entry'], 'Spacious SUV, good price, papers complete.'],
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

        foreach (self::CARS as $i => [$lotIndex, $make, $model, $year, $trim, $naira, $km, $colour, $rgb, $body, $condition, $cc, $drive, $features, $description]) {
            $this->car($lots[$lotIndex], compact('make', 'model', 'year', 'trim', 'naira', 'km', 'colour', 'rgb', 'body', 'condition', 'cc', 'drive', 'features', 'description'), $i);
        }
    }

    /** @param array<string, mixed> $data */
    private function lot(array $data): Lot
    {
        $owner = User::firstOrCreate(['phone' => $data['phone']], ['name' => $data['owner'], 'phone_verified_at' => now()]);
        $owner->update(['name' => $data['owner']]);

        // Databases seeded earlier have "Demo Seller …" (or "Demo Lot …"): rename those rather than add a second set.
        $lot = Lot::whereIn('name', [$data['name'], ...$data['old']])->first()
            ?? app(CreateLot::class)->run($owner, ['name' => $data['name'], 'phone' => $data['phone'], 'tagline' => $data['tagline']]);

        $lot->update([
            'name' => $data['name'], 'tagline' => $data['tagline'],
            'status' => LotStatus::Active, 'whatsapp' => $data['phone'], 'city' => $data['city'], 'state' => $data['state'], 'address' => $data['address'],
            'latitude' => $data['lat'], 'longitude' => $data['lng'], 'brand_color' => $data['color'],
        ]);
        $lot->forceFill(['submitted_at' => $lot->submitted_at ?? now(), 'verified_at' => $lot->verified_at ?? now()])->save();
        if (str_starts_with($lot->slug, 'demo-')) {
            $lot->forceFill(['slug' => Lot::uniqueSlug($data['name'])])->save(); // the old demo-lot-ikeja style address
        }

        return $lot;
    }

    /** @param array<string, mixed> $c */
    private function car(Lot $lot, array $c, int $n): void
    {
        $makeRow = Make::where('name', $c['make'])->firstOrFail();
        $modelRow = $makeRow->models()->where('name', $c['model'])->firstOrFail();
        $owner = $lot->owner()->firstOrFail();

        $details = [
            'body_type' => $c['body'], 'mileage_km' => $c['km'], 'condition' => $c['condition'], 'transmission' => 'automatic',
            'fuel' => $c['model'] === 'Hilux' ? 'diesel' : 'petrol', 'engine_cc' => $c['cc'], 'drivetrain' => $c['drive'], 'colour' => $c['colour'],
            'duty_status' => 'paid', 'registered' => $c['condition'] === 'locally_used', 'description' => $c['description'],
            'feature_ids' => Feature::whereIn('slug', $c['features'])->pluck('id')->all(),
        ];
        $photos = $this->photosFor($c);

        $vehicle = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('vehicle_model_id', $modelRow->id)->where('year', $c['year'])->first();
        if ($vehicle) {
            // Already there (it may have orders and chats): refresh its details, and swap drawn photos for real ones.
            app(SaveVehicleDetails::class)->run($vehicle, $details);
            // Drawn placeholders are exactly 1600x1000; anything else is already a real photo.
            $drawn = $vehicle->media()->get()->every(fn ($m) => (int) $m->width === 1600 && (int) $m->height === 1000);
            if ($photos !== [] && $drawn) {
                foreach ($vehicle->media()->get() as $media) {
                    app(ArrangeVehicleMedia::class)->delete($vehicle, $media);
                }
                $this->attach($vehicle, $photos);
            }

            return;
        }

        $vehicle = app(SaveVehicleIdentity::class)->run($lot, $owner, [
            'make_id' => $makeRow->id, 'vehicle_model_id' => $modelRow->id, 'year' => $c['year'], 'trim' => $c['trim'],
        ]);
        app(SaveVehicleDetails::class)->run($vehicle, $details);
        $this->attach($vehicle, $photos ?: [$this->photo($c['rgb'], 0), $this->photo($c['rgb'], 1)]);

        app(SaveVehiclePrice::class)->run($vehicle, $owner, $c['naira'] * 100, true);
        app(PublishVehicle::class)->run($vehicle->refresh());
        $vehicle->forceFill(['listed_at' => now()->subDays($n * 2)])->save();
    }

    /** @param list<string> $images file contents, or paths of real photos */
    private function attach(Vehicle $vehicle, array $images): void
    {
        $uploads = app(MediaUploads::class);
        foreach ($images as $image) {
            $real = is_file($image);
            $key = $uploads->newKey($vehicle, $real ? (mime_content_type($image) ?: 'image/jpeg') : 'image/jpeg');
            $uploads->disk()->put($key, $real ? (string) file_get_contents($image) : $image);
            app(AttachVehicleMedia::class)->run($vehicle, $key);
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private function photosFor(array $c): array
    {
        $dir = database_path('seeders/demo-photos/'.Str::slug("{$c['year']} {$c['make']} {$c['model']}"));
        $files = is_dir($dir) ? glob($dir.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) : [];
        sort($files);

        return array_slice($files ?: [], 0, Vehicle::MAX_PHOTOS);
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
