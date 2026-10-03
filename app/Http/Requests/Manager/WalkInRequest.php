<?php

namespace App\Http\Requests\Manager;

use App\Domain\LotManager\Actions\SyncOfflineItems;
use App\Domain\Support\Fields;
use App\Domain\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class WalkInRequest extends FormRequest
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
        return [...SyncOfflineItems::walkInRules(), 'client_uuid' => ['nullable', 'uuid']];
    }

    /** @return array<string, mixed> */
    public function action(): array
    {
        $data = $this->validated();

        return [...$data, 'budget_max' => isset($data['budget_max']) ? Money::fromMajor($data['budget_max']) : null];
    }
}
