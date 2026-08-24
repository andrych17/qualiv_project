<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** §3J — CSV import: account_code, cost_center_code (blank = unassigned), period_no, amount. Row-level resolution/validation lives in BudgetService::importCsv() — this only checks a real CSV file was uploaded. */
class ImportBudgetCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }
}
