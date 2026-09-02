<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\WorkCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3M — start a downtime span against a machine or a bare work center. */
class StoreDowntimeEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'machine_id' => 'nullable|integer',
            'work_center_id' => 'nullable|integer',
            'order_id' => 'nullable|integer',
            'category' => ['required', Rule::in([DowntimeEvent::CATEGORY_PLANNED, DowntimeEvent::CATEGORY_UNPLANNED])],
            'reason_code' => ['required', Rule::in(array_merge(DowntimeEvent::PLANNED_REASONS, DowntimeEvent::UNPLANNED_REASONS))],
        ];
    }

    /** Schema-qualified tables (MES.*) can't be checked via `exists:`/`unique:` — see StoreMachineRequest. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $machineId = $this->input('machine_id');
            $workCenterId = $this->input('work_center_id');

            if (! $machineId && ! $workCenterId) {
                $validator->errors()->add('machine_id', 'Downtime must be scoped to a machine or a work center.');
            }
            if ($machineId && ! Machine::query()->whereKey($machineId)->exists()) {
                $validator->errors()->add('machine_id', 'The selected machine is invalid.');
            }
            if ($workCenterId && ! WorkCenter::query()->whereKey($workCenterId)->exists()) {
                $validator->errors()->add('work_center_id', 'The selected work center is invalid.');
            }
            if ($orderId = $this->input('order_id')) {
                if (! ProdOrder::query()->whereKey($orderId)->exists()) {
                    $validator->errors()->add('order_id', 'The selected order is invalid.');
                }
            }

            $category = $this->input('category');
            $reason = $this->input('reason_code');
            $validReasons = $category === DowntimeEvent::CATEGORY_PLANNED
                ? DowntimeEvent::PLANNED_REASONS
                : DowntimeEvent::UNPLANNED_REASONS;

            if ($category && $reason && ! in_array($reason, $validReasons, true)) {
                $validator->errors()->add('reason_code', $category === DowntimeEvent::CATEGORY_PLANNED
                    ? 'Not a valid planned-downtime reason.'
                    : 'Not a valid unplanned-downtime reason.');
            }
        });
    }
}
