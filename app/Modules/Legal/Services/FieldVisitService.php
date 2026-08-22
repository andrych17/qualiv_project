<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\FieldVisitType;
use RuntimeException;

class FieldVisitService
{
    /** @param  array<string, mixed>  $data */
    public function schedule(array $data): FieldVisit
    {
        $data['status'] = FieldVisit::STATUS_SCHEDULED;

        return FieldVisit::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(FieldVisit $visit, array $data): FieldVisit
    {
        if ($visit->status === FieldVisit::STATUS_COMPLETED) {
            throw new RuntimeException('A completed field visit cannot be edited.');
        }

        $visit->update($data);

        return $visit->refresh();
    }

    /** Mobile check-in — GPS is captured at check-in time (§3M). */
    public function checkIn(FieldVisit $visit, float $lat, float $lng): FieldVisit
    {
        if ($visit->status !== FieldVisit::STATUS_SCHEDULED) {
            throw new RuntimeException('Only a scheduled visit can be checked in.');
        }

        $visit->update([
            'status' => FieldVisit::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
            'gps_lat' => $lat,
            'gps_lng' => $lng,
        ]);

        return $visit->refresh();
    }

    /** @param  list<array{label: string, done: bool, note?: string}>  $checklistResult */
    public function complete(FieldVisit $visit, array $checklistResult, ?string $notes): FieldVisit
    {
        if ($visit->status !== FieldVisit::STATUS_CHECKED_IN) {
            throw new RuntimeException('Only a checked-in visit can be completed.');
        }

        $visit->update([
            'status' => FieldVisit::STATUS_COMPLETED,
            'checklist_result' => $checklistResult,
            'notes' => $notes,
        ]);

        return $visit->refresh();
    }

    public function delete(FieldVisit $visit): void
    {
        $visit->delete();
    }

    /** @return list<array{label: string, done: bool, note: string}> */
    public function blankChecklist(FieldVisitType $type): array
    {
        return collect($type->default_checklist ?? [])
            ->map(fn (string $label) => ['label' => $label, 'done' => false, 'note' => ''])
            ->all();
    }
}
