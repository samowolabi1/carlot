<?php

namespace App\Http\Requests\Trust;

use App\Domain\Support\Fields;
use App\Domain\Trust\Enums\CheckResult;
use App\Domain\Trust\Models\Inspection;
use App\Domain\Trust\Support\InspectionChecklist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The 40-point checklist, from the seller's form or a registered inspector's. */
class InspectionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'checklist' => ['required', 'array'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'inspector_name' => Fields::businessName(required: false),
            'photos' => ['nullable', 'array', 'max:40'],
            'photos.*' => ['array', 'max:4'],
            'photos.*.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ];

        foreach (InspectionChecklist::keys() as $key) {
            $rules["checklist.{$key}.status"] = ['required', Rule::enum(CheckResult::class)];
            $rules["checklist.{$key}.note"] = ['nullable', 'string', 'max:200'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $count = collect($this->file('photos', []))->flatten()->count();

            if ($count > Inspection::MAX_PHOTOS) {
                $validator->errors()->add('photos', 'Add up to '.Inspection::MAX_PHOTOS.' photos.');
            }

            foreach (InspectionChecklist::keys() as $key) {
                if (($this->input("checklist.{$key}.status")) === CheckResult::Fail->value && blank($this->input("checklist.{$key}.note"))) {
                    $validator->errors()->add("checklist.{$key}.note", 'Say what failed: "'.InspectionChecklist::label($key).'".');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['checklist.*.status.required' => 'Choose pass, advisory or fail for every item.', 'photos.*.*.max' => 'Each photo must be 12 MB or smaller.'];
    }

    /** @return array<string, list<UploadedFile>> */
    public function photos(): array
    {
        return collect($this->file('photos', []))->map(fn ($files) => array_values(array_filter((array) $files, fn ($f) => $f instanceof UploadedFile)))->filter()->all();
    }
}
