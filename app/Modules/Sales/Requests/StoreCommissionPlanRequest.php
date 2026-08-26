<?php

namespace App\Modules\Sales\Requests;

use App\Modules\Sales\Models\SalesTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCommissionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'basis' => ['required', 'in:flat_pct,tiered'],
            'flat_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tier_rules' => ['nullable', 'array'],
            'applies_to_type' => ['required', 'in:team,rep'],
            'applies_to_sales_team_id' => ['nullable', 'required_if:applies_to_type,team', 'integer'],
            'applies_to_user_id' => ['nullable', 'required_if:applies_to_type,rep', 'exists:users,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $teamId = $this->input('applies_to_sales_team_id');
            if ($teamId && ! SalesTeam::query()->whereKey($teamId)->exists()) {
                $validator->errors()->add('applies_to_sales_team_id', 'The selected sales team is invalid.');
            }
        });
    }
}
