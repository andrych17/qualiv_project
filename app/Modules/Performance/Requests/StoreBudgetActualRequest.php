<?php

namespace App\Modules\Performance\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** §3B — manual actual entry for one BudgetLine. */
class StoreBudgetActualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual_value' => 'required|numeric',
        ];
    }
}
