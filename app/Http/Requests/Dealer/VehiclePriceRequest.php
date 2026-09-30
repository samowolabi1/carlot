<?php

namespace App\Http\Requests\Dealer;

use App\Domain\Support\Fields;
use Illuminate\Foundation\Http\FormRequest;

class VehiclePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vehicle'));
    }

    protected function prepareForValidation(): void
    {
        // "₦12,500,000" or "12500000" → 12500000
        $this->merge(['price' => Fields::cleanMoney($this->input('price'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Whole naira; stored in kobo.
            'price' => Fields::money(min: 10000),
            'negotiable' => ['boolean'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['price.min' => 'Enter the asking price in naira.'];
    }
}
