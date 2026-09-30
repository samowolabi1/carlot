<?php

namespace App\Http\Requests\Deals;

use App\Domain\Support\Fields;
use App\Domain\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class OfferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // "₦11,800,000" → 11800000 (whole naira; stored in kobo)
        $this->merge(['amount' => Fields::cleanMoney($this->input('amount'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => Fields::money(),
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function amount(): int
    {
        return Money::fromMajor((int) $this->validated('amount'));
    }
}
