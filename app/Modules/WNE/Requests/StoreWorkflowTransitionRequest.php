<?php

namespace App\Modules\WNE\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_step_id' => 'required|integer',
            'to_step_id' => 'required|integer|different:from_step_id',
            'condition_expression' => 'nullable|array', // NULL = the mandatory default/"else" transition (§3D)
            'condition_expression.field' => 'required_with:condition_expression|string',
            'condition_expression.op' => 'required_with:condition_expression|in:=,!=,>,<,in,contains',
            'condition_expression.value' => 'required_with:condition_expression',
            'seq' => 'nullable|integer|min:0',
        ];
    }
}
