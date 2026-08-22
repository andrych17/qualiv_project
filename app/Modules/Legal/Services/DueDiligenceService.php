<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\DueDiligenceCheck;
use App\Modules\Legal\Models\LandObject;
use RuntimeException;

class DueDiligenceService
{
    public function addCheck(LandObject $landObject, string $checkType): DueDiligenceCheck
    {
        return DueDiligenceCheck::query()->create([
            'land_object_id' => $landObject->id,
            'check_type' => $checkType,
            'status' => DueDiligenceCheck::STATUS_PENDING,
        ]);
    }

    public function recordResult(DueDiligenceCheck $check, string $status, ?string $notes, int $checkedBy): DueDiligenceCheck
    {
        if (! in_array($status, [DueDiligenceCheck::STATUS_CLEAR, DueDiligenceCheck::STATUS_FLAGGED], true)) {
            throw new RuntimeException('A check result must be recorded as clear or flagged.');
        }

        $check->update([
            'status' => $status,
            'result_notes' => $notes,
            'checked_by' => $checkedBy,
            'checked_at' => now(),
        ]);

        return $check->refresh();
    }

    /**
     * §3I — a flagged check blocks the linked deed(s) from signing until resolved or
     * explicitly overridden with a logged justification; never silent.
     */
    public function override(DueDiligenceCheck $check, string $justification, int $overriddenBy): DueDiligenceCheck
    {
        if (! $check->isBlocking()) {
            throw new RuntimeException('Only a currently-blocking, non-overridden check can be overridden.');
        }

        $check->update([
            'overridden_by' => $overriddenBy,
            'overridden_at' => now(),
            'override_justification' => $justification,
        ]);

        return $check->refresh();
    }
}
