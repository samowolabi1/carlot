<?php

namespace App\Http\Requests\Manager;

use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\Support\Fields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Any member of the seller (lot.member middleware).
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('budget_max')) {
            $this->merge(['budget_max' => Fields::cleanMoney($this->input('budget_max'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => Fields::personName(),
            'email' => Fields::email(required: false, max: 120),
            'address' => Fields::text(200),
            'source' => ['required', Rule::enum(CustomerSource::class)],
            'tags' => ['nullable', 'array', 'max:'.count(LotCustomer::TAGS)],
            'tags.*' => [Rule::in(LotCustomer::TAGS)],
            'budget_max' => Fields::money(required: false, min: 0),
            'notes' => Fields::text(2000),
            'consent_whatsapp' => ['boolean'],
        ];
    }
}
