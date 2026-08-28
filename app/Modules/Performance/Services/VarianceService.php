<?php

namespace App\Modules\Performance\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\AccountLedgerService;
use App\Modules\Performance\Data\VarianceResult;
use App\Modules\Performance\Models\BudgetActual;
use App\Modules\Performance\Models\BudgetCategoryAccount;
use App\Modules\Performance\Models\BudgetLine;
use App\Modules\Performance\Models\Forecast;
use App\Modules\Performance\Models\ForecastLine;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\KpiValue;
use App\Modules\Performance\Models\Target;
use App\Modules\SysConfig\Services\ConfigService;
use App\Services\TenantFeatureService;

/**
 * §3G Variance Analysis Engine — "the one reusable service every other Form/Engine calls to
 * compare actual vs. plan." `evaluateKpi()` compares a KPI's Target vs. KpiValue (§3C/§3D);
 * `evaluateBudgetLine()` (§3B) compares a BudgetLine's plan vs. its GL-sourced or manual
 * actual. Forecast-line metricRefs are still deferred until §3H exists.
 *
 * Threshold classification differs by shape, not by accident:
 * - KPI (`classifyKpi()`): directional. A `higher_is_better` KPI *exceeding* its target by 20%
 *   must never read as a breach, or beating a revenue target would look identical to badly
 *   missing it — only a shortfall against the KPI's own "better" direction counts against the
 *   thresholds; overachievement is always `on_track`.
 * - Budget (`classifyBudgetVariance()`): symmetric, per §3B's own framing ("within 5% = on
 *   track, 5–15% = warning, >15% = breach"). Under-executing a plan by 30% is as much a
 *   management signal as overspending it by 30%, unlike a KPI target.
 * - Forecast (`evaluateForecastLine()`, reuses `classifyBudgetVariance()`): also symmetric, for
 *   a different reason than Budget — a forecast is a *prediction*, not a commitment, so beating
 *   it by 40% is a forecasting-accuracy problem exactly as much as missing it by 40% would be.
 *   Directional (KPI-style) classification would silently call an inaccurate-but-favorable
 *   forecast "on track," which misreports what Forecast is actually for.
 * All three reuse the same two tenant-configurable threshold consts — a tenant can't yet band
 * KPI, Budget, and Forecast variance differently; revisit if that proves necessary in practice.
 */
class VarianceService
{
    private const EPSILON = 0.00005;

    private const DEFAULT_WARNING_THRESHOLD_PCT = 5.0;

    private const DEFAULT_BREACH_THRESHOLD_PCT = 15.0;

    public function __construct(
        protected ConfigService $config,
        protected TenantFeatureService $features,
        protected AccountLedgerService $ledger,
    ) {}

    /**
     * Compares a KPI's Target (plan) against its KpiValue (actual) for one subject/period.
     * Returns null when either side is missing — variance has nothing to compare yet.
     */
    public function evaluateKpi(string $subjectType, ?int $subjectId, int $kpiId, int $periodId): ?VarianceResult
    {
        $kpi = KpiDefinition::query()->find($kpiId);
        if ($kpi === null) {
            return null;
        }

        $target = Target::query()
            ->where('kpi_id', $kpiId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('period_id', $periodId)
            ->first();

        $value = KpiValue::query()
            ->where('kpi_id', $kpiId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('period_id', $periodId)
            ->first();

        if ($target === null || $value === null) {
            return null;
        }

        return $this->classifyKpi((float) $target->target_value, (float) $value->actual_value, $kpi->direction);
    }

    /**
     * §3B: compares a BudgetLine's `amount_planned` against its resolved actual. Prefers a
     * GL-sourced actual (see resolveGlActual()) when the line's category is mapped and
     * Accounting is installed; falls back to a manually entered `PERF.budget_actuals` row
     * otherwise. Returns null when neither source has anything yet — same "nothing to compare"
     * convention as evaluateKpi().
     */
    public function evaluateBudgetLine(BudgetLine $line): ?VarianceResult
    {
        $line->loadMissing('period');

        $resolved = $this->resolveBudgetActual($line);
        if ($resolved === null) {
            return null;
        }

        return $this->classifyBudgetVariance((float) $line->amount_planned, $resolved['actual'], $resolved['source']);
    }

    /** @return array{actual: float, source: string}|null */
    private function resolveBudgetActual(BudgetLine $line): ?array
    {
        $glActual = $this->resolveGlActual($line);
        if ($glActual !== null) {
            return ['actual' => $glActual, 'source' => VarianceResult::SOURCE_GL];
        }

        $manual = BudgetActual::query()->where('budget_line_id', $line->id)->first();
        if ($manual === null) {
            return null;
        }

        return ['actual' => (float) $manual->actual_value, 'source' => VarianceResult::SOURCE_MANUAL];
    }

    /**
     * §3B: "if Accounting is installed and the category is mapped… reads actual spend for the
     * line's period directly from Accounting." Spec names `AccountingService::getAccountBalance`,
     * but that method returns a cumulative closing balance, not a period total — wrong shape for
     * "spend this month." `AccountLedgerService::forAccountAndPeriod()` is the method that
     * actually exists for this (its own docblock cites this exact use case). `costCenterId` is
     * deliberately null — a budget line has no cost-center concept, so this reads only
     * cost-center-"Unassigned" GL activity, not "any cost center."
     *
     * Company resolution is per mapping row: a row's own `company_id` wins when set (multiple
     * mapped accounts can belong to different companies); otherwise falls back to the single
     * active company if there is exactly one. A row that can't resolve a company, whose account
     * doesn't belong to that company, or whose period has no matching `ACCOUNTING.fiscal_periods`
     * row (exact date-range match — a non-calendar fiscal calendar can legitimately miss here) is
     * skipped rather than failing the whole line; only mapped rows that skip entirely fall back to
     * manual. Multiple resolvable rows for one category are summed.
     */
    private function resolveGlActual(BudgetLine $line): ?float
    {
        if (! $this->features->enabled('ACCOUNTING')) {
            return null;
        }

        $mappings = BudgetCategoryAccount::query()
            ->where('category', $line->category)
            ->where('is_active', true)
            ->get();

        if ($mappings->isEmpty()) {
            return null;
        }

        $sum = 0.0;
        $resolvedAny = false;

        foreach ($mappings as $mapping) {
            $companyId = $mapping->company_id ?? $this->resolveDefaultCompanyId();
            if ($companyId === null) {
                continue;
            }

            $account = Account::query()->where('id', $mapping->account_id)->where('company_id', $companyId)->first();
            if ($account === null) {
                continue;
            }

            $period = FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->where('start_date', $line->period->start_date)
                ->where('end_date', $line->period->end_date)
                ->first();
            if ($period === null) {
                continue;
            }

            $sum += $this->ledger->forAccountAndPeriod($account, $period, null)['periodTotal'];
            $resolvedAny = true;
        }

        return $resolvedAny ? round($sum, 2) : null;
    }

    private function resolveDefaultCompanyId(): ?int
    {
        $activeCompanyIds = Company::query()->where('is_active', true)->pluck('id');

        return $activeCompanyIds->count() === 1 ? $activeCompanyIds->first() : null;
    }

    /**
     * §3H: compares a ForecastLine's `forecast_value` against a comparison value that depends
     * on which of the two mutually-exclusive links the forecast's header carries:
     * - `kpi_id`: the comparison value is that KPI's real recorded actual (`PERF.kpi_values`)
     *   for the same subject/period — a genuine actual-vs-forecast reading.
     * - `budget_id`: the comparison value is the linked Budget's total *planned* amount for
     *   that period (every `BudgetLine.amount_planned` summed, regardless of category) — NOT a
     *   resolved actual. Summing each budget line's own resolved actual (à la
     *   evaluateBudgetLine()) instead was considered and rejected: a budget with several
     *   categories where only some have a GL mapping or manual actual entered would silently
     *   sum to a partial, incomplete total that looks authoritative — exactly the
     *   "never mistake an unreconciled figure for a reconciled one" failure §3B's own
     *   actual-sourcing rule exists to prevent, just reintroduced one level up as a hidden
     *   aggregate. Forecast-vs-budget's-own-planned-total is complete by construction (no
     *   missing categories possible) and still answers a real question ("are we forecasting to
     *   land above or below what was originally budgeted"); true forecast-vs-actual for a
     *   budget-linked series is left to a future Dashboard/Scorecard view that can aggregate
     *   with full cross-category visibility, not duplicated here.
     * Returns null when the comparison value can't be resolved at all (no KpiValue yet; no
     * matching BudgetLine for that period).
     *
     * Uses `classifyBudgetVariance()` (symmetric), not `classifyKpi()` (directional), for both
     * link types — see this class's docblock for why a forecast is scored as a prediction, not
     * a commitment.
     */
    public function evaluateForecastLine(ForecastLine $line): ?VarianceResult
    {
        $line->loadMissing('forecast');
        $forecast = $line->forecast;

        $comparisonValue = $forecast->kpi_id !== null
            ? $this->resolveForecastKpiActual($forecast, $line->period_id)
            : $this->resolveForecastBudgetPlanned($forecast, $line->period_id);

        if ($comparisonValue === null) {
            return null;
        }

        return $this->classifyBudgetVariance((float) $line->forecast_value, $comparisonValue, null);
    }

    private function resolveForecastKpiActual(Forecast $forecast, int $periodId): ?float
    {
        $value = KpiValue::query()
            ->where('kpi_id', $forecast->kpi_id)
            ->where('subject_type', $forecast->subject_type)
            ->where('subject_id', $forecast->subject_id)
            ->where('period_id', $periodId)
            ->first();

        return $value === null ? null : (float) $value->actual_value;
    }

    private function resolveForecastBudgetPlanned(Forecast $forecast, int $periodId): ?float
    {
        $query = BudgetLine::query()->where('budget_id', $forecast->budget_id)->where('period_id', $periodId);

        return $query->exists() ? (float) $query->sum('amount_planned') : null;
    }

    private function classifyBudgetVariance(float $planValue, float $actualValue, ?string $actualSource): VarianceResult
    {
        $varianceAbs = $actualValue - $planValue;

        if (abs($planValue) <= self::EPSILON) {
            $status = abs($varianceAbs) <= self::EPSILON ? VarianceResult::STATUS_ON_TRACK : VarianceResult::STATUS_BREACH;

            return new VarianceResult($planValue, $actualValue, $varianceAbs, null, $status, $actualSource);
        }

        $variancePct = ($varianceAbs / abs($planValue)) * 100;
        $absPct = abs($variancePct);

        $warningThreshold = (float) ($this->config->get('PERFORMANCE', 'VARIANCE_WARNING_THRESHOLD_PCT') ?? self::DEFAULT_WARNING_THRESHOLD_PCT);
        $breachThreshold = (float) ($this->config->get('PERFORMANCE', 'VARIANCE_BREACH_THRESHOLD_PCT') ?? self::DEFAULT_BREACH_THRESHOLD_PCT);

        $status = match (true) {
            $absPct <= $warningThreshold => VarianceResult::STATUS_ON_TRACK,
            $absPct <= $breachThreshold => VarianceResult::STATUS_WARNING,
            default => VarianceResult::STATUS_BREACH,
        };

        return new VarianceResult($planValue, $actualValue, $varianceAbs, $variancePct, $status, $actualSource);
    }

    private function classifyKpi(float $planValue, float $actualValue, string $direction): VarianceResult
    {
        $varianceAbs = $actualValue - $planValue;
        $higherIsBetter = $direction === KpiDefinition::DIRECTION_HIGHER_IS_BETTER;

        if (abs($planValue) <= self::EPSILON) {
            // No meaningful percent base — fall back to a directional sign check, no "warning" band.
            $favorable = $higherIsBetter ? $actualValue >= 0 : $actualValue <= 0;

            return new VarianceResult($planValue, $actualValue, $varianceAbs, null, $favorable ? VarianceResult::STATUS_ON_TRACK : VarianceResult::STATUS_BREACH);
        }

        $variancePct = ($varianceAbs / abs($planValue)) * 100;
        $favorablePct = $higherIsBetter ? $variancePct : -$variancePct;

        $warningThreshold = (float) ($this->config->get('PERFORMANCE', 'VARIANCE_WARNING_THRESHOLD_PCT') ?? self::DEFAULT_WARNING_THRESHOLD_PCT);
        $breachThreshold = (float) ($this->config->get('PERFORMANCE', 'VARIANCE_BREACH_THRESHOLD_PCT') ?? self::DEFAULT_BREACH_THRESHOLD_PCT);

        $status = match (true) {
            $favorablePct >= -$warningThreshold => VarianceResult::STATUS_ON_TRACK,
            $favorablePct >= -$breachThreshold => VarianceResult::STATUS_WARNING,
            default => VarianceResult::STATUS_BREACH,
        };

        return new VarianceResult($planValue, $actualValue, $varianceAbs, $variancePct, $status);
    }
}
