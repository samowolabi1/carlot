<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\FuelType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * NHTSA vPIC: free, covers US-spec cars, which is most Tokunbo stock in Nigeria.
 * Results are cached for 30 days per VIN.
 */
class NhtsaVinDecoder implements VinDecoder
{
    public function __construct(private readonly string $baseUrl = 'https://vpic.nhtsa.dot.gov/api') {}

    public function decode(string $vin): ?DecodedVin
    {
        $vin = strtoupper($vin);

        $cached = Cache::remember("vin:{$vin}", now()->addDays(30), function () use ($vin): array {
            $row = Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout(8)
                ->retry(2, 300, throw: false)
                ->get("/vehicles/DecodeVinValues/{$vin}", ['format' => 'json'])
                ->throw()
                ->json('Results.0', []);

            return $this->map($vin, is_array($row) ? $row : [])->toArray();
        });

        $decoded = DecodedVin::fromArray($cached);

        return $decoded->found() ? $decoded : null;
    }

    /** @param array<string, mixed> $row */
    private function map(string $vin, array $row): DecodedVin
    {
        $value = fn (string $key): ?string => filled($row[$key] ?? null) && $row[$key] !== 'Not Applicable' ? trim((string) $row[$key]) : null;

        $cc = $value('DisplacementCC');
        $litres = $value('DisplacementL');
        $engineCc = $cc !== null ? (int) round((float) $cc) : ($litres !== null ? (int) round((float) $litres * 1000) : null);

        return new DecodedVin(
            vin: $vin,
            make: $value('Make') ? Str::title(strtolower($value('Make'))) : null,
            model: $value('Model'),
            year: $value('ModelYear') ? (int) $value('ModelYear') : null,
            trim: $value('Trim'),
            engineCc: $engineCc ?: null,
            fuel: $this->fuel($value('FuelTypePrimary'), $value('ElectrificationLevel')),
            drivetrain: $this->drivetrain($value('DriveType')),
            bodyType: $this->bodyType($value('BodyClass')),
        );
    }

    private function fuel(?string $primary, ?string $electrification): ?FuelType
    {
        if ($electrification !== null && str_contains(strtolower($electrification), 'hybrid')) {
            return FuelType::Hybrid;
        }

        $primary = strtolower((string) $primary);

        return match (true) {
            str_contains($primary, 'gasoline') => FuelType::Petrol,
            str_contains($primary, 'diesel') => FuelType::Diesel,
            str_contains($primary, 'electric') => FuelType::Electric,
            str_contains($primary, 'natural gas') => FuelType::Cng,
            default => null,
        };
    }

    private function drivetrain(?string $drive): ?Drivetrain
    {
        $drive = strtolower((string) $drive);

        return match (true) {
            str_contains($drive, 'awd') || str_contains($drive, 'all-wheel') => Drivetrain::Awd,
            str_contains($drive, '4wd') || str_contains($drive, '4x4') || str_contains($drive, '4-wheel') => Drivetrain::FourWd,
            str_contains($drive, 'fwd') || str_contains($drive, 'front-wheel') => Drivetrain::Fwd,
            str_contains($drive, 'rwd') || str_contains($drive, 'rear-wheel') => Drivetrain::Rwd,
            default => null,
        };
    }

    private function bodyType(?string $class): ?BodyType
    {
        $class = strtolower((string) $class);

        return match (true) {
            str_contains($class, 'sport utility') || str_contains($class, 'multi-purpose') || str_contains($class, 'crossover') => BodyType::Suv,
            str_contains($class, 'pickup') => BodyType::Pickup,
            str_contains($class, 'minivan') || str_contains($class, 'van') => BodyType::Van,
            str_contains($class, 'hatchback') => BodyType::Hatchback,
            str_contains($class, 'coupe') => BodyType::Coupe,
            str_contains($class, 'convertible') => BodyType::Convertible,
            str_contains($class, 'wagon') => BodyType::Wagon,
            str_contains($class, 'bus') => BodyType::Bus,
            str_contains($class, 'truck') => BodyType::Truck,
            str_contains($class, 'sedan') || str_contains($class, 'saloon') => BodyType::Sedan,
            default => null,
        };
    }
}
