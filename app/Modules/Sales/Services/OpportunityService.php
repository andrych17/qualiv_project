<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Events\OpportunityWon;
use App\Modules\Sales\Models\Opportunity;
use Illuminate\Validation\ValidationException;

class OpportunityService
{
    public function create(array $data, ?int $userId): Opportunity
    {
        $stage = $data['stage'] ?? Opportunity::STAGE_NEW;

        if ($stage === Opportunity::STAGE_LOST && empty($data['loss_reason'])) {
            throw ValidationException::withMessages([
                'loss_reason' => ['A loss reason is required when an opportunity is marked as lost.'],
            ]);
        }

        return Opportunity::create([
            'name' => $data['name'],
            'customer_id' => $data['customer_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'stage' => $stage,
            'owner_id' => $data['owner_id'] ?? $userId,
            'sales_team_id' => $data['sales_team_id'] ?? null,
            'estimated_value' => $data['estimated_value'] ?? null,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'loss_reason' => $data['loss_reason'] ?? null,
        ]);
    }

    public function update(Opportunity $opportunity, array $data): Opportunity
    {
        $stage = $data['stage'] ?? $opportunity->stage;

        if ($stage === Opportunity::STAGE_LOST && empty($data['loss_reason']) && empty($opportunity->loss_reason)) {
            throw ValidationException::withMessages([
                'loss_reason' => ['A loss reason is required when an opportunity is marked as lost.'],
            ]);
        }

        $wasWon = $opportunity->stage === Opportunity::STAGE_WON;

        $opportunity->update([
            'name' => $data['name'] ?? $opportunity->name,
            'customer_id' => array_key_exists('customer_id', $data) ? $data['customer_id'] : $opportunity->customer_id,
            'lead_id' => array_key_exists('lead_id', $data) ? $data['lead_id'] : $opportunity->lead_id,
            'stage' => $stage,
            'owner_id' => array_key_exists('owner_id', $data) ? $data['owner_id'] : $opportunity->owner_id,
            'sales_team_id' => array_key_exists('sales_team_id', $data) ? $data['sales_team_id'] : $opportunity->sales_team_id,
            'estimated_value' => array_key_exists('estimated_value', $data) ? $data['estimated_value'] : $opportunity->estimated_value,
            'expected_close_date' => array_key_exists('expected_close_date', $data) ? $data['expected_close_date'] : $opportunity->expected_close_date,
            'loss_reason' => $stage === Opportunity::STAGE_LOST ? ($data['loss_reason'] ?? $opportunity->loss_reason) : null,
        ]);

        if (! $wasWon && $stage === Opportunity::STAGE_WON) {
            event(new OpportunityWon($opportunity));
        }

        return $opportunity->refresh()->load(['customer', 'lead', 'owner', 'salesTeam']);
    }

    public function updateStage(Opportunity $opportunity, string $newStage, ?string $lossReason = null): Opportunity
    {
        return $this->update($opportunity, [
            'stage' => $newStage,
            'loss_reason' => $lossReason,
        ]);
    }
}
