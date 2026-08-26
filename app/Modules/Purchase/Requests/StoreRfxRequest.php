<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRfxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:rfq,rfi,rfp'],
            'pr_id' => ['nullable', 'integer'],
            'due_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'suppliers' => ['required', 'array', 'min:1'],
            'suppliers.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $prId = $this->input('pr_id');
            if ($prId && ! PurRequisitionHdr::query()->whereKey($prId)->exists()) {
                $validator->errors()->add('pr_id', 'The selected requisition is invalid.');
            }

            $supplierIds = array_filter((array) $this->input('suppliers', []));
            if ($supplierIds && Partner::query()->whereIn('id', $supplierIds)->count() !== count($supplierIds)) {
                $validator->errors()->add('suppliers', 'One or more selected suppliers are invalid.');
            }
        });
    }
}
