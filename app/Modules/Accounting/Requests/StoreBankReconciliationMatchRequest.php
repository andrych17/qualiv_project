<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankReconciliationMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'statement_line_id' => ['required', 'integer'],
            'journal_line_id' => ['required', 'integer'],
        ];
    }
}
