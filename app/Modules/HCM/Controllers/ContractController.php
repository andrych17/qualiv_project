<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\HCM\Requests\StoreContractRequest;
use App\Modules\HCM\Services\ContractService;
use App\Modules\HCM\Services\EmployeeService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['start_date', 'end_date', 'contract_type', 'base_salary', 'status'];

    public function __construct(
        protected ContractService $contractService,
        protected EmployeeService $employeeService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'contract_type', 'status', 'employee_id', 'sort', 'direction', 'per_page');

        $query = EmploymentContract::query()
            ->with(['employee.position.job', 'employee.position.orgUnit'])
            ->filter($filters);

        TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'start_date', 'desc');

        $contracts = $query->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15))
            ->withQueryString();

        return Inertia::render('HCM/Contracts/Index', [
            'contracts' => $contracts,
            'filters' => $filters,
            'expiringContracts' => $this->contractService->getExpiringContracts(60),
        ]);
    }

    public function store(StoreContractRequest $request): RedirectResponse
    {
        $this->contractService->create($request->validated());

        return back()->with('success', 'Contract created successfully.');
    }

    public function renew(Request $request, EmploymentContract $contract): RedirectResponse
    {
        $validated = $request->validate([
            'contract_type' => ['required', 'string', 'in:PKWT,PKWTT'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'required_if:contract_type,PKWT', 'date', 'after:start_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'work_location' => ['nullable', 'string', 'max:150'],
            'probation_end_date' => ['nullable', 'date', 'after:start_date'],
        ]);

        $this->contractService->renew($contract, $validated);

        return back()->with('success', 'Contract renewed successfully.');
    }

    public function terminate(EmploymentContract $contract): RedirectResponse
    {
        $this->contractService->terminate($contract);

        return back()->with('success', 'Contract marked as terminated.');
    }
}
