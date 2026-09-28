<?php

namespace App\Http\Requests\Manager;

use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\LotManager\Models\LotCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Any member of the lot (lot.member middleware).
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('budget_max')) {
            $this->merge(['budget_max' => preg_replace('/[^\d]/', '', (string) $this->input('budget_max'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:200'],
            'source' => ['required', Rule::enum(CustomerSource::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => [Rule::in(LotCustomer::TAGS)],
            'budget_max' => ['nullable', 'integer', 'min:0', 'max:5000000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'consent_whatsapp' => ['boolean'],
        ];
    }
}
