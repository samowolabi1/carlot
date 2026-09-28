<?php

namespace App\Http\Requests\Dealer;

use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\DutyStatus;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vehicle'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body_type' => ['nullable', Rule::enum(BodyType::class)],
            'mileage_km' => ['required', 'integer', 'between:0,2000000'],
            'condition' => ['required', Rule::enum(VehicleCondition::class)],
            'transmission' => ['required', Rule::enum(Transmission::class)],
            'fuel' => ['required', Rule::enum(FuelType::class)],
            'engine_cc' => ['nullable', 'integer', 'between:500,10000'],
            'drivetrain' => ['nullable', Rule::enum(Drivetrain::class)],
            'colour' => ['nullable', 'string', 'max:40'],
            'interior_colour' => ['nullable', 'string', 'max:40'],
            'duty_status' => ['nullable', Rule::enum(DutyStatus::class)],
            'registered' => ['boolean'],
            'description' => ['nullable', 'string', 'max:3000'],
            'feature_ids' => ['array', 'max:60'],
            'feature_ids.*' => ['integer', 'exists:features,id'],
        ];
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->safe()->all();
    }
}
