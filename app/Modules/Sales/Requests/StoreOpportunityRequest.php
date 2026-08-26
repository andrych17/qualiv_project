<?php

namespace App\Modules\Sales\Requests;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'customer_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'stage' => ['required', 'in:'.implode(',', Opportunity::STAGES)],
            'owner_id' => ['nullable', 'exists:users,id'],
            'sales_team_id' => ['nullable', 'integer'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'loss_reason' => ['nullable', 'required_if:stage,lost', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $customerId = $this->input('customer_id');
            if ($customerId && ! Partner::query()->whereKey($customerId)->exists()) {
                $validator->errors()->add('customer_id', 'The selected customer is invalid.');
            }

            $leadId = $this->input('lead_id');
            if ($leadId && ! Lead::query()->whereKey($leadId)->exists()) {
                $validator->errors()->add('lead_id', 'The selected lead is invalid.');
            }

            $teamId = $this->input('sales_team_id');
            if ($teamId && ! SalesTeam::query()->whereKey($teamId)->exists()) {
                $validator->errors()->add('sales_team_id', 'The selected sales team is invalid.');
            }
        });
    }
}
