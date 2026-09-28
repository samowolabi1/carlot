<?php

namespace App\Http\Requests\Deals;

use App\Domain\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class OfferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // "₦11,800,000" → 11800000 (whole naira; stored in kobo)
        $this->merge(['amount' => preg_replace('/[^\d]/', '', (string) $this->input('amount'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function amount(): int
    {
        return Money::fromMajor((int) $this->validated('amount'));
    }
}
