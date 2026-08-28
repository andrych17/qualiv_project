<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Performance\Models\BudgetCategoryAccount;
use App\Modules\Performance\Requests\StoreBudgetCategoryAccountRequest;
use App\Modules\Performance\Requests\UpdateBudgetCategoryAccountRequest;
use App\Modules\Performance\Services\BudgetCategoryAccountService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3B — tenant-editable category → GL account mapping that makes a budget category's lines GL-sourced. */
class BudgetCategoryAccountController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['category', 'created_at'];

    public function __construct(protected BudgetCategoryAccountService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('category', 'sort', 'direction', 'per_page');

        $mappings = BudgetCategoryAccount::query()
            ->with(['account:id,account_code,account_name', 'company:id,legal_name'])
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', 'ilike', "%{$category}%"))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'category'),
                fn ($query) => $query->orderBy('category'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (BudgetCategoryAccount $m) => [
                'id' => $m->id,
                'category' => $m->category,
                'account_label' => $m->account ? "{$m->account->account_code} — {$m->account->account_name}" : null,
                'company_name' => $m->company?->legal_name ?? 'All companies',
                'is_active' => $m->is_active,
            ]);

        return Inertia::render('Performance/BudgetCategoryAccounts/Index', [
            'mappings' => $mappings,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/BudgetCategoryAccounts/Create', $this->formProps());
    }

    public function store(StoreBudgetCategoryAccountRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.budgetCategoryAccounts.index')->with('success', 'Mapping created.');
    }

    public function edit(BudgetCategoryAccount $budgetCategoryAccount): Response
    {
        return Inertia::render('Performance/BudgetCategoryAccounts/Edit', [
            ...$this->formProps(),
            'mapping' => $budgetCategoryAccount->only('id', 'category', 'account_id', 'company_id', 'is_active'),
        ]);
    }

    public function update(UpdateBudgetCategoryAccountRequest $request, BudgetCategoryAccount $budgetCategoryAccount)
    {
        $this->service->update($budgetCategoryAccount, $request->validated());

        return redirect()->route('performance.budgetCategoryAccounts.index')->with('success', 'Mapping updated.');
    }

    public function destroy(BudgetCategoryAccount $budgetCategoryAccount)
    {
        $this->service->delete($budgetCategoryAccount);

        return redirect()->route('performance.budgetCategoryAccounts.index')->with('success', 'Mapping deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, BudgetCategoryAccount::class, fn (BudgetCategoryAccount $m) => $this->service->delete($m));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'accounts' => Account::query()->where('is_active', true)->orderBy('account_code')->get(['id', 'account_code', 'account_name']),
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
        ];
    }
}
