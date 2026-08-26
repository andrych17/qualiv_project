<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\DepreciationScheduleCommercial;
use App\Modules\Accounting\Models\DepreciationScheduleFiscal;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FixedAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §3G — the monthly depreciation batch. One row per asset per fiscal period, generated as
 * this actually runs (not pre-computed for an asset's whole life at acquisition — see
 * migration docblock for why: a pre-computed schedule goes silently stale the moment a rate
 * table or method is edited mid-life).
 *
 * Idempotent at the DB level via the unique(asset_id, fiscal_period_id) constraint on both
 * schedule tables, not just a query-then-insert check — re-running a period is safe and only
 * picks up assets that don't have a row yet (e.g. one added after the first run), never
 * double-posts an existing one.
 *
 * Commercial depreciation for the whole batch posts as ONE journal (source: 'asset', so it
 * skips the manual-entry control-account guard — this service is what guarantees correctness,
 * same posture as ArInvoiceService/§3F transfers). One journal rather than one-per-asset
 * because a real monthly depreciation entry reads as a single batch posting in the GL.
 * Fiscal depreciation never posts to GL at all (§3G rule — parallel schedule only, for SPT
 * Tahunan reconciliation).
 *
 * Declining-balance is computed against net book value at (annual rate / 12) each month —
 * pure declining-balance, with no terminal switch to straight-line in an asset's final years
 * (some tax software does this to fully amortize by the nominal useful-life end). Deliberate
 * v1 simplification: 2-decimal rounding self-terminates the schedule once the computed amount
 * rounds to 0.00, which is close enough for a v1 register; document if a tenant needs exact
 * PMK terminal-year behavior later.
 *
 * runForAssets() is the shared core also used by AssetDisposalService to catch up the single
 * period an asset is disposed in, so both paths compute a period identically.
 */
class DepreciationRunService
{
    public function __construct(private readonly JournalService $journalService) {}

    /** @return array{commercialCount:int, fiscalCount:int, journalId:?int} */
    public function runForPeriod(Company $company, FiscalPeriod $period, int $userId): array
    {
        $assets = FixedAsset::query()
            ->where('company_id', $company->id)
            ->where('status', FixedAsset::STATUS_ACTIVE)
            ->where('acquisition_date', '<=', $period->end_date)
            ->with('assetGroup')
            ->get();

        return $this->runForAssets($assets, $period, $userId);
    }

    /**
     * @param  Collection<int, FixedAsset>  $assets
     * @return array{commercialCount:int, fiscalCount:int, journalId:?int}
     */
    public function runForAssets(Collection $assets, FiscalPeriod $period, int $userId): array
    {
        return DB::transaction(function () use ($assets, $period, $userId) {
            $assets->each->loadMissing('assetGroup');

            $alreadyCommercial = DepreciationScheduleCommercial::query()
                ->where('fiscal_period_id', $period->id)->whereIn('asset_id', $assets->pluck('id'))->pluck('asset_id');
            $alreadyFiscal = DepreciationScheduleFiscal::query()
                ->where('fiscal_period_id', $period->id)->whereIn('asset_id', $assets->pluck('id'))->pluck('asset_id');

            // Pass 1: compute in memory — nothing written yet, since commercial rows need a journal_id that doesn't exist until the batch journal is created.
            $commercialItems = [];
            foreach ($assets as $asset) {
                if ($alreadyCommercial->contains($asset->id)) {
                    continue;
                }
                $prior = $this->priorAccumulated(DepreciationScheduleCommercial::class, $asset, $period);
                $amount = $this->computeAmount($asset, $prior, $asset->commercial_method, (float) ($asset->commercial_declining_rate ?? 0), $asset->commercial_useful_life_months);
                if ($amount > 0.0) {
                    $commercialItems[] = ['asset' => $asset, 'amount' => $amount, 'accumulated' => round($prior + $amount, 2)];
                }
            }

            $journalId = null;
            if (! empty($commercialItems)) {
                $lines = [];
                foreach ($commercialItems as $item) {
                    /** @var FixedAsset $asset */
                    $asset = $item['asset'];
                    $lines[] = ['account_id' => $asset->depreciation_expense_gl_account_id, 'debit' => $item['amount'], 'description' => "Depreciation — {$asset->asset_no} {$asset->name}"];
                    $lines[] = ['account_id' => $asset->accumulated_depreciation_gl_account_id, 'credit' => $item['amount'], 'description' => "Depreciation — {$asset->asset_no} {$asset->name}"];
                }

                $journal = $this->journalService->create(
                    ['company_id' => $period->company_id, 'fiscal_period_id' => $period->id, 'journal_date' => $period->end_date->toDateString(), 'currency_code' => $period->company->base_currency, 'memo' => "Depreciation — period {$period->period_no}"],
                    $lines,
                    $userId,
                    'asset',
                );
                $journal = $this->journalService->post($journal, $userId);
                $journalId = $journal->id;

                foreach ($commercialItems as $item) {
                    /** @var FixedAsset $asset */
                    $asset = $item['asset'];
                    DepreciationScheduleCommercial::query()->create([
                        'asset_id' => $asset->id, 'fiscal_period_id' => $period->id,
                        'depreciation_amount' => $item['amount'], 'accumulated_depreciation' => $item['accumulated'],
                        'net_book_value' => round((float) $asset->acquisition_cost - $item['accumulated'], 2),
                        'journal_id' => $journalId, 'created_at' => now(),
                    ]);
                }
            }

            $fiscalCount = 0;
            foreach ($assets as $asset) {
                if ($alreadyFiscal->contains($asset->id)) {
                    continue;
                }
                $group = $asset->assetGroup;
                $prior = $this->priorAccumulated(DepreciationScheduleFiscal::class, $asset, $period);
                $amount = $this->computeAmount($asset, $prior, $asset->fiscal_method, (float) ($group->fiscal_declining_rate ?? 0), $group->fiscal_useful_life_months);
                if ($amount > 0.0) {
                    $accumulated = round($prior + $amount, 2);
                    DepreciationScheduleFiscal::query()->create([
                        'asset_id' => $asset->id, 'fiscal_period_id' => $period->id,
                        'depreciation_amount' => $amount, 'accumulated_depreciation' => $accumulated,
                        'net_book_value' => round((float) $asset->acquisition_cost - $accumulated, 2),
                        'created_at' => now(),
                    ]);
                    $fiscalCount++;
                }
            }

            return ['commercialCount' => count($commercialItems), 'fiscalCount' => $fiscalCount, 'journalId' => $journalId];
        });
    }

    /** Straight-line: cost / useful-life-months, flat every period. Declining-balance: (annual rate / 12) × NBV at the start of the period. Both capped at the remaining book value. */
    private function computeAmount(FixedAsset $asset, float $priorAccumulated, string $method, float $annualRate, int $usefulLifeMonths): float
    {
        $remaining = round((float) $asset->acquisition_cost - $priorAccumulated, 2);
        if ($remaining <= 0.0) {
            return 0.0;
        }

        $raw = $method === FixedAsset::METHOD_DECLINING_BALANCE
            ? $remaining * ($annualRate / 12)
            : ($usefulLifeMonths > 0 ? (float) $asset->acquisition_cost / $usefulLifeMonths : 0.0);

        return min(round($raw, 2), $remaining);
    }

    /** The latest schedule row (by the period it belongs to, not insertion order) strictly before $period — small row counts per asset, so sorting in PHP over an eager-loaded relation is simpler than a cross-table SQL ORDER BY. */
    private function priorAccumulated(string $modelClass, FixedAsset $asset, FiscalPeriod $period): float
    {
        $latest = $modelClass::query()
            ->where('asset_id', $asset->id)
            ->with('fiscalPeriod:id,start_date')
            ->get()
            ->filter(fn ($row) => $row->fiscalPeriod->start_date->lt($period->start_date))
            ->sortByDesc(fn ($row) => $row->fiscalPeriod->start_date)
            ->first();

        return $latest ? (float) $latest->accumulated_depreciation : 0.0;
    }
}
