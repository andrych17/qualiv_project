<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AllocationRule;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Requests\StoreAllocationRuleRequest;
use App\Modules\Accounting\Requests\UpdateAllocationRuleRequest;
use App\Modules\Accounting\Services\AllocationRuleService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3I — allocation rule CRUD. Running a rule for a period is AllocationRunController's job, not this one. */
class AllocationRuleController extends Controller
{
    public function __construct(private readonly AllocationRuleService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $rules = AllocationRule::query()
            ->where('company_id', $companyId)
            ->with(['sourceAccount:id,account_code,account_name', 'sourceCostCenter:id,code,name'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Accounting/AllocationRules/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'rules' => $rules->map(fn (AllocationRule $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'source_account' => "{$r->sourceAccount->account_code} — {$r->sourceAccount->account_name}",
                'source_cost_center' => $r->sourceCostCenter ? "{$r->sourceCostCenter->code} {$r->sourceCostCenter->name}" : 'Unassigned (no cost center)',
                'is_active' => $r->is_active,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/AllocationRules/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            ...$this->formOptions($companyId),
        ]);
    }

    public function store(StoreAllocationRuleRequest $request)
    {
        $data = $request->validated();
        $rule = $this->service->create(
            [
                'company_id' => $data['company_id'],
                'name' => $data['name'],
                'source_account_id' => $data['source_account_id'],
                'source_cost_center_id' => $data['source_cost_center_id'] ?? null,
            ],
            $data['targets'],
            $request->user()->id,
        );

        return redirect()->route('accounting.allocation-rules.edit', $rule)->with('success', 'Allocation rule saved.');
    }

    public function edit(AllocationRule $rule): Response
    {
        $rule->load('targets');

        return Inertia::render('Accounting/AllocationRules/Edit', [
            'rule' => [
                'id' => $rule->id,
                'company_id' => $rule->company_id,
                'name' => $rule->name,
                'source_account_id' => $rule->source_account_id,
                'source_cost_center_id' => $rule->source_cost_center_id,
                'is_active' => $rule->is_active,
                'targets' => $rule->targets->map(fn ($t) => ['cost_center_id' => $t->cost_center_id, 'percentage' => (float) $t->percentage]),
            ],
            ...$this->formOptions($rule->company_id),
        ]);
    }

    public function update(UpdateAllocationRuleRequest $request, AllocationRule $rule)
    {
        $data = $request->validated();
        $this->service->update(
            $rule,
            [
                'name' => $data['name'],
                'source_account_id' => $data['source_account_id'],
                'source_cost_center_id' => $data['source_cost_center_id'] ?? null,
            ],
            $data['targets'],
            $request->user()->id,
        );

        return redirect()->route('accounting.allocation-rules.edit', $rule)->with('success', 'Allocation rule updated.');
    }

    public function setActive(Request $request, AllocationRule $rule)
    {
        $this->service->setActive($rule, $request->boolean('is_active'), $request->user()->id);

        return back()->with('success', $request->boolean('is_active') ? 'Rule resumed.' : 'Rule paused.');
    }

    public function destroy(Request $request, AllocationRule $rule)
    {
        $companyId = $rule->company_id;
        $this->service->delete($rule, $request->user()->id);

        return redirect()->route('accounting.allocation-rules.index', ['company_id' => $companyId])->with('success', 'Allocation rule deleted.');
    }

    private function formOptions(?int $companyId): array
    {
        if (! $companyId) {
            return ['accounts' => [], 'costCenters' => []];
        }

        return [
            'accounts' => Account::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('account_code')
                ->get(['id', 'account_code', 'account_name'])
                ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"]),
            'costCenters' => CostCenter::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (CostCenter $c) => ['value' => $c->id, 'label' => "{$c->code} {$c->name}"]),
        ];
    }
}
