<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\ServiceCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceCaseStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ServiceCase::STATUSES)],
        ];
    }
}
