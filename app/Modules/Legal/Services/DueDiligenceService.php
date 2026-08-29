<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\DueDiligenceCheck;
use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\FieldVisitType;
use App\Modules\Legal\Models\LandObject;
use RuntimeException;

class DueDiligenceService
{
    /** The field-visit type an auto-triggered site check is scheduled under (§3I / §3M). */
    private const AUTO_VISIT_TYPE_CODE = 'site_survey';

    public function __construct(
        protected FieldVisitService $fieldVisits,
    ) {}

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
        $check->refresh();

        if ($status === DueDiligenceCheck::STATUS_FLAGGED) {
            $this->triggerFieldVisit($check);
        }

        return $check;
    }

    /**
     * §3I: "Field checks ... are the natural trigger for a Field Visit (3M)." A flagged result
     * needs someone to go verify on site before it can be resolved or overridden, so schedule
     * one automatically — skipped if a visit is already open against this land object, so a
     * re-flag (or multiple checks flagging together) doesn't spam duplicate visits.
     */
    private function triggerFieldVisit(DueDiligenceCheck $check): void
    {
        $type = FieldVisitType::query()->where('code', self::AUTO_VISIT_TYPE_CODE)->where('is_active', true)->first();
        if (! $type) {
            return;
        }

        $alreadyOpen = FieldVisit::query()
            ->where('land_object_id', $check->land_object_id)
            ->whereIn('status', [FieldVisit::STATUS_SCHEDULED, FieldVisit::STATUS_CHECKED_IN])
            ->exists();
        if ($alreadyOpen) {
            return;
        }

        $this->fieldVisits->schedule([
            'land_object_id' => $check->land_object_id,
            'visit_type_id' => $type->id,
            'notes' => "Auto-scheduled: verify flagged due diligence check ({$check->check_type}).",
        ]);
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
