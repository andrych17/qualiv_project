<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Requests\StoreAccountRequest;
use App\Modules\Accounting\Requests\UpdateAccountRequest;
use App\Modules\Accounting\Services\AccountService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** §3B Chart of Accounts — depth-indented flat listing per company, same convention as DMS's Folder tree. */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->with('parent:id,account_name')
            ->orderBy('account_code')
            ->get();

        return Inertia::render('Accounting/Accounts/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'accounts' => $this->indent($accounts)->map(fn (Account $a) => [
                'id' => $a->id,
                'account_code' => $a->account_code,
                'account_name' => $a->account_name,
                'depth' => $a->depth,
                'account_type' => $a->account_type,
                'normal_balance' => $a->normal_balance,
                'is_control_account' => $a->is_control_account,
                'is_active' => $a->is_active,
            ])->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/Accounts/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'parents' => $this->parentOptions($companyId),
        ]);
    }

    public function store(StoreAccountRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.accounts.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Account created.');
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('Accounting/Accounts/Edit', [
            'account' => $account->only([
                'id', 'company_id', 'account_code', 'account_name', 'account_type',
                'normal_balance', 'parent_account_id', 'is_control_account', 'is_active',
            ]),
            'parents' => $this->parentOptions($account->company_id, $account->id),
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        $this->service->update($account, $request->validated());

        return redirect()->route('accounting.accounts.index', ['company_id' => $account->company_id])
            ->with('success', 'Account updated.');
    }

    public function destroy(Account $account)
    {
        $companyId = $account->company_id;
        $this->service->delete($account);

        return redirect()->route('accounting.accounts.index', ['company_id' => $companyId])
            ->with('success', 'Account deleted.');
    }

    /** §3B: "a starter Indonesian-standard COA ships with the module." */
    public function seedStarterCoa(Company $company)
    {
        $this->service->seedStarterCoa($company);

        return redirect()->route('accounting.accounts.index', ['company_id' => $company->id])
            ->with('success', 'Starter Chart of Accounts created.');
    }

    /** Depth-first order with a `depth` attribute set on each Account, for the indented flat listing. */
    private function indent(Collection $accounts): Collection
    {
        $byParent = $accounts->groupBy('parent_account_id');
        $ordered = collect();

        $walk = function (?int $parentId, int $depth) use (&$walk, &$ordered, $byParent) {
            foreach ($byParent->get($parentId) ?? [] as $account) {
                $account->depth = $depth;
                $ordered->push($account);
                $walk($account->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $ordered;
    }

    /** Flat, depth-indented options excluding $excludeAccountId's own subtree (prevents parent cycles). */
    private function parentOptions(?int $companyId, ?int $excludeAccountId = null): array
    {
        if (! $companyId) {
            return [];
        }

        $accounts = Account::query()->where('company_id', $companyId)->orderBy('account_code')->get(['id', 'account_code', 'account_name', 'parent_account_id']);
        $excludeIds = $excludeAccountId ? $this->subtreeIds($accounts, $excludeAccountId) : [];

        $byParent = $accounts->groupBy('parent_account_id');
        $flatten = function (?int $parentId, int $depth) use (&$flatten, $byParent, $excludeIds) {
            return ($byParent->get($parentId) ?? collect())
                ->reject(fn (Account $a) => in_array($a->id, $excludeIds, true))
                ->flatMap(fn (Account $a) => collect([
                    ['value' => $a->id, 'label' => str_repeat('— ', $depth)."{$a->account_code} {$a->account_name}"],
                ])->concat($flatten($a->id, $depth + 1)));
        };

        return $flatten(null, 0)->values()->all();
    }

    /** @return list<int> $rootId and every descendant id, via a plain BFS over an already-loaded collection. */
    private function subtreeIds(Collection $accounts, int $rootId): array
    {
        $ids = [$rootId];
        $queue = [$rootId];

        while ($queue) {
            $current = array_pop($queue);
            foreach ($accounts->where('parent_account_id', $current) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
