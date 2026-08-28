<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\Forecast;
use App\Modules\Performance\Models\ForecastLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3H Forecast — a forecast links to exactly one of a Budget or a KPI (`assertXor()`; no DB
 * CHECK, same precedent as Accounting's `FakturPajakService`). There is no draft/status ladder
 * like Budget: a forecast is immutable once created, and "revising" it (`revise()`) always
 * creates a brand-new version row rather than mutating in place, per §3H's own "same
 * non-destructive history principle as ... Budget locking" — see the migration's docblock for
 * the `root_forecast_id`/`version_no`/`is_latest` versioning scheme.
 */
class ForecastService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Forecast
    {
        $this->assertXor($data);

        return DB::transaction(function () use ($data) {
            $forecast = Forecast::query()->create([
                ...$this->headerAttributes($data),
                'method' => Forecast::METHOD_MANUAL,
                'version_no' => 1,
                'root_forecast_id' => null,
                'is_latest' => true,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($forecast, $data['lines'] ?? []);

            return $forecast->load('lines');
        });
    }

    /**
     * Creates a new version superseding $forecast. Only the current latest version of a series
     * can be revised — revising an old snapshot would fork the linear history this scheme
     * assumes. The old version's `is_latest` flip and the new version's insert happen in one
     * transaction, since `is_latest` is a service-maintained denormalization with no DB
     * constraint backing "exactly one latest per series" (see migration docblock).
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(Forecast $forecast, array $data): Forecast
    {
        if (! $forecast->is_latest) {
            throw ValidationException::withMessages(['id' => 'Only the latest version of a forecast can be revised.']);
        }

        return DB::transaction(function () use ($forecast, $data) {
            $forecast->update(['is_latest' => false]);

            $newVersion = Forecast::query()->create([
                'subject_type' => $forecast->subject_type,
                'subject_id' => $forecast->subject_id,
                'budget_id' => $forecast->budget_id,
                'kpi_id' => $forecast->kpi_id,
                'period_id' => $data['period_id'],
                'method' => Forecast::METHOD_MANUAL,
                'version_no' => $forecast->version_no + 1,
                'root_forecast_id' => $forecast->root_forecast_id ?? $forecast->id,
                'is_latest' => true,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($newVersion, $data['lines'] ?? []);

            return $newVersion->load('lines');
        });
    }

    /** Only a series with no revisions yet (still v1) can be deleted outright — once revised, the history is permanent, same append-only spirit as Budget locking. */
    public function delete(Forecast $forecast): void
    {
        if ($forecast->version_no !== 1 || ! $forecast->is_latest) {
            throw ValidationException::withMessages(['id' => 'A forecast that has been revised can no longer be deleted — its history is permanent.']);
        }

        $forecast->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function assertXor(array $data): void
    {
        $hasBudget = ! empty($data['budget_id']);
        $hasKpi = ! empty($data['kpi_id']);

        if ($hasBudget === $hasKpi) {
            throw ValidationException::withMessages(['budget_id' => 'A forecast must link to exactly one of a Budget or a KPI, not both or neither.']);
        }
    }

    /**
     * When linked to a Budget, subject_type/subject_id are always derived from that Budget
     * (never accepted from input) — a forecast's subject can't legitimately diverge from the
     * budget it's forecasting.
     *
     * @param  array<string, mixed>  $data
     */
    private function headerAttributes(array $data): array
    {
        if (! empty($data['budget_id'])) {
            $budget = Budget::query()->findOrFail($data['budget_id']);

            return [
                'subject_type' => $budget->subject_type,
                'subject_id' => $budget->subject_id,
                'budget_id' => $budget->id,
                'kpi_id' => null,
                'period_id' => $data['period_id'],
                'notes' => $data['notes'] ?? null,
            ];
        }

        return [
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_type'] === Forecast::SUBJECT_COMPANY ? null : $data['subject_id'],
            'budget_id' => null,
            'kpi_id' => $data['kpi_id'],
            'period_id' => $data['period_id'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function syncLines(Forecast $forecast, array $lines): void
    {
        $forecast->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['period_id']) || ! isset($line['forecast_value'])) {
                continue;
            }

            ForecastLine::query()->create([
                'forecast_id' => $forecast->id,
                'period_id' => $line['period_id'],
                'forecast_value' => $line['forecast_value'],
            ]);
        }
    }
}
