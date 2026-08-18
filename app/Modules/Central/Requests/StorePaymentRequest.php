<?php

namespace App\Modules\Central\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0',
            'method' => 'nullable|string|in:bank_transfer',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ];
    }
}
