<?php

namespace App\Modules\Purchase\Services;

use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurOrderLine;
use App\Modules\Purchase\Models\PurVendorDocument;
use App\Modules\Purchase\Models\VendorProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EsgComplianceService
{
    /**
     * Computes TKDN local content reporting and vendor compliance document audit (§3M).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getEsgComplianceReport(array $filters = []): array
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

        $pos = (clone $basePoQuery)->with(['supplier:id,name', 'lines.category'])->get();

        $totalSpend = 0.0;
        $totalDeclaredSpend = 0.0;
        $weightedTkdnSum = 0.0;
        $unweightedTkdnSum = 0.0;
        $totalLocalContentValue = 0.0;

        $declaredLinesCount = 0;
        $totalLinesCount = 0;

        $tiers = [
            'high' => ['key' => 'high', 'label' => 'High TKDN (≥ 40%)', 'min' => 40.0, 'count' => 0, 'spend' => 0.0, 'color' => 'emerald'],
            'medium' => ['key' => 'medium', 'label' => 'Moderate TKDN (25% - 39.9%)', 'min' => 25.0, 'count' => 0, 'spend' => 0.0, 'color' => 'amber'],
            'low' => ['key' => 'low', 'label' => 'Low TKDN (< 25%)', 'min' => 0.01, 'count' => 0, 'spend' => 0.0, 'color' => 'blue'],
            'undeclared' => ['key' => 'undeclared', 'label' => 'Undeclared / 0%', 'min' => 0.0, 'count' => 0, 'spend' => 0.0, 'color' => 'slate'],
        ];

        $categoryMap = [];
        $supplierMap = [];
        $lineItems = [];

        foreach ($pos as $po) {
            $supplierId = $po->supplier_id;
            $supplierName = $po->supplier?->name ?? 'Unknown Supplier';

            if (! isset($supplierMap[$supplierId])) {
                $supplierMap[$supplierId] = [
                    'id' => $supplierId,
                    'name' => $supplierName,
                    'total_spend' => 0.0,
                    'declared_spend' => 0.0,
                    'weighted_sum' => 0.0,
                    'unweighted_sum' => 0.0,
                    'total_lines' => 0,
                    'declared_lines' => 0,
                    'local_content_value' => 0.0,
                ];
            }

            foreach ($po->lines as $line) {
                if (! empty($filters['category_id']) && (int) $line->category_id !== (int) $filters['category_id']) {
                    continue;
                }

                $lineTotal = (float) $line->qty_ordered * (float) $line->unit_price + (float) ($line->tax_amount ?? 0);
                $tkdnPct = $line->local_content_pct !== null ? (float) $line->local_content_pct : null;
                $localVal = ($tkdnPct !== null && $tkdnPct > 0) ? round($lineTotal * ($tkdnPct / 100), 2) : 0.0;

                $totalSpend += $lineTotal;
                $totalLinesCount++;

                $supplierMap[$supplierId]['total_spend'] += $lineTotal;
                $supplierMap[$supplierId]['total_lines']++;
                $supplierMap[$supplierId]['local_content_value'] += $localVal;

                $cat = $line->category;
                $catId = $cat?->id ?: 0;
                $catName = $cat?->name ?? 'Uncategorized';

                if (! isset($categoryMap[$catId])) {
                    $categoryMap[$catId] = [
                        'id' => $cat?->id,
                        'name' => $catName,
                        'spend_type' => $cat?->kind ?? 'indirect',
                        'total_spend' => 0.0,
                        'declared_spend' => 0.0,
                        'weighted_sum' => 0.0,
                        'unweighted_sum' => 0.0,
                        'total_lines' => 0,
                        'declared_lines' => 0,
                        'high_tkdn_spend' => 0.0,
                    ];
                }
                $categoryMap[$catId]['total_spend'] += $lineTotal;
                $categoryMap[$catId]['total_lines']++;

                if ($tkdnPct !== null) {
                    $declaredLinesCount++;
                    $totalDeclaredSpend += $lineTotal;
                    $weightedTkdnSum += ($lineTotal * $tkdnPct);
                    $unweightedTkdnSum += $tkdnPct;
                    $totalLocalContentValue += $localVal;

                    $supplierMap[$supplierId]['declared_lines']++;
                    $supplierMap[$supplierId]['declared_spend'] += $lineTotal;
                    $supplierMap[$supplierId]['weighted_sum'] += ($lineTotal * $tkdnPct);
                    $supplierMap[$supplierId]['unweighted_sum'] += $tkdnPct;

                    $categoryMap[$catId]['declared_lines']++;
                    $categoryMap[$catId]['declared_spend'] += $lineTotal;
                    $categoryMap[$catId]['weighted_sum'] += ($lineTotal * $tkdnPct);
                    $categoryMap[$catId]['unweighted_sum'] += $tkdnPct;

                    if ($tkdnPct >= 40.0) {
                        $tiers['high']['count']++;
                        $tiers['high']['spend'] += $lineTotal;
                        $categoryMap[$catId]['high_tkdn_spend'] += $lineTotal;
                    } elseif ($tkdnPct >= 25.0) {
                        $tiers['medium']['count']++;
                        $tiers['medium']['spend'] += $lineTotal;
                    } elseif ($tkdnPct > 0) {
                        $tiers['low']['count']++;
                        $tiers['low']['spend'] += $lineTotal;
                    } else {
                        $tiers['undeclared']['count']++;
                        $tiers['undeclared']['spend'] += $lineTotal;
                    }
                } else {
                    $tiers['undeclared']['count']++;
                    $tiers['undeclared']['spend'] += $lineTotal;
                }

                $lineItems[] = [
                    'id' => $line->id,
                    'po_id' => $po->id,
                    'po_no' => $po->po_no,
                    'po_date' => $po->created_at?->toDateString(),
                    'supplier_name' => $supplierName,
                    'description' => $line->description,
                    'category_name' => $catName,
                    'qty_ordered' => (float) $line->qty_ordered,
                    'unit_price' => (float) $line->unit_price,
                    'line_total' => $lineTotal,
                    'local_content_pct' => $tkdnPct,
                    'local_content_value' => $localVal,
                    'is_compliant' => ($tkdnPct !== null && $tkdnPct >= 40.0),
                ];
            }
        }

        // Tier distribution percentages
        foreach ($tiers as &$tier) {
            $tier['share_pct'] = $totalSpend > 0 ? round(($tier['spend'] / $totalSpend) * 100, 1) : 0;
            $tier['count_share_pct'] = $totalLinesCount > 0 ? round(($tier['count'] / $totalLinesCount) * 100, 1) : 0;
        }
        unset($tier);

        // Category TKDN rollup
        $categoryReport = [];
        foreach ($categoryMap as $cat) {
            $catWeightedAvg = $cat['declared_spend'] > 0 ? round($cat['weighted_sum'] / $cat['declared_spend'], 1) : 0;
            $categoryReport[] = [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'spend_type' => $cat['spend_type'],
                'total_spend' => $cat['total_spend'],
                'declared_spend' => $cat['declared_spend'],
                'avg_tkdn_pct' => $catWeightedAvg,
                'high_tkdn_spend' => $cat['high_tkdn_spend'],
                'high_tkdn_pct' => $cat['total_spend'] > 0 ? round(($cat['high_tkdn_spend'] / $cat['total_spend']) * 100, 1) : 0,
                'coverage_pct' => $cat['total_spend'] > 0 ? round(($cat['declared_spend'] / $cat['total_spend']) * 100, 1) : 0,
            ];
        }
        usort($categoryReport, fn ($a, $b) => $b['total_spend'] <=> $a['total_spend']);

        // Supplier TKDN rollup
        $supplierReport = [];
        foreach ($supplierMap as $sup) {
            $supWeightedAvg = $sup['declared_spend'] > 0 ? round($sup['weighted_sum'] / $sup['declared_spend'], 1) : 0;
            $rating = 'unrated';
            if ($sup['declared_lines'] > 0) {
                if ($supWeightedAvg >= 40.0) {
                    $rating = 'high'; // Standard compliant
                } elseif ($supWeightedAvg >= 25.0) {
                    $rating = 'medium';
                } else {
                    $rating = 'low';
                }
            }

            $supplierReport[] = [
                'id' => $sup['id'],
                'name' => $sup['name'],
                'total_spend' => $sup['total_spend'],
                'declared_spend' => $sup['declared_spend'],
                'total_lines' => $sup['total_lines'],
                'declared_lines' => $sup['declared_lines'],
                'avg_tkdn_pct' => $supWeightedAvg,
                'local_content_value' => $sup['local_content_value'],
                'coverage_pct' => $sup['total_spend'] > 0 ? round(($sup['declared_spend'] / $sup['total_spend']) * 100, 1) : 0,
                'rating' => $rating,
            ];
        }
        usort($supplierReport, fn ($a, $b) => $b['total_spend'] <=> $a['total_spend']);

        // Vendor compliance documents (§3G & §3M)
        $vendors = VendorProfile::query()
            ->with(['partner:id,name', 'documents'])
            ->get();

        $totalVendors = $vendors->count();
        $compliantVendors = 0;
        $expiringSoonVendors = 0;
        $expiredVendors = 0;

        $docValidCount = 0;
        $docExpiringSoonCount = 0;
        $docExpiredCount = 0;
        $expiringDocsList = [];

        foreach ($vendors as $vp) {
            $vDocs = $vp->documents;
            $hasExpired = false;
            $hasExpiringSoon = false;

            foreach ($vDocs as $doc) {
                $status = $doc->status;
                $daysRemaining = $doc->expiry_date ? now()->startOfDay()->diffInDays($doc->expiry_date->startOfDay(), false) : null;

                if ($daysRemaining !== null) {
                    if ($daysRemaining < 0) {
                        $status = 'expired';
                    } elseif ($daysRemaining <= 30) {
                        $status = 'expiring_soon';
                    }
                }

                if ($status === 'expired') {
                    $docExpiredCount++;
                    $hasExpired = true;
                } elseif ($status === 'expiring_soon') {
                    $docExpiringSoonCount++;
                    $hasExpiringSoon = true;
                } else {
                    $docValidCount++;
                }

                if ($status === 'expired' || $status === 'expiring_soon') {
                    $expiringDocsList[] = [
                        'id' => $doc->id,
                        'vendor_profile_id' => $vp->id,
                        'vendor_name' => $vp->partner?->name ?? 'Vendor',
                        'doc_type' => $doc->doc_type,
                        'expiry_date' => $doc->expiry_date?->toDateString(),
                        'days_remaining' => $daysRemaining,
                        'status' => $status,
                    ];
                }
            }

            if ($hasExpired) {
                $expiredVendors++;
            } elseif ($hasExpiringSoon) {
                $expiringSoonVendors++;
            } elseif ($vDocs->isNotEmpty()) {
                $compliantVendors++;
            }
        }

        usort($expiringDocsList, fn ($a, $b) => ($a['days_remaining'] ?? 999) <=> ($b['days_remaining'] ?? 999));

        $weightedAvgTkdn = $totalDeclaredSpend > 0 ? round($weightedTkdnSum / $totalDeclaredSpend, 2) : 0.0;
        $unweightedAvgTkdn = $declaredLinesCount > 0 ? round($unweightedTkdnSum / $declaredLinesCount, 2) : 0.0;

        return [
            'tkdn_summary' => [
                'weighted_average_pct' => $weightedAvgTkdn,
                'unweighted_average_pct' => $unweightedAvgTkdn,
                'total_spend' => $totalSpend,
                'total_declared_spend' => $totalDeclaredSpend,
                'total_local_content_value' => $totalLocalContentValue,
                'tkdn_coverage_pct' => $totalSpend > 0 ? round(($totalDeclaredSpend / $totalSpend) * 100, 1) : 0.0,
                'total_lines' => $totalLinesCount,
                'declared_lines' => $declaredLinesCount,
                'compliant_target_met' => ($weightedAvgTkdn >= 40.0), // Indonesian government 40% threshold
            ],
            'tier_distribution' => array_values($tiers),
            'tkdn_by_category' => $categoryReport,
            'tkdn_by_supplier' => $supplierReport,
            'vendor_compliance_summary' => [
                'total_vendors' => $totalVendors,
                'compliant_vendors_count' => $compliantVendors,
                'expiring_soon_vendors_count' => $expiringSoonVendors,
                'expired_vendors_count' => $expiredVendors,
                'doc_valid_count' => $docValidCount,
                'doc_expiring_soon_count' => $docExpiringSoonCount,
                'doc_expired_count' => $docExpiredCount,
            ],
            'expiring_documents' => $expiringDocsList,
            'line_items' => array_slice($lineItems, 0, 100), // First 100 lines for register view
            'filter_options' => [
                'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'suppliers' => Partner::query()->whereHas('roles', fn ($q) => $q->where('role_type_id', fn ($sub) => $sub->select('id')->from('CRM.partner_role_types')->where('code', 'VENDOR')))->orderBy('name')->get(['id', 'name']),
            ],
            'active_filters' => [
                'date_range' => $filters['date_range'] ?? 'all',
                'start_date' => $startDate?->toDateString(),
                'end_date' => $endDate?->toDateString(),
                'supplier_id' => $filters['supplier_id'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
            ],
        ];
    }

    /**
     * Resolves date range preset.
     *
     * @param array<string, mixed> $filters
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
            default => [null, null],
        };
    }
}
