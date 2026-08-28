<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Target;
use Illuminate\Validation\ValidationException;

/**
 * §3C: "A KPI must be active to accept new target assignments" — checked here, not just at
 * the UI's option list, since a KPI can be deactivated after a form was opened. Uniqueness
 * (one target per kpi/subject/period) is enforced here rather than relying solely on the DB
 * unique index — Postgres treats every NULL `subject_id` (the "company" subject) as distinct,
 * so the index alone would let duplicate company-level targets through.
 */
class TargetService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Target
    {
        $this->assertKpiActive($data['kpi_id']);
        $this->assertUnique($data);

        return Target::query()->create([...$this->attributes($data), 'created_by' => auth()->id()]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Target $target, array $data): Target
    {
        $this->assertUnique($data, excludeId: $target->id);

        $target->update($this->attributes($data));

        return $target->refresh();
    }

    public function delete(Target $target): void
    {
        $target->delete();
    }

    private function assertKpiActive(int $kpiId): void
    {
        $kpi = KpiDefinition::query()->find($kpiId);
        if ($kpi === null || ! $kpi->is_active) {
            throw ValidationException::withMessages(['kpi_id' => 'This KPI is inactive and can no longer accept new target assignments.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function assertUnique(array $data, ?int $excludeId = null): void
    {
        $exists = Target::query()
            ->where('kpi_id', $data['kpi_id'])
            ->where('subject_type', $data['subject_type'])
            ->where('subject_id', $data['subject_id'] ?? null)
            ->where('period_id', $data['period_id'])
            ->when($excludeId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['subject_id' => 'A target for this KPI/subject/period already exists.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'kpi_id' => $data['kpi_id'],
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_type'] === Target::SUBJECT_COMPANY ? null : $data['subject_id'],
            'period_id' => $data['period_id'],
            'target_value' => $data['target_value'],
            'stretch_value' => $data['stretch_value'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
