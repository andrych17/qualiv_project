<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\AdjustmentReason;
use Illuminate\Validation\ValidationException;

class AdjustmentReasonService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): AdjustmentReason
    {
        return AdjustmentReason::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(AdjustmentReason $reason, array $data): AdjustmentReason
    {
        $reason->update($this->attributes($data));

        return $reason->refresh();
    }

    public function delete(AdjustmentReason $reason): void
    {
        if (Adjustment::query()->where('reason_id', $reason->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'This reason is used by an existing adjustment — deactivate it instead.']);
        }

        $reason->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
