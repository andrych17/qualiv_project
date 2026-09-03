<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProdOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * MES_SPECS.md §3S — validated before the payload is ever queued, so a malformed gateway call
 * fails fast instead of retrying a job that can never succeed. Schema-qualified tables (MES.*)
 * can't be checked via `exists:` — see StoreDowntimeEventRequest for the same rule.
 */
class IngestIotDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'machine_id' => ['nullable', 'integer'],
            'readings' => ['array'],
            'readings.*.batch_phase_id' => ['required_with:readings', 'integer'],
            'readings.*.process_parameter_id' => ['required_with:readings', 'integer'],
            'readings.*.value' => ['required_with:readings', 'numeric'],
            'events' => ['array'],
            'events.*.order_id' => ['required_with:events', 'integer'],
            'events.*.event_type' => ['required_with:events', 'string'],
            'events.*.payload' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (empty($this->input('readings')) && empty($this->input('events'))) {
                $validator->errors()->add('payload', 'At least one reading or event is required.');

                return;
            }

            if ($machineId = $this->input('machine_id')) {
                if (! Machine::query()->whereKey($machineId)->exists()) {
                    $validator->errors()->add('machine_id', 'The selected machine is invalid.');
                }
            }

            foreach ($this->input('readings', []) as $i => $reading) {
                if (isset($reading['batch_phase_id']) && ! BatchPhase::query()->whereKey($reading['batch_phase_id'])->exists()) {
                    $validator->errors()->add("readings.{$i}.batch_phase_id", 'The selected batch phase is invalid.');
                }
                if (isset($reading['process_parameter_id']) && ! ProcessParameter::query()->whereKey($reading['process_parameter_id'])->exists()) {
                    $validator->errors()->add("readings.{$i}.process_parameter_id", 'The selected process parameter is invalid.');
                }
            }

            foreach ($this->input('events', []) as $i => $event) {
                if (isset($event['order_id']) && ! ProdOrder::query()->whereKey($event['order_id'])->exists()) {
                    $validator->errors()->add("events.{$i}.order_id", 'The selected order is invalid.');
                }
            }
        });
    }
}
