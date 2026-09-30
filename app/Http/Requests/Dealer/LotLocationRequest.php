<?php

namespace App\Http\Requests\Dealer;

use App\Domain\Support\Fields;
use App\Domain\Support\Regions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LotLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lot'));
    }

    protected function prepareForValidation(): void
    {
        // Maps say "Lagos State" or "Abuja"; store the list's spelling.
        $this->merge(['state' => Regions::normalize($this->input('state')) ?? $this->input('state')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address' => Fields::text(255, required: true, min: 5),
            'landmark' => Fields::text(255),
            'city' => Fields::place(),
            'state' => ['required', Rule::in(Regions::all())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'latitude.required' => 'Drop a pin on the map or use your current location.',
            'longitude.required' => 'Drop a pin on the map or use your current location.',
            'state.in' => 'Pick the state from the list.',
        ];
    }
}
