<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\Scorecard;
use App\Modules\Performance\Models\ScorecardItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3F Scorecard Builder — header + items, always fully editable (no draft/status ladder; a
 * Scorecard is a view/composition, not a transaction). Two rules enforced here, neither as a DB
 * constraint:
 * - Each item links to exactly one of `kpi_id`/`okr_id` (`assertXor()`) — same precedent as
 *   §3H Forecast's budget_id/kpi_id.
 * - Every perspective's items must weight to 100% (`assertWeightsSum100()`), per §3F's own
 *   "must sum to 100% per perspective, validated on save."
 */
class ScorecardService
{
    private const EPSILON = 0.01;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Scorecard
    {
        $items = $data['items'] ?? [];
        $this->assertItemsValid($items);

        return DB::transaction(function () use ($data, $items) {
            $scorecard = Scorecard::query()->create([
                ...$this->headerAttributes($data),
                'created_by' => auth()->id(),
            ]);
            $this->syncItems($scorecard, $items);

            return $scorecard->load('items');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Scorecard $scorecard, array $data): Scorecard
    {
        $items = $data['items'] ?? [];
        $this->assertItemsValid($items);

        return DB::transaction(function () use ($scorecard, $data, $items) {
            $scorecard->update($this->headerAttributes($data));
            $this->syncItems($scorecard, $items);

            return $scorecard->refresh()->load('items');
        });
    }

    public function delete(Scorecard $scorecard): void
    {
        $scorecard->delete();
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function assertItemsValid(array $items): void
    {
        $weightsByPerspective = [];

        foreach ($items as $item) {
            $hasKpi = ! empty($item['kpi_id']);
            $hasOkr = ! empty($item['okr_id']);
            if ($hasKpi === $hasOkr) {
                throw ValidationException::withMessages(['items' => 'Each scorecard item must link to exactly one of a KPI or an OKR Objective.']);
            }

            $perspectiveId = $item['perspective_id'];
            $weightsByPerspective[$perspectiveId] = ($weightsByPerspective[$perspectiveId] ?? 0.0) + (float) $item['weight'];
        }

        foreach ($weightsByPerspective as $perspectiveId => $total) {
            if (abs($total - 100.0) > self::EPSILON) {
                throw ValidationException::withMessages(['items' => "Weights for perspective #{$perspectiveId} must sum to 100% (currently {$total}%)."]);
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function headerAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_type'] === Scorecard::SUBJECT_COMPANY ? null : $data['subject_id'],
            'period_id' => $data['period_id'],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function syncItems(Scorecard $scorecard, array $items): void
    {
        $scorecard->items()->delete();

        foreach ($items as $item) {
            ScorecardItem::query()->create([
                'scorecard_id' => $scorecard->id,
                'perspective_id' => $item['perspective_id'],
                'kpi_id' => $item['kpi_id'] ?? null,
                'okr_id' => $item['okr_id'] ?? null,
                'weight' => $item['weight'],
            ]);
        }
    }
}
