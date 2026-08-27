<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\CostCenter;
use App\Modules\Purchase\Models\PurBudget;
use App\Modules\Purchase\Models\PurContractHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use Carbon\Carbon;

class SpendAnalyticsService
{
    /**
     * Aggregates spend analytics across suppliers, categories, cost centers, contracts, and time periods (§3J).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getSpendAnalytics(array $filters = []): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($filters);

        $basePoQuery = PurOrderHdr::query()
            ->whereNotIn('status', [PurOrderHdr::STATUS_DRAFT, PurOrderHdr::STATUS_CANCELLED]);

        if ($startDate) {
            $basePoQuery->where('created_at', '>=', $startDate->startOfDay());
        }
        if ($endDate) {
            $basePoQuery->where('created_at', '<=', $endDate->endOfDay());
        }
        if (! empty($filters['supplier_id'])) {
            $basePoQuery->where('supplier_id', (int) $filters['supplier_id']);
        }

        $pos = (clone $basePoQuery)->with(['supplier:id,name', 'lines.category', 'requisition.costCenter'])->get();

        $totalSpend = 0.0;
        $directSpend = 0.0;
        $indirectSpend = 0.0;
        $capexSpend = 0.0;
        $opexSpend = 0.0;
        $onCatalogSpend = 0.0;
        $offCatalogSpend = 0.0;

        $supplierMap = [];
        $categoryMap = [];
        $costCenterMap = [];
        $monthlyMap = [];

        foreach ($pos as $po) {
            $poDate = $po->created_at ? $po->created_at->format('Y-m') : now()->format('Y-m');
            $supplierId = $po->supplier_id;
            $supplierName = $po->supplier?->name ?? 'Unknown Supplier';
            $costCenterId = $po->requisition?->cost_center_id;
            $costCenterName = $po->requisition?->costCenter?->name ?? 'General / Unassigned';
            $costCenterCode = $po->requisition?->costCenter?->code ?? 'GEN';

            if (! isset($supplierMap[$supplierId])) {
                $supplierMap[$supplierId] = [
                    'id' => $supplierId,
                    'name' => $supplierName,
                    'po_count' => 0,
                    'total_spend' => 0.0,
                    'currency_code' => $po->currency_code ?? 'IDR',
                ];
            }
            $supplierMap[$supplierId]['po_count']++;

            if (! isset($monthlyMap[$poDate])) {
                $monthlyMap[$poDate] = [
                    'period' => $poDate,
                    'total_spend' => 0.0,
                    'direct_spend' => 0.0,
                    'indirect_spend' => 0.0,
                    'po_count' => 0,
                ];
            }
            $monthlyMap[$poDate]['po_count']++;

            $ccKey = $costCenterId ?: 0;
            if (! isset($costCenterMap[$ccKey])) {
                $costCenterMap[$ccKey] = [
                    'id' => $costCenterId,
                    'code' => $costCenterCode,
                    'name' => $costCenterName,
                    'total_spend' => 0.0,
                    'po_count' => 0,
                ];
            }
            $costCenterMap[$ccKey]['po_count']++;

            foreach ($po->lines as $line) {
                // Check category filter if provided
                if (! empty($filters['category_id']) && (int) $line->category_id !== (int) $filters['category_id']) {
                    continue;
                }

                $lineTotal = (float) $line->qty_ordered * (float) $line->unit_price + (float) ($line->tax_amount ?? 0);
                $totalSpend += $lineTotal;

                $supplierMap[$supplierId]['total_spend'] += $lineTotal;
                $monthlyMap[$poDate]['total_spend'] += $lineTotal;
                $costCenterMap[$ccKey]['total_spend'] += $lineTotal;

                $cat = $line->category;
                $catId = $cat?->id ?: 0;
                $catName = $cat?->name ?? 'Uncategorized';
                $spendType = $cat?->kind ?? 'indirect'; // direct | indirect
                $capexOpex = $cat?->capex_opex ?? 'opex'; // capex | opex

                if ($spendType === 'direct') {
                    $directSpend += $lineTotal;
                    $monthlyMap[$poDate]['direct_spend'] += $lineTotal;
                } else {
                    $indirectSpend += $lineTotal;
                    $monthlyMap[$poDate]['indirect_spend'] += $lineTotal;
                }

                if ($capexOpex === 'capex') {
                    $capexSpend += $lineTotal;
                } else {
                    $opexSpend += $lineTotal;
                }

                if ($line->catalog_item_id) {
                    $onCatalogSpend += $lineTotal;
                } else {
                    $offCatalogSpend += $lineTotal;
                }

                if (! isset($categoryMap[$catId])) {
                    $categoryMap[$catId] = [
                        'id' => $cat?->id,
                        'name' => $catName,
                        'spend_type' => $spendType,
                        'capex_opex' => $capexOpex,
                        'total_spend' => 0.0,
                        'line_count' => 0,
                    ];
                }
                $categoryMap[$catId]['total_spend'] += $lineTotal;
                $categoryMap[$catId]['line_count']++;
            }
        }

        // Supplier concentration calculations
        $suppliersList = array_values($supplierMap);
        usort($suppliersList, fn ($a, $b) => $b['total_spend'] <=> $a['total_spend']);

        $top5Spend = 0.0;
        $top10Spend = 0.0;
        foreach ($suppliersList as $idx => &$sup) {
            $sup['share_pct'] = $totalSpend > 0 ? round(($sup['total_spend'] / $totalSpend) * 100, 2) : 0;
            if ($idx < 5) {
                $top5Spend += $sup['total_spend'];
            }
            if ($idx < 10) {
                $top10Spend += $sup['total_spend'];
            }
        }
        unset($sup);

        $top5SharePct = $totalSpend > 0 ? round(($top5Spend / $totalSpend) * 100, 1) : 0;
        $top10SharePct = $totalSpend > 0 ? round(($top10Spend / $totalSpend) * 100, 1) : 0;
        $hasHighConcentrationRisk = false;
        if (! empty($suppliersList) && ($suppliersList[0]['share_pct'] >= 40.0)) {
            $hasHighConcentrationRisk = true;
        }

        // Categories list
        $categoriesList = array_values($categoryMap);
        usort($categoriesList, fn ($a, $b) => $b['total_spend'] <=> $a['total_spend']);
        foreach ($categoriesList as &$cat) {
            $cat['share_pct'] = $totalSpend > 0 ? round(($cat['total_spend'] / $totalSpend) * 100, 2) : 0;
        }
        unset($cat);

        // Cost centers with soft budgets
        $costCentersList = array_values($costCenterMap);
        $activeBudgets = PurBudget::query()->get();
        foreach ($costCentersList as &$cc) {
            $ccBudget = $activeBudgets->where('cost_center_id', $cc['id'])->sum('budget_amount');
            $cc['budget_amount'] = (float) $ccBudget;
            $cc['budget_consumed_pct'] = $ccBudget > 0 ? round(($cc['total_spend'] / $ccBudget) * 100, 1) : 0;
            $cc['remaining_budget'] = $ccBudget > 0 ? ($ccBudget - $cc['total_spend']) : null;
        }
        unset($cc);

        // Monthly trends sorted chronologically
        ksort($monthlyMap);
        $monthlyTrendList = array_values($monthlyMap);

        // Contract utilization (§3H & §3J)
        $contracts = PurContractHdr::query()
            ->with('supplier:id,name')
            ->whereIn('status', [PurContractHdr::STATUS_ACTIVE, PurContractHdr::STATUS_RENEWED, PurContractHdr::STATUS_EXPIRING_SOON])
            ->get();

        $contractService = app(ContractService::class);
        $contractList = [];
        $contractCoveredSpend = 0.0;

        foreach ($contracts as $contract) {
            $spend = $contractService->calculateSpend($contract);
            $contractVal = $contract->value !== null ? (float) $contract->value : 0;
            $utilPct = $contractVal > 0 ? round(($spend / $contractVal) * 100, 1) : 0;
            $contractCoveredSpend += $spend;

            $statusHealth = 'normal';
            if ($utilPct >= 100) {
                $statusHealth = 'exceeded';
            } elseif ($utilPct >= 80) {
                $statusHealth = 'warning';
            }

            $contractList[] = [
                'id' => $contract->id,
                'title' => $contract->title,
                'type' => $contract->type,
                'supplier_name' => $contract->supplier?->name ?? '—',
                'contract_value' => $contractVal,
                'spend_amount' => $spend,
                'utilization_pct' => $utilPct,
                'remaining_headroom' => max(0, $contractVal - $spend),
                'health_status' => $statusHealth,
                'end_date' => $contract->end_date->toDateString(),
            ];
        }

        $posCount = $pos->count();
        $avgPoValue = $posCount > 0 ? round($totalSpend / $posCount, 2) : 0;

        return [
            'kpis' => [
                'total_spend' => $totalSpend,
                'pos_count' => $posCount,
                'avg_po_value' => $avgPoValue,
                'direct_spend' => $directSpend,
                'direct_spend_pct' => $totalSpend > 0 ? round(($directSpend / $totalSpend) * 100, 1) : 0,
                'indirect_spend' => $indirectSpend,
                'indirect_spend_pct' => $totalSpend > 0 ? round(($indirectSpend / $totalSpend) * 100, 1) : 0,
                'capex_spend' => $capexSpend,
                'capex_spend_pct' => $totalSpend > 0 ? round(($capexSpend / $totalSpend) * 100, 1) : 0,
                'opex_spend' => $opexSpend,
                'opex_spend_pct' => $totalSpend > 0 ? round(($opexSpend / $totalSpend) * 100, 1) : 0,
                'on_catalog_spend' => $onCatalogSpend,
                'on_catalog_pct' => $totalSpend > 0 ? round(($onCatalogSpend / $totalSpend) * 100, 1) : 0,
                'off_catalog_spend' => $offCatalogSpend,
                'off_catalog_pct' => $totalSpend > 0 ? round(($offCatalogSpend / $totalSpend) * 100, 1) : 0,
                'contract_covered_spend' => $contractCoveredSpend,
                'contract_coverage_pct' => $totalSpend > 0 ? min(100, round(($contractCoveredSpend / $totalSpend) * 100, 1)) : 0,
            ],
            'supplier_concentration' => [
                'top_5_share_pct' => $top5SharePct,
                'top_10_share_pct' => $top10SharePct,
                'high_risk_flag' => $hasHighConcentrationRisk,
                'top_supplier_name' => ! empty($suppliersList) ? $suppliersList[0]['name'] : null,
                'top_supplier_share_pct' => ! empty($suppliersList) ? $suppliersList[0]['share_pct'] : 0,
            ],
            'spend_by_supplier' => $suppliersList,
            'spend_by_category' => $categoriesList,
            'spend_by_cost_center' => $costCentersList,
            'monthly_trend' => $monthlyTrendList,
            'contract_utilization' => $contractList,
            'filter_options' => [
                'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'cost_centers' => CostCenter::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            ],
            'active_filters' => [
                'date_range' => $filters['date_range'] ?? 'all',
                'start_date' => $startDate?->toDateString(),
                'end_date' => $endDate?->toDateString(),
                'supplier_id' => $filters['supplier_id'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'cost_center_id' => $filters['cost_center_id'] ?? null,
            ],
        ];
    }

    /**
     * Resolves start and end Carbon dates based on standard filter presets.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveDateRange(array $filters): array
    {
        $preset = $filters['date_range'] ?? 'all';

        return match ($preset) {
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'last_30_days' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'this_quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'ytd' => [now()->startOfYear(), now()->endOfDay()],
            'last_12_months' => [now()->subMonths(12)->startOfMonth(), now()->endOfDay()],
            'custom' => [
                ! empty($filters['start_date']) ? Carbon::parse($filters['start_date']) : null,
                ! empty($filters['end_date']) ? Carbon::parse($filters['end_date']) : null,
            ],
            default => [null, null], // 'all'
        };
    }
}
