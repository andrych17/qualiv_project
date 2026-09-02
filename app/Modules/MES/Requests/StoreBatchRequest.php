<?php

namespace App\Modules\MES\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** MES_SPECS.md §3I — creates a batch against a process-model order's resolved recipe. */
class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planned_qty' => 'nullable|numeric|min:0.0001',
        ];
    }
}
