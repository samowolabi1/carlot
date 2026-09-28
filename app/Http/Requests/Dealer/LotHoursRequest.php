<?php

namespace App\Http\Requests\Dealer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LotHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lot'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.is_closed' => ['required', 'boolean'],
            'days.*.opens_at' => ['nullable', 'required_if:days.*.is_closed,false', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'required_if:days.*.is_closed,false', 'date_format:H:i'],
            'slot_minutes' => ['required', 'integer', 'in:15,30,45,60,90,120'],
            'slot_capacity' => ['required', 'integer', 'between:1,20'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('days') as $i => $day) {
                if (! ($day['is_closed'] ?? true) && ($day['opens_at'] ?? '') >= ($day['closes_at'] ?? '')) {
                    $validator->errors()->add("days.{$i}.closes_at", 'Closing time must be after opening time.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'days.*.opens_at.required_if' => 'Add an opening time or mark the day closed.',
            'days.*.closes_at.required_if' => 'Add a closing time or mark the day closed.',
        ];
    }
}
