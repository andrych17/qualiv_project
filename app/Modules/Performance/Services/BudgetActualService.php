<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\BudgetActual;
use App\Modules\Performance\Models\BudgetLine;

/**
 * §3B — manual actual entry against a BudgetLine, used only while that line's category isn't
 * GL-mapped (see VarianceService::evaluateBudgetLine(), which always prefers the GL-sourced
 * figure when one resolves). One row per line — `upsert()` below is create-or-update by
 * `budget_line_id`, same "record a fact" posture as KpiValueService, just keyed on the line
 * instead of a kpi/subject/period triple since a BudgetLine already pins exactly one of those.
 */
class BudgetActualService
{
    public function upsert(BudgetLine $line, float $actualValue): BudgetActual
    {
        $actual = BudgetActual::query()->firstOrNew(['budget_line_id' => $line->id]);
        $actual->fill([
            'actual_value' => $actualValue,
            'source' => BudgetActual::SOURCE_MANUAL,
            'entered_by' => auth()->id(),
            'entered_at' => now(),
        ]);
        $actual->save();

        return $actual->refresh();
    }

    public function delete(BudgetActual $actual): void
    {
        $actual->delete();
    }
}
