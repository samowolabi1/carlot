<?php

namespace App\Http\Requests\Manager;

use App\Domain\LotManager\Actions\SyncOfflineItems;
use App\Domain\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Any member of the lot (lot.member middleware).
    }

    protected function prepareForValidation(): void
    {
        // "₦12,500,000" → 12500000 (whole naira; stored in kobo)
        foreach (['agreed_price', 'discount', 'trade_in_value', 'deposit_required'] as $key) {
            if ($this->filled($key)) {
                $this->merge([$key => preg_replace('/[^\d]/', '', (string) $this->input($key))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...SyncOfflineItems::orderRules(), 'client_uuid' => ['nullable', 'uuid']];
    }

    /** @return array<string, mixed> */
    public function action(): array
    {
        $data = $this->validated();

        foreach (['agreed_price', 'discount', 'trade_in_value', 'deposit_required'] as $key) {
            $data[$key] = isset($data[$key]) ? Money::fromMajor($data[$key]) : null;
        }

        return array_filter($data, fn ($v) => $v !== null);
    }
}
