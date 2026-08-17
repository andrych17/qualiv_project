<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Requests\StoreInvoiceRequest;
use App\Modules\Central\Services\CentralInvoiceService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    private const SORTABLE = ['id', 'tenant_id', 'status', 'due_date', 'amount_total'];

    public function __construct(
        protected CentralInvoiceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('tenant_id', 'status', 'sort', 'direction', 'per_page');

        $invoices = CentralInvoice::query()
            ->with('tenant:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'due_date', 'desc'),
                fn ($query) => $query->orderByDesc('due_date'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        return Inertia::render('Central/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Central/Invoices/Create', [
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Tenant $t) => ['label' => $t->name, 'value' => $t->getKey()])->values(),
            'plans' => CentralPlan::query()->where('is_active', true)->orderBy('code')->get(['code', 'name', 'price_monthly'])
                ->map(fn (CentralPlan $p) => ['label' => "{$p->name} ({$p->code})", 'value' => $p->code, 'price_monthly' => $p->price_monthly])->values(),
        ]);
    }

    public function store(StoreInvoiceRequest $request)
    {
        $this->service->generate($request->validated());

        return redirect()->route('central.invoices.index')->with('success', 'Invoice generated.');
    }

    public function show(CentralInvoice $invoice): Response
    {
        return Inertia::render('Central/Invoices/Show', [
            'invoice' => $invoice->load(['tenant:id,name', 'lines', 'payments']),
        ]);
    }

    public function destroy(CentralInvoice $invoice)
    {
        $this->service->void($invoice);

        return redirect()->route('central.invoices.index')->with('success', 'Invoice voided.');
    }
}
