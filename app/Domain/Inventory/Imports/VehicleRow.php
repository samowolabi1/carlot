<?php

namespace App\Domain\Inventory\Imports;

use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\DutyStatus;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Models\Make;
use Illuminate\Support\Str;

/**
 * One spreadsheet row, checked and turned into the add-car actions' data. Accepts the values or
 * the labels sellers see ("Automatic", "Tokunbo", "₦12,500,000").
 */
final class VehicleRow
{
    public const COLUMNS = ['vin', 'make', 'model', 'year', 'trim', 'body_type', 'mileage_km', 'condition', 'transmission', 'fuel', 'engine_cc', 'colour', 'duty', 'price', 'negotiable', 'description'];

    private const ALIASES = [
        'condition' => ['tokunbo' => 'foreign_used', 'foreign used' => 'foreign_used', 'nigerian used' => 'locally_used', 'locally used' => 'locally_used', 'local used' => 'locally_used', 'brand new' => 'new'],
        'transmission' => ['auto' => 'automatic', 'at' => 'automatic', 'mt' => 'manual', 'stick' => 'manual'],
        'duty' => ['yes' => 'paid', 'no' => 'unpaid', 'n/a' => 'na', 'not applicable' => 'na'],
        'body_type' => ['saloon' => 'sedan', 'jeep' => 'suv', 'pick-up' => 'pickup', 'estate' => 'wagon', 'minivan' => 'van'],
    ];

    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, mixed> */
    public array $identity = [];

    /** @var array<string, mixed> */
    public array $details = [];

    public ?int $price = null;

    public bool $negotiable = true;

    /** @param array<string, mixed> $row keyed by heading */
    public function __construct(array $row)
    {
        $get = fn (string $key) => trim((string) ($row[$key] ?? ''));

        $vin = strtoupper(preg_replace('/\s+/', '', $get('vin')) ?? '');
        if ($vin !== '' && ! preg_match('/^[A-HJ-NPR-Z0-9]{11,17}$/', $vin)) {
            $this->errors[] = 'VIN should be 11–17 letters and numbers (no I, O or Q).';
        }

        $make = $get('make') !== '' ? Make::where('slug', Str::slug($get('make')))->orWhere('name', $get('make'))->first() : null;
        if ($make === null) {
            $this->errors[] = $get('make') === '' ? 'Make is missing.' : "We don't know the make \"{$get('make')}\".";
        }
        if ($get('model') === '') {
            $this->errors[] = 'Model is missing.';
        }

        $year = (int) $get('year');
        if ($year < 1980 || $year > (int) now()->format('Y') + 1) {
            $this->errors[] = 'Year should be between 1980 and '.((int) now()->format('Y') + 1).'.';
        }

        $mileage = $this->number($get('mileage_km'));
        if ($get('mileage_km') !== '' && ($mileage === null || $mileage > 2_000_000)) {
            $this->errors[] = 'Mileage should be a number of kilometres.';
        }

        $engine = $this->number($get('engine_cc'));
        if ($engine !== null && ($engine < 500 || $engine > 10_000)) {
            // Litres ("2.5") are common in spreadsheets.
            $litres = (float) str_replace(',', '.', $get('engine_cc'));
            $engine = $litres > 0 && $litres < 10 ? (int) round($litres * 1000) : null;
        }

        $this->price = $this->number($get('price'));
        if ($get('price') !== '' && ($this->price === null || $this->price < 100_000)) {
            $this->errors[] = 'Price should be in naira, e.g. 12500000.';
        }

        $this->negotiable = ! in_array(Str::lower($get('negotiable')), ['no', 'n', 'false', '0'], true);

        $enum = fn (string $column, string $class) => $this->enum($column, $class, $get($column));

        $this->identity = [
            'vin' => $vin ?: null,
            'make_id' => $make?->id,
            'model_name' => $get('model'),
            'year' => $year,
            'trim' => $get('trim') ?: null,
        ];
        $this->details = array_filter([
            'body_type' => $enum('body_type', BodyType::class),
            'mileage_km' => $mileage,
            'condition' => $enum('condition', VehicleCondition::class),
            'transmission' => $enum('transmission', Transmission::class),
            'fuel' => $enum('fuel', FuelType::class),
            'engine_cc' => $engine,
            'colour' => $get('colour') ? mb_substr($get('colour'), 0, 40) : null,
            'duty_status' => $enum('duty', DutyStatus::class),
            'description' => $get('description') ? mb_substr($get('description'), 0, 3000) : null,
        ], fn ($v) => $v !== null);
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** @param class-string<\BackedEnum> $class */
    private function enum(string $column, string $class, string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        $key = Str::lower($raw);
        $key = self::ALIASES[$column][$key] ?? $key;

        foreach ($class::cases() as $case) {
            if ($case->value === $key || (method_exists($case, 'label') && Str::lower($case->label()) === $key)) {
                return (string) $case->value;
            }
        }

        $this->errors[] = ucfirst(str_replace('_', ' ', $column))." \"{$raw}\" isn't one we know.";

        return null;
    }

    private function number(string $raw): ?int
    {
        $digits = preg_replace('/[^\d.]/', '', $raw) ?? '';

        return $digits === '' || ! is_numeric($digits) ? null : (int) round((float) $digits);
    }
}
