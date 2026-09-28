<?php

namespace App\Http\Requests\Dealer;

use App\Domain\Lots\Models\Lot;
use App\Domain\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LotProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lot = $this->route('lot');

        // Creating a new lot (onboarding step 1) is open to any signed-in user.
        return ! $lot instanceof Lot || $this->user()->can('update', $lot);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'about' => ['nullable', 'string', 'max:2000'],
            'phone' => ['required', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['phone', 'whatsapp'] as $field) {
                if (filled($this->input($field)) && PhoneNumber::tryNormalize($this->input($field)) === null) {
                    $validator->errors()->add($field, 'Enter a valid phone number.');
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['phone'] = PhoneNumber::tryNormalize($data['phone']);
        $data['whatsapp'] = PhoneNumber::tryNormalize($data['whatsapp'] ?? null) ?? $data['phone'];

        return data_get($data, $key, $default);
    }
}
