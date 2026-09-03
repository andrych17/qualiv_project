<?php

namespace App\Modules\PP\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\PP\Models\ChangeoverMatrix;
use App\Modules\PP\Models\ResourceGroupMember;

/**
 * PP_SPECS.md §3J — CRUD over `pp_changeover_matrix` plus the lookup §3I's "minimize setup" /
 * "minimize changeover" strategies consume (`SchedulingRuleService`). The matrix is small,
 * tenant-curated master data (a handful to a few hundred rows), so `lookup()` fetches the
 * candidate rows for one resource and scores them in PHP rather than writing the specificity
 * ordering as SQL — simpler to read and correct for a table this size.
 */
class ChangeoverMatrixService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): ChangeoverMatrix
    {
        return ChangeoverMatrix::query()->create($this->normalize($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(ChangeoverMatrix $row, array $data): ChangeoverMatrix
    {
        $row->update($this->normalize($data));

        return $row->refresh();
    }

    public function delete(ChangeoverMatrix $row): void
    {
        $row->delete();
    }

    /**
     * PP_SPECS.md §3J Rules/Logic — the diagonal is always free (switching a resource to the
     * product it is already running costs nothing, regardless of matrix data), so that case never
     * touches the table. Otherwise: the most specific matching active row wins — an exact
     * from/to-product match beats a from/to-family match beats the `'other'` wildcard family, and
     * a resource-group-specific row beats a matrix-wide (`resource_group_id IS NULL`) one. No
     * matching row is not an error — an unconfigured pair is treated as a free changeover, the
     * same "missing data isn't a blocker" posture `SchedulingRuleService::sortKey()` uses for a
     * missing `need_by_date`.
     *
     * @return array{changeover_minutes: int, cleaning_minutes: int}
     */
    public function lookup(string $resourceType, int $resourceRefId, ?int $fromProductId, ?int $toProductId): array
    {
        if ($fromProductId === $toProductId) {
            return ['changeover_minutes' => 0, 'cleaning_minutes' => 0];
        }

        $groupIds = ResourceGroupMember::query()
            ->where('resource_type', $resourceType)
            ->where('resource_ref_id', $resourceRefId)
            ->pluck('resource_group_id');

        $fromFamily = $fromProductId ? Product::query()->find($fromProductId)?->category?->name : null;
        $toFamily = $toProductId ? Product::query()->find($toProductId)?->category?->name : null;

        $best = null;
        $bestScore = -1;

        ChangeoverMatrix::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('resource_group_id')->orWhereIn('resource_group_id', $groupIds))
            ->get()
            ->each(function (ChangeoverMatrix $row) use (&$best, &$bestScore, $fromProductId, $fromFamily, $toProductId, $toFamily) {
                $fromScore = $this->sideScore($row->from_product_id, $row->from_family, $fromProductId, $fromFamily);
                $toScore = $this->sideScore($row->to_product_id, $row->to_family, $toProductId, $toFamily);
                if ($fromScore === null || $toScore === null) {
                    return;
                }

                $score = $fromScore * 4 + $toScore * 4 + ($row->resource_group_id ? 1 : 0);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $row;
                }
            });

        return [
            'changeover_minutes' => $best->changeover_minutes ?? 0,
            'cleaning_minutes' => $best->cleaning_minutes ?? 0,
        ];
    }

    /** 3 = exact product match, 2 = named-family match, 1 = wildcard family, null = not a candidate. */
    private function sideScore(?int $rowProductId, ?string $rowFamily, ?int $productId, ?string $family): ?int
    {
        if ($rowProductId !== null) {
            return $rowProductId === $productId ? 3 : null;
        }
        if ($rowFamily === ChangeoverMatrix::WILDCARD_FAMILY) {
            return 1;
        }
        if ($family !== null && strcasecmp($rowFamily ?? '', $family) === 0) {
            return 2;
        }

        return null;
    }

    /** A row keys "from"/"to" on product OR family, never both — product wins if the form sent both. */
    private function normalize(array $data): array
    {
        $fromProductId = $data['from_product_id'] ?? null;
        $toProductId = $data['to_product_id'] ?? null;

        return [
            'from_product_id' => $fromProductId,
            'from_family' => $fromProductId ? null : ($data['from_family'] ?? null),
            'to_product_id' => $toProductId,
            'to_family' => $toProductId ? null : ($data['to_family'] ?? null),
            'resource_group_id' => $data['resource_group_id'] ?? null,
            'changeover_minutes' => $data['changeover_minutes'] ?? 0,
            'cleaning_minutes' => $data['cleaning_minutes'] ?? 0,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
