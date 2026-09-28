<?php

namespace App\Http\Requests\Deals;

use App\Domain\Deals\Enums\TradeInCondition;
use App\Domain\Deals\Models\TradeIn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TradeInRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['mileage_km' => preg_replace('/[^\d]/', '', (string) $this->input('mileage_km'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'make_id' => ['required', 'integer', 'exists:makes,id'],
            'vehicle_model_id' => ['nullable', 'integer', Rule::exists('vehicle_models', 'id')->where('make_id', $this->integer('make_id'))],
            'model_name' => ['required_without:vehicle_model_id', 'nullable', 'string', 'max:60'],
            'year' => ['required', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'mileage_km' => ['required', 'integer', 'min:0', 'max:2000000'],
            'condition' => ['required', Rule::enum(TradeInCondition::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photos' => ['required', 'array', 'min:1', 'max:'.TradeIn::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp,heic', 'max:10240'],
            'car' => ['nullable', 'string', 'size:26'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['photos.required' => 'Add at least one photo of your car.', 'model_name.required_without' => 'Pick or type the model.'];
    }
}
