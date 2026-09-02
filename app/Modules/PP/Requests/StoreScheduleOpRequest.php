<?php

namespace App\Modules\PP\Requests;

use App\Modules\PP\Models\ScheduleOp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreScheduleOpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planned_order_id' => 'required|integer',
            'seq' => 'nullable|integer|min:1',
            'resource_type' => ['nullable', Rule::in([
                ScheduleOp::RESOURCE_TYPE_MES_WORK_CENTER,
                ScheduleOp::RESOURCE_TYPE_MES_MACHINE,
                ScheduleOp::RESOURCE_TYPE_MES_STATION,
            ])],
            'resource_ref_id' => 'nullable|integer',
            'planned_start' => 'required|date',
            'planned_end' => 'required|date|after:planned_start',
            'status' => ['nullable', Rule::in([ScheduleOp::STATUS_DRAFT, ScheduleOp::STATUS_COMMITTED])],
        ];
    }

    /** Existence of `planned_order_id` and the production-order/cancelled rules are enforced in ScheduleOpService — same schema-qualified-table discipline as StoreCapacityPlanRequest. */
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
