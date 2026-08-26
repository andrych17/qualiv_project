<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\Contract;
use App\Modules\Sales\Models\ContractSubscription;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Requests\StoreContractRequest;
use App\Modules\Sales\Services\BillingService;
use App\Modules\Sales\Services\ContractService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function __construct(
        protected ContractService $contractService,
        protected BillingService $billingService,
    ) {}

    public function index(Request $request): Response
    {
        $contracts = Contract::with(['customer', 'subscriptions', 'priceList'])
            ->when($request->search, function ($q, $s) {
                $q->where('name', 'ilike', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$s}%"));
            })
            ->when($request->status, fn ($q, $st) => $q->where('status', $st))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sales/Contracts/Index', [
            'contracts' => $contracts,
            'statuses' => Contract::STATUSES,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Contracts/Create', [
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'priceLists' => PriceList::query()->where('is_active', true)->select(['id', 'name'])->get(),
            'intervals' => ContractSubscription::INTERVALS,
        ]);
    }

    public function store(StoreContractRequest $request): RedirectResponse
    {
        $contract = $this->contractService->create($request->validated(), $request->user()?->id);

        return redirect()->route('sales.contracts.show', $contract)
            ->with('success', 'Contract draft created.');
    }

    public function show(Contract $contract): Response
    {
        $contract->load(['customer', 'subscriptions.recurringSchedules', 'priceList', 'creator']);

        return Inertia::render('Sales/Contracts/Show', [
            'contract' => $contract,
        ]);
    }

    public function edit(Contract $contract): Response
    {
        $contract->load(['customer', 'subscriptions']);

        return Inertia::render('Sales/Contracts/Edit', [
            'contract' => $contract,
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'priceLists' => PriceList::query()->where('is_active', true)->select(['id', 'name'])->get(),
            'intervals' => ContractSubscription::INTERVALS,
        ]);
    }

    public function update(StoreContractRequest $request, Contract $contract): RedirectResponse
    {
        $this->contractService->update($contract, $request->validated());

        return redirect()->route('sales.contracts.show', $contract)
            ->with('success', 'Contract updated.');
    }

    public function activate(Contract $contract): RedirectResponse
    {
        $this->contractService->activate($contract);

        return back()->with('success', 'Contract activated and recurring billing schedules generated.');
    }

    public function cancel(Contract $contract): RedirectResponse
    {
        $this->contractService->cancel($contract);

        return back()->with('success', 'Contract cancelled.');
    }

    public function renew(Request $request, Contract $contract): RedirectResponse
    {
        $request->validate(['term_end' => ['required', 'date', 'after:'.$contract->term_end]]);
        $this->contractService->renew($contract, $request->term_end);

        return back()->with('success', 'Contract renewed.');
    }

    public function triggerRecurringBilling(): RedirectResponse
    {
        $count = $this->billingService->processRecurringSchedules();

        return back()->with('success', "Processed {$count} due recurring billing schedules.");
    }
}
