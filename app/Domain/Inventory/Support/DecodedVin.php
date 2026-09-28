<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\FuelType;

final class DecodedVin
{
    public function __construct(
        public readonly string $vin,
        public readonly ?string $make,
        public readonly ?string $model,
        public readonly ?int $year,
        public readonly ?string $trim = null,
        public readonly ?int $engineCc = null,
        public readonly ?FuelType $fuel = null,
        public readonly ?Drivetrain $drivetrain = null,
        public readonly ?BodyType $bodyType = null,
    ) {}

    public function found(): bool
    {
        return $this->make !== null && $this->year !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'vin' => $this->vin,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'trim' => $this->trim,
            'engine_cc' => $this->engineCc,
            'fuel' => $this->fuel?->value,
            'drivetrain' => $this->drivetrain?->value,
            'body_type' => $this->bodyType?->value,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['vin'],
            $data['make'],
            $data['model'],
            $data['year'],
            $data['trim'] ?? null,
            $data['engine_cc'] ?? null,
            FuelType::tryFrom((string) ($data['fuel'] ?? '')),
            Drivetrain::tryFrom((string) ($data['drivetrain'] ?? '')),
            BodyType::tryFrom((string) ($data['body_type'] ?? '')),
        );
    }
}
