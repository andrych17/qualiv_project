<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(Lead::DIRECT_STAGES)],
        ];
    }
}
