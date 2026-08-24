<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3J Budgeting — one flat annual Budget per company/fiscal year (v1: no revision/scenario
 * versioning, per spec). The grid is edited one cost-center scope at a time (including the
 * "Unassigned" / null scope); saveGrid() replaces that whole scope in one transaction so a
 * cell cleared on the grid is a real delete, not a leftover zero row. See the migration
 * docblock for why there's no DB-level uniqueness on (account_id, cost_center_id,
 * fiscal_period_id) — this replace-scope discipline is what actually enforces it.
 */
class BudgetService
{
    public function getOrCreate(Company $company, FiscalYear $fiscalYear, int $userId): Budget
    {
        $budget = Budget::query()->where('company_id', $company->id)->where('fiscal_year_id', $fiscalYear->id)->first();
        if ($budget) {
            return $budget;
        }

        return DB::transaction(function () use ($company, $fiscalYear, $userId) {
            $budget = Budget::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'fiscal_year_id' => $fiscalYear->id,
                'created_by' => $userId,
            ]);

            AuditLog::record([
                'company_id' => $company->id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.budgets',
                'subject_id' => $budget->id,
                'actor_id' => $userId,
                'after_snapshot' => ['fiscal_year' => $fiscalYear->year],
            ]);

            return $budget;
        });
    }

    /**
     * Replaces every line in this budget's ($costCenterId) scope with $cells — a cell the
     * grid submits as blank is simply absent from $cells, which deletes it. $cells is
     * pre-validated by StoreBudgetGridRequest (account_id belongs to the budget's company,
     * fiscal_period_id belongs to the budget's fiscal year) before this ever runs.
     *
     * @param  list<array{account_id:int, fiscal_period_id:int, amount:float}>  $cells
     */
    public function saveGrid(Budget $budget, ?int $costCenterId, array $cells, int $userId): void
    {
        DB::transaction(function () use ($budget, $costCenterId, $cells, $userId) {
            $scope = fn ($q) => $costCenterId === null ? $q->whereNull('cost_center_id') : $q->where('cost_center_id', $costCenterId);

            $beforeCount = $scope(BudgetLine::query()->where('budget_id', $budget->id))->count();

            $scope(BudgetLine::query()->where('budget_id', $budget->id))->delete();

            $rows = array_map(fn (array $cell) => [
                'budget_id' => $budget->id,
                'account_id' => $cell['account_id'],
                'cost_center_id' => $costCenterId,
                'fiscal_period_id' => $cell['fiscal_period_id'],
                'amount' => $cell['amount'],
            ], $cells);

            if ($rows !== []) {
                BudgetLine::query()->insert($rows);
            }

            AuditLog::record([
                'company_id' => $budget->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.budgets',
                'subject_id' => $budget->id,
                'actor_id' => $userId,
                'before_snapshot' => ['cost_center_id' => $costCenterId, 'line_count' => $beforeCount],
                'after_snapshot' => ['cost_center_id' => $costCenterId, 'line_count' => count($rows)],
            ]);
        });
    }

    /**
     * CSV columns: account_code, cost_center_code (blank = unassigned), period_no (1-12),
     * amount. Every row is validated before any write — one bad code fails the whole file
     * (no partial import), since a silently-partial budget is worse than a rejected one.
     * Existing cells named by the file are upserted (not scope-replaced, since one CSV can
     * legitimately span multiple cost centers at once, unlike the single-scope grid).
     *
     * @return array{imported: int}
     */
    public function importCsv(Budget $budget, UploadedFile $file, int $userId): array
    {
        $accounts = Account::query()->where('company_id', $budget->company_id)->get()->keyBy('account_code');
        $costCenters = CostCenter::query()->where('company_id', $budget->company_id)->get()->keyBy('code');
        $periods = FiscalPeriod::query()->where('fiscal_year_id', $budget->fiscal_year_id)->get()->keyBy('period_no');

        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map('trim', fgetcsv($handle, null, ',', '"', '\\') ?: []);

        $errors = [];
        $rows = [];
        $lineNo = 1;

        while (($raw = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($raw === [null] || $raw === []) {
                continue;
            }

            $data = array_combine($header, $raw);
            $accountCode = trim((string) ($data['account_code'] ?? ''));
            $costCenterCode = trim((string) ($data['cost_center_code'] ?? ''));
            $periodNo = trim((string) ($data['period_no'] ?? ''));
            $amount = trim((string) ($data['amount'] ?? ''));

            $account = $accounts->get($accountCode);
            $costCenter = $costCenterCode !== '' ? $costCenters->get($costCenterCode) : null;
            $period = ctype_digit($periodNo) ? $periods->get((int) $periodNo) : null;

            if (! $account) {
                $errors[] = "Row {$lineNo}: unknown account_code \"{$accountCode}\".";
            }
            if ($costCenterCode !== '' && ! $costCenter) {
                $errors[] = "Row {$lineNo}: unknown cost_center_code \"{$costCenterCode}\".";
            }
            if (! $period) {
                $errors[] = "Row {$lineNo}: invalid period_no \"{$periodNo}\" — must be 1-12 within this budget's fiscal year.";
            }
            if (! is_numeric($amount)) {
                $errors[] = "Row {$lineNo}: amount \"{$amount}\" is not numeric.";
            }

            // Every row is checked regardless of earlier failures, so a file that's invalid
            // in two places reports both at once — $rows is discarded entirely below if
            // $errors ends up non-empty for the file as a whole.
            if ($account && $period && is_numeric($amount)) {
                $rows[] = [
                    'account_id' => $account->id,
                    'cost_center_id' => $costCenter?->id,
                    'fiscal_period_id' => $period->id,
                    'amount' => (float) $amount,
                ];
            }
        }
        fclose($handle);

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        DB::transaction(function () use ($budget, $rows, $userId) {
            foreach ($rows as $row) {
                BudgetLine::query()->updateOrCreate(
                    [
                        'budget_id' => $budget->id,
                        'account_id' => $row['account_id'],
                        'cost_center_id' => $row['cost_center_id'],
                        'fiscal_period_id' => $row['fiscal_period_id'],
                    ],
                    ['amount' => $row['amount']],
                );
            }

            AuditLog::record([
                'company_id' => $budget->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.budgets',
                'subject_id' => $budget->id,
                'actor_id' => $userId,
                'after_snapshot' => ['csv_rows_imported' => count($rows)],
            ]);
        });

        return ['imported' => count($rows)];
    }
}
