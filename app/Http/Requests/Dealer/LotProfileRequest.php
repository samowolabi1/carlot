<?php

namespace App\Http\Requests\Dealer;

use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Fields;
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
            'name' => Fields::businessName(max: 80),
            'tagline' => Fields::text(120),
            'about' => Fields::text(2000),
            'phone' => Fields::phone(),
            'whatsapp' => Fields::phone(required: false),
            'email' => Fields::email(required: false),
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
