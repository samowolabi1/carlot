<?php

namespace App\Http\Requests\Dealer;

use Illuminate\Foundation\Http\FormRequest;

class LotBookingRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lot'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'booking_auto_confirm' => ['required', 'boolean'],
            'booking_min_notice_minutes' => ['required', 'integer', 'in:0,30,60,120,240,1440'],
        ];
    }
}
