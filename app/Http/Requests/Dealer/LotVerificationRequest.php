<?php

namespace App\Http\Requests\Dealer;

use App\Domain\Lots\Models\Lot;
use Illuminate\Foundation\Http\FormRequest;

class LotVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Lot $lot */
        $lot = $this->route('lot');

        return $this->user()?->can('submit', $lot) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Companies: RC + 5–8 digits; business names: BN + digits (CAC).
            'cac_number' => ['required', 'string', 'regex:/^\s*((RC|BN|IT)[\s\-]?)?\d{5,8}\s*$/i'],
            'certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'frontage' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cac_number.regex' => 'Enter the RC or BN number from your CAC certificate, e.g. RC 1234567.',
            'certificate.required' => 'Add your CAC certificate (a PDF or a clear photo).',
            'certificate.max' => 'The certificate must be 10 MB or smaller.',
            'frontage.required' => 'Add a photo of your lot from the road, with your sign visible.',
            'frontage.max' => 'The photo must be 12 MB or smaller.',
        ];
    }
}
