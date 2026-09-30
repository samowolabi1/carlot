<?php

namespace App\Http\Requests\Dealer;

use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Fields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VehicleIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle instanceof Vehicle
            ? $this->user()->can('update', $vehicle)
            : $this->user()->can('create', [Vehicle::class, $this->route('lot')]);
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('vin')) {
            $this->merge(['vin' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('vin')) ?? '')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Lot $lot */
        $lot = $this->route('lot');
        $vehicle = $this->route('vehicle');

        return [
            // VINs never use I, O or Q.
            'vin' => [
                'nullable', 'string', 'size:17', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/',
                Rule::unique('vehicles', 'vin')->where('lot_id', $lot->id)->whereNull('deleted_at')->ignore($vehicle?->id),
            ],
            'make_id' => ['required', 'integer', 'exists:makes,id'],
            'vehicle_model_id' => ['nullable', 'integer', Rule::exists('vehicle_models', 'id')->where('make_id', $this->integer('make_id'))],
            'model_name' => [...Fields::model(required: false), 'required_without:vehicle_model_id'],
            'year' => ['required', 'integer', 'between:1970,'.(now()->year + 1)],
            'trim' => Fields::model(required: false),
            'decoded' => ['nullable', 'array', 'max:10'],
            'decoded.engine_cc' => ['nullable', 'integer', 'between:500,10000'],
            'decoded.fuel' => ['nullable', 'string', 'max:40'],
            'decoded.drivetrain' => ['nullable', 'string', 'max:40'],
            'decoded.body_type' => ['nullable', 'string', 'max:40'],
            'wizard' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vin.size' => 'A VIN has exactly 17 characters.',
            'vin.regex' => 'That VIN has characters a VIN never uses (I, O or Q), or symbols.',
            'vin.unique' => 'A car with this VIN is already in your stock.',
            'model_name.required_without' => 'Choose a model, or type it if it is not in the list.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['fuel' => FuelType::class, 'drivetrain' => Drivetrain::class, 'body_type' => BodyType::class] as $field => $enum) {
                $value = $this->input("decoded.{$field}");

                if ($value !== null && $enum::tryFrom($value) === null) {
                    $validator->errors()->add("decoded.{$field}", 'Unexpected decoded value.');
                }
            }
        }];
    }
}
