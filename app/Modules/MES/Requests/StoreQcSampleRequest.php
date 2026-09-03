<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\QcCharacteristic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3L — one sample against an order (assembly) or a batch phase (process), naming which output row (if any) it's inspecting. */
class StoreQcSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'nullable|integer',
            'batch_phase_id' => 'nullable|integer',
            'output_id' => 'nullable|integer',
            'results' => 'required|array|min:1',
            'results.*.characteristic_id' => 'required|integer',
            'results.*.actual_value' => 'nullable|numeric',
            'results.*.result' => ['required', Rule::in(['pass', 'fail', 'hold'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->input('order_id') && ! $this->input('batch_phase_id')) {
                $validator->errors()->add('order_id', 'A sample must be scoped to an order or a batch phase.');
            }

            foreach ((array) $this->input('results', []) as $i => $result) {
                $characteristicId = $result['characteristic_id'] ?? null;
                if ($characteristicId && ! QcCharacteristic::query()->whereKey($characteristicId)->exists()) {
                    $validator->errors()->add("results.{$i}.characteristic_id", 'The selected characteristic is invalid.');
                }
            }

            $outputId = $this->input('output_id');
            $orderId = $this->input('order_id');
            if ($outputId && $orderId && ! ProductionOutput::query()->whereKey($outputId)->where('order_id', $orderId)->exists()) {
                $validator->errors()->add('output_id', 'The selected output does not belong to this order.');
            }
        });
    }
}
