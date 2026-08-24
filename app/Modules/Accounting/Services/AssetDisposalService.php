<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AssetDisposal;
use App\Modules\Accounting\Models\DepreciationScheduleCommercial;
use App\Modules\Accounting\Models\DepreciationScheduleFiscal;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FixedAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3G — sale/write-off. Depreciates the asset through its disposal month before computing
 * NBV — same "full month, both schedules agree" convention DepreciationRunService uses, via
 * the exact same runForAssets() call (a single-asset collection) so a disposal-period catch-up
 * and a normal batch run can never compute a period differently.
 *
 * v1 simplification: only the disposal period itself is caught up if missing. This assumes
 * prior periods were already run in order (same trust-the-operator posture as the rest of
 * this module) — it does not audit or backfill a real gap left by skipped earlier runs.
 */
class AssetDisposalService
{
    public function __construct(
        private readonly DepreciationRunService $depreciationRunService,
        private readonly JournalService $journalService,
    ) {}

    /** @param  array{disposal_date:string, proceeds:float, proceeds_gl_account_id?:?int, gain_loss_gl_account_id:int, notes?:?string}  $data */
    public function dispose(FixedAsset $asset, array $data, int $userId): AssetDisposal
    {
        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            throw ValidationException::withMessages(['asset' => 'This asset is already disposed.']);
        }

        $proceeds = round((float) $data['proceeds'], 2);
        if ($proceeds > 0 && empty($data['proceeds_gl_account_id'])) {
            throw ValidationException::withMessages(['proceeds_gl_account_id' => 'Required when proceeds are greater than zero.']);
        }

        $period = FiscalPeriod::query()
            ->where('company_id', $asset->company_id)
            ->whereDate('start_date', '<=', $data['disposal_date'])
            ->whereDate('end_date', '>=', $data['disposal_date'])
            ->first();
        if (! $period) {
            throw ValidationException::withMessages(['disposal_date' => 'No fiscal period covers this date.']);
        }

        return DB::transaction(function () use ($asset, $data, $userId, $proceeds, $period) {
            $hasCommercial = DepreciationScheduleCommercial::query()->where('asset_id', $asset->id)->where('fiscal_period_id', $period->id)->exists();
            $hasFiscal = DepreciationScheduleFiscal::query()->where('asset_id', $asset->id)->where('fiscal_period_id', $period->id)->exists();
            if (! $hasCommercial || ! $hasFiscal) {
                $this->depreciationRunService->runForAssets(collect([$asset->loadMissing('assetGroup')]), $period, $userId);
            }

            $commercialNbv = $this->latestNbv(DepreciationScheduleCommercial::class, $asset, $period);
            $fiscalNbv = $this->latestNbv(DepreciationScheduleFiscal::class, $asset, $period);
            $commercialAccumulated = round((float) $asset->acquisition_cost - $commercialNbv, 2);

            $gainLoss = round($proceeds - $commercialNbv, 2);

            $lines = [
                ['account_id' => $asset->accumulated_depreciation_gl_account_id, 'debit' => $commercialAccumulated, 'description' => "Disposal — {$asset->asset_no} {$asset->name}"],
                ['account_id' => $asset->asset_gl_account_id, 'credit' => (float) $asset->acquisition_cost, 'description' => "Disposal — {$asset->asset_no} {$asset->name}"],
            ];
            if ($commercialAccumulated <= 0.0) {
                array_shift($lines); // JournalService rejects a zero-amount line — an asset disposed before ever being depreciated has nothing to debit here
            }
            if ($proceeds > 0) {
                $lines[] = ['account_id' => $data['proceeds_gl_account_id'], 'debit' => $proceeds, 'description' => "Disposal proceeds — {$asset->asset_no}"];
            }
            if ($gainLoss > 0) {
                $lines[] = ['account_id' => $data['gain_loss_gl_account_id'], 'credit' => $gainLoss, 'description' => "Gain on disposal — {$asset->asset_no}"];
            } elseif ($gainLoss < 0) {
                $lines[] = ['account_id' => $data['gain_loss_gl_account_id'], 'debit' => abs($gainLoss), 'description' => "Loss on disposal — {$asset->asset_no}"];
            }

            $journal = $this->journalService->create(
                ['company_id' => $asset->company_id, 'fiscal_period_id' => $period->id, 'journal_date' => $data['disposal_date'], 'currency_code' => $asset->company->base_currency, 'memo' => "Disposal — {$asset->asset_no} {$asset->name}"],
                $lines,
                $userId,
                'asset',
            );
            $journal = $this->journalService->post($journal, $userId);

            $disposal = AssetDisposal::query()->create([
                'asset_id' => $asset->id,
                'disposal_date' => $data['disposal_date'],
                'proceeds' => $proceeds,
                'proceeds_gl_account_id' => $data['proceeds_gl_account_id'] ?? null,
                'gain_loss_gl_account_id' => $data['gain_loss_gl_account_id'],
                'commercial_nbv_at_disposal' => $commercialNbv,
                'fiscal_nbv_at_disposal' => $fiscalNbv,
                'gain_loss_amount' => $gainLoss,
                'notes' => $data['notes'] ?? null,
                'journal_id' => $journal->id,
                'created_by' => $userId,
            ]);

            $asset->update(['status' => FixedAsset::STATUS_DISPOSED]);

            return $disposal;
        });
    }

    /** NBV as of the latest schedule row at or before $period — falls back to full acquisition cost if the asset was never depreciated at all. */
    private function latestNbv(string $modelClass, FixedAsset $asset, FiscalPeriod $period): float
    {
        $latest = $modelClass::query()
            ->where('asset_id', $asset->id)
            ->with('fiscalPeriod:id,start_date')
            ->get()
            ->filter(fn ($row) => $row->fiscalPeriod->start_date->lte($period->start_date))
            ->sortByDesc(fn ($row) => $row->fiscalPeriod->start_date)
            ->first();

        return $latest ? (float) $latest->net_book_value : (float) $asset->acquisition_cost;
    }
}
