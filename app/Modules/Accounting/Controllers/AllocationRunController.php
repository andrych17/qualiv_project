<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\AllocationRule;
use App\Modules\Accounting\Models\AllocationRun;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Requests\StoreAllocationRunRequest;
use App\Modules\Accounting\Services\AllocationRunService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3I — running a rule for a period: preview first (read-only), then a confirmed run posts the journal. Run history is listed here too, since it's the natural place to see what's already been done. */
class AllocationRunController extends Controller
{
    public function __construct(private readonly AllocationRunService $service) {}

    public function show(Request $request, AllocationRule $rule): Response
    {
        $rule->load(['sourceAccount:id,account_code,account_name', 'sourceCostCenter:id,code,name', 'targets.costCenter:id,code,name']);

        // Full rows, not a display-only column select — $selectedPeriod is passed into
        // AllocationRunService::run(), which needs end_date (journal_date) and every other
        // column, not just what this page happens to render.
        $periods = FiscalPeriod::query()
            ->where('company_id', $rule->company_id)
            ->orderByDesc('start_date')
            ->get();

        $selectedPeriodId = $request->integer('fiscal_period_id') ?: $periods->first()?->id;
        $selectedPeriod = $selectedPeriodId ? $periods->firstWhere('id', $selectedPeriodId) : null;

        $alreadyRun = $selectedPeriod
            ? AllocationRun::query()->where('allocation_rule_id', $rule->id)->where('fiscal_period_id', $selectedPeriod->id)->exists()
            : false;

        $preview = ($selectedPeriod && ! $alreadyRun)
            ? $this->service->preview($rule, $selectedPeriod)
            : null;

        $costCenterLabel = fn (int $costCenterId) => $rule->targets->firstWhere('cost_center_id', $costCenterId)?->costCenter;

        return Inertia::render('Accounting/AllocationRules/Run', [
            'rule' => [
                'id' => $rule->id,
                'name' => $rule->name,
                'source_account' => "{$rule->sourceAccount->account_code} — {$rule->sourceAccount->account_name}",
                'source_cost_center' => $rule->sourceCostCenter ? "{$rule->sourceCostCenter->code} {$rule->sourceCostCenter->name}" : 'Unassigned (no cost center)',
            ],
            'periods' => $periods->map(fn (FiscalPeriod $p) => ['value' => $p->id, 'label' => 'Period '.$p->period_no.' ('.$p->start_date->format('M Y').')']),
            'selectedPeriodId' => $selectedPeriodId,
            'alreadyRun' => $alreadyRun,
            'preview' => $preview ? [
                'sourceAmount' => $preview['sourceAmount'],
                'lines' => collect($preview['lines'])->map(fn ($l) => [
                    'cost_center' => optional($costCenterLabel($l['cost_center_id']))->code.' '.optional($costCenterLabel($l['cost_center_id']))->name,
                    'amount' => $l['amount'],
                ]),
            ] : null,
            'runs' => AllocationRun::query()
                ->where('allocation_rule_id', $rule->id)
                ->with(['fiscalPeriod:id,period_no', 'journal:id'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (AllocationRun $run) => [
                    'id' => $run->id,
                    'period_no' => $run->fiscalPeriod->period_no,
                    'source_amount' => (float) $run->source_amount,
                    'journal_id' => $run->journal_id,
                    'created_at' => $run->created_at->format('d M Y H:i'),
                ]),
        ]);
    }

    public function store(StoreAllocationRunRequest $request, AllocationRule $rule)
    {
        $period = FiscalPeriod::findOrFail($request->validated('fiscal_period_id'));
        $run = $this->service->run($rule, $period, $request->user()->id);

        return redirect()->route('accounting.journals.show', $run->journal_id)->with('success', 'Allocation run posted.');
    }
}
