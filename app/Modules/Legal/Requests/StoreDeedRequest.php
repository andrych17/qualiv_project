<?php

namespace App\Modules\Legal\Requests;

use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\Matter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matter_id' => 'nullable|integer',
            'deed_type_id' => 'required|integer',
            'minuta_reference' => 'nullable|string|max:150',
            'summary' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    /** exists:LEGAL.*,id can't be used — see StoreMatterRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $matterId = $this->input('matter_id');
            if ($matterId && ! Matter::query()->whereKey($matterId)->exists()) {
                $validator->errors()->add('matter_id', 'The selected matter is invalid.');
            }

            $deedTypeId = $this->input('deed_type_id');
            $deedType = $deedTypeId ? DeedType::query()->find($deedTypeId) : null;
            if ($deedTypeId && (! $deedType || $deedType->category !== DeedType::CATEGORY_NOTARY)) {
                $validator->errors()->add('deed_type_id', 'The selected deed type is invalid.');
            }
        });
    }
}
