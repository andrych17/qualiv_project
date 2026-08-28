<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\BudgetLine;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Requests\StoreBudgetRequest;
use App\Modules\Performance\Requests\UpdateBudgetRequest;
use App\Modules\Performance\Services\BudgetService;
use App\Modules\Performance\Services\VarianceService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3B Budgeting (Entry) — header + lines, draft-only mutability, status ladder, versioning. */
class BudgetController extends Controller
{
    private const SORTABLE = ['name', 'fiscal_year', 'status', 'created_at'];

    public function __construct(
        protected BudgetService $service,
        protected VarianceService $variance,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'fiscal_year', 'subject_type', 'sort', 'direction', 'per_page');

        $budgets = Budget::query()
            ->withCount('lines')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Budget $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'subject_label' => $this->subjectLabel($b->subject_type, $b->subject_id),
                'fiscal_label' => $b->fiscal_quarter ? "{$b->fiscal_year} Q{$b->fiscal_quarter}" : (string) $b->fiscal_year,
                'status' => $b->status,
                'version_no' => $b->version_no,
                'lines_count' => $b->lines_count,
            ]);

        return Inertia::render('Performance/Budgets/Index', [
            'budgets' => $budgets,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/Budgets/Create', $this->formProps());
    }

    public function store(StoreBudgetRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.budgets.index')->with('success', 'Budget created.');
    }

    public function edit(Budget $budget): Response
    {
        $budget->load(['lines.period', 'lines.actual']);

        return Inertia::render('Performance/Budgets/Edit', [
            ...$this->formProps(),
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'subject_type' => $budget->subject_type,
                'subject_id' => $budget->subject_id,
                'fiscal_year' => $budget->fiscal_year,
                'fiscal_quarter' => $budget->fiscal_quarter,
                'owner_id' => $budget->owner_id,
                'notes' => $budget->notes,
                'status' => $budget->status,
                'version_no' => $budget->version_no,
                'lines' => $budget->lines->map(fn (BudgetLine $line) => $this->lineProps($line)),
            ],
        ]);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget)
    {
        $this->service->update($budget, $request->validated());

        return redirect()->route('performance.budgets.index')->with('success', 'Budget updated.');
    }

    public function destroy(Budget $budget)
    {
        $this->service->delete($budget);

        return redirect()->route('performance.budgets.index')->with('success', 'Budget deleted.');
    }

    public function submit(Budget $budget)
    {
        $this->service->submit($budget);

        return redirect()->route('performance.budgets.edit', $budget)->with('success', 'Budget submitted.');
    }

    public function approve(Budget $budget)
    {
        $this->service->approve($budget);

        return redirect()->route('performance.budgets.edit', $budget)->with('success', 'Budget approved.');
    }

    public function lock(Budget $budget)
    {
        $this->service->lock($budget);

        return redirect()->route('performance.budgets.edit', $budget)->with('success', 'Budget locked.');
    }

    public function newVersion(Budget $budget)
    {
        $newVersion = $this->service->createNewVersion($budget);

        return redirect()->route('performance.budgets.edit', $newVersion)->with('success', 'New draft version created.');
    }

    /** @return array<string, mixed> */
    private function lineProps(BudgetLine $line): array
    {
        $result = $this->variance->evaluateBudgetLine($line);

        return [
            'id' => $line->id,
            'category' => $line->category,
            'period_id' => $line->period_id,
            'period_label' => $line->period?->label,
            'amount_planned' => (float) $line->amount_planned,
            'notes' => $line->notes,
            'manual_actual_value' => $line->actual !== null ? (float) $line->actual->actual_value : null,
            'variance' => $result === null ? null : [
                'actual_value' => $result->actualValue,
                'variance_pct' => $result->variancePct,
                'status' => $result->status,
                'actual_source' => $result->actualSource,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'periods' => Period::query()->where('is_active', true)->orderByDesc('start_date')->get(['id', 'label']),
            'orgUnits' => OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'full_name', 'employee_no']),
        ];
    }

    private function subjectLabel(string $subjectType, ?int $subjectId): string
    {
        return match ($subjectType) {
            Budget::SUBJECT_COMPANY => 'Company',
            Budget::SUBJECT_ORG_UNIT => OrgUnit::query()->find($subjectId)?->name ?? 'Unknown org unit',
            Budget::SUBJECT_EMPLOYEE => Employee::query()->find($subjectId)?->full_name ?? 'Unknown employee',
            default => 'Unknown subject',
        };
    }
}
