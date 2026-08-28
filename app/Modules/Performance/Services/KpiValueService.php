<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Events\KpiValueRecorded;
use App\Modules\Performance\Models\KpiValue;
use Illuminate\Validation\ValidationException;

/**
 * §3D — MVP manual entry into `perf.kpi_values`. Unlike Target's "must be active" gate (§3C:
 * "A KPI must be active to accept new target assignments"), that spec sentence is scoped to
 * targets only — recording an actual is capturing a fact, not making a new commitment, so an
 * actual can still be entered against a KPI that's since been deactivated.
 */
class KpiValueService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): KpiValue
    {
        $this->assertUnique($data);

        $value = KpiValue::query()->create([
            ...$this->attributes($data),
            'source' => KpiValue::SOURCE_MANUAL,
            'entered_by' => auth()->id(),
            'entered_at' => now(),
        ]);

        KpiValueRecorded::dispatch($value->kpi_id, $value->subject_type, $value->subject_id, $value->period_id);

        return $value;
    }

    /** @param  array<string, mixed>  $data */
    public function update(KpiValue $value, array $data): KpiValue
    {
        $this->assertUnique($data, excludeId: $value->id);

        $value->update([
            ...$this->attributes($data),
            'entered_by' => auth()->id(),
            'entered_at' => now(),
        ]);

        KpiValueRecorded::dispatch($value->kpi_id, $value->subject_type, $value->subject_id, $value->period_id);

        return $value->refresh();
    }

    public function delete(KpiValue $value): void
    {
        $value->delete();
    }

    /**
     * Same NULL-unsafe-index caveat as TargetService::assertUnique() — Postgres treats every
     * NULL `subject_id` (the "company" subject) as distinct, so the DB unique index alone
     * can't catch a duplicate company-level entry.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertUnique(array $data, ?int $excludeId = null): void
    {
        $exists = KpiValue::query()
            ->where('kpi_id', $data['kpi_id'])
            ->where('subject_type', $data['subject_type'])
            ->where('subject_id', $data['subject_id'] ?? null)
            ->where('period_id', $data['period_id'])
            ->when($excludeId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['subject_id' => 'An actual value for this KPI/subject/period already exists — edit it instead.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'kpi_id' => $data['kpi_id'],
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_type'] === 'company' ? null : $data['subject_id'],
            'period_id' => $data['period_id'],
            'actual_value' => $data['actual_value'],
        ];
    }
}
