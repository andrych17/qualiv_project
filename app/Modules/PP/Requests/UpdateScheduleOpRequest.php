<?php

namespace App\Modules\PP\Requests;

use App\Modules\PP\Models\ScheduleOp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Resource/date/sequence only — planned_order_id and status are fixed on update (status moves through commit/release). */
class UpdateScheduleOpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seq' => 'nullable|integer|min:1',
            'resource_type' => ['nullable', Rule::in([
                ScheduleOp::RESOURCE_TYPE_MES_WORK_CENTER,
                ScheduleOp::RESOURCE_TYPE_MES_MACHINE,
                ScheduleOp::RESOURCE_TYPE_MES_STATION,
            ])],
            'resource_ref_id' => 'nullable|integer',
            'planned_start' => 'required|date',
            'planned_end' => 'required|date|after:planned_start',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasType = ! empty($this->input('resource_type'));
            $hasRef = ! empty($this->input('resource_ref_id'));

            if ($hasType !== $hasRef) {
                $validator->errors()->add('resource_ref_id', 'Provide both a resource type and a resource reference, or neither.');
            }
        });
    }
}
