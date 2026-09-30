<?php

namespace App\Http\Requests\Manager;

use App\Domain\LotManager\Actions\SyncOfflineItems;
use App\Domain\Support\Fields;
use App\Domain\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPayment', $this->route('order'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => Fields::cleanMoney($this->input('amount'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...SyncOfflineItems::paymentRules(), 'client_uuid' => ['nullable', 'uuid']];
    }

    /** @return array{amount: int, method: string, reference?: ?string, paid_at?: ?string, client_uuid?: ?string} */
    public function action(): array
    {
        $data = $this->validated();

        return [...$data, 'amount' => Money::fromMajor($data['amount'])];
    }
}
