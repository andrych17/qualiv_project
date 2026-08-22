<?php

namespace App\Modules\Legal\Requests;

use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\LandObject;
use App\Modules\Legal\Models\Matter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateFieldVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matter_id' => 'nullable|integer',
            'land_object_id' => 'nullable|integer',
            'deed_id' => 'nullable|integer',
            'visit_type_id' => 'required|integer',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    /** exists:LEGAL.*,id can't be used — see StoreMatterRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['matter_id' => Matter::class, 'land_object_id' => LandObject::class, 'deed_id' => Deed::class] as $field => $model) {
                $id = $this->input($field);
                if ($id && ! $model::query()->whereKey($id)->exists()) {
                    $validator->errors()->add($field, 'Invalid selection.');
                }
            }
        });
    }
}
