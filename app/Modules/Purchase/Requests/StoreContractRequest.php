<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\CRM\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'in:framework,blanket,project'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'auto_renew' => ['boolean'],
            'notice_period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'dms_document_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $supplierId = $this->input('supplier_id');
            if ($supplierId && ! Partner::query()->whereKey($supplierId)->exists()) {
                $validator->errors()->add('supplier_id', 'The selected supplier is invalid.');
            }
        });
    }
}
