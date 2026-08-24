<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §3N — shared plumbing for the four report controllers (Trial Balance, Balance Sheet, P&L, Cash Flow). */
abstract class BaseReportController extends Controller
{
    /**
     * CSV export — the v1 export format (plain `fputcsv`, no new dependency; opens natively
     * in Excel/Sheets). A polished PDF/XLSX export is a deliberately deferred upgrade, not
     * something this report generation needed to be correct.
     *
     * @param  list<string>  $header
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function csvResponse(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Every period for a company, newest first — reports read closed periods too, unlike DepreciationRunController which only offers open ones for posting. */
    protected function periodOptions(int $companyId): Collection
    {
        return FiscalPeriod::query()->where('company_id', $companyId)->orderByDesc('start_date')
            ->get(['id', 'period_no', 'start_date', 'end_date'])
            ->map(fn (FiscalPeriod $p) => ['value' => $p->id, 'label' => "Period {$p->period_no} ({$p->start_date->toDateString()} – {$p->end_date->toDateString()})"]);
    }

    /**
     * Merges two lists of {account_code, account_name, balance} rows by account_code, summing
     * balance — the "combined" mode building block (§3K rule: simple summation by matching
     * account code, not consolidation with eliminations).
     *
     * @param  list<array{account_code: ?string, account_name: string, balance: float}>  $existing
     * @param  list<array{account_code: ?string, account_name: string, balance: float}>  $incoming
     * @return list<array{account_code: ?string, account_name: string, balance: float}>
     */
    protected function mergeRows(array $existing, array $incoming): array
    {
        $merged = collect($existing)->keyBy(fn ($r) => $r['account_code'] ?? $r['account_name']);

        foreach ($incoming as $row) {
            $key = $row['account_code'] ?? $row['account_name'];
            $current = $merged->get($key, ['account_id' => null, 'account_code' => $row['account_code'], 'account_name' => $row['account_name'], 'balance' => 0.0]);
            $current['balance'] = round($current['balance'] + $row['balance'], 2);
            $merged->put($key, $current);
        }

        return $merged->sortBy(fn ($r) => $r['account_code'] ?? $r['account_name'])->values()->all();
    }
}
