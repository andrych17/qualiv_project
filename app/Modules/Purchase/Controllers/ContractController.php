<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\PurContractHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Requests\StoreContractRequest;
use App\Modules\Purchase\Requests\UpdateContractRequest;
use App\Modules\Purchase\Services\ContractService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function __construct(
        protected ContractService $service,
    ) {}

    public function index(): Response
    {
        $contracts = PurContractHdr::query()
            ->with(['supplier:id,name', 'creator:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(function (PurContractHdr $c) {
                $spend = $this->service->calculateSpend($c);

                return [
                    'id' => $c->id,
                    'uuid' => $c->uuid,
                    'title' => $c->title,
                    'type' => $c->type,
                    'supplier_name' => $c->supplier?->name,
                    'value' => $c->value !== null ? (float) $c->value : null,
                    'spend_amount' => $spend,
                    'currency_code' => $c->currency_code,
                    'start_date' => $c->start_date->toDateString(),
                    'end_date' => $c->end_date->toDateString(),
                    'status' => $c->status,
                    'auto_renew' => $c->auto_renew,
                ];
            });

        return Inertia::render('Purchase/Contracts/Index', [
            'contracts' => $contracts,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchase/Contracts/Create', [
            'vendors' => Partner::query()
                ->whereHas('roles', fn ($q) => $q->where('role_type_id', fn ($sub) => $sub->select('id')->from('CRM.partner_role_types')->where('code', 'VENDOR')))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(StoreContractRequest $request)
    {
        $contract = $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('purchase.contracts.show', $contract->id)->with('success', "Contract '{$contract->title}' created.");
    }

    public function show(PurContractHdr $contract): Response
    {
        $contract->load(['supplier:id,name', 'creator:id,name']);
        $spend = $this->service->calculateSpend($contract);

        $relatedOrders = PurOrderHdr::query()
            ->where('supplier_id', $contract->supplier_id)
            ->whereBetween('created_at', [
                $contract->start_date->startOfDay(),
                $contract->end_date->endOfDay(),
            ])
            ->orderByDesc('id')
            ->get(['id', 'po_no', 'status', 'total_amount', 'currency_code', 'created_at'])
            ->map(fn (PurOrderHdr $po) => [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'status' => $po->status,
                'total_amount' => (float) $po->total_amount,
                'currency_code' => $po->currency_code,
                'created_at' => $po->created_at?->toDateString(),
            ]);

        return Inertia::render('Purchase/Contracts/Show', [
            'contract' => [
                'id' => $contract->id,
                'uuid' => $contract->uuid,
                'title' => $contract->title,
                'type' => $contract->type,
                'supplier' => $contract->supplier ? ['id' => $contract->supplier->id, 'name' => $contract->supplier->name] : null,
                'value' => $contract->value !== null ? (float) $contract->value : null,
                'spend_amount' => $spend,
                'spend_pct' => ($contract->value && (float) $contract->value > 0) ? round(($spend / (float) $contract->value) * 100, 1) : 0,
                'currency_code' => $contract->currency_code,
                'start_date' => $contract->start_date->toDateString(),
                'end_date' => $contract->end_date->toDateString(),
                'auto_renew' => $contract->auto_renew,
                'notice_period_days' => $contract->notice_period_days,
                'status' => $contract->status,
                'creator' => $contract->creator ? ['id' => $contract->creator->id, 'name' => $contract->creator->name] : null,
                'created_at' => $contract->created_at?->toDateTimeString(),
            ],
            'relatedOrders' => $relatedOrders,
        ]);
    }

    public function edit(PurContractHdr $contract): Response
    {
        return Inertia::render('Purchase/Contracts/Edit', [
            'contract' => [
                'id' => $contract->id,
                'supplier_id' => $contract->supplier_id,
                'title' => $contract->title,
                'type' => $contract->type,
                'value' => $contract->value !== null ? (float) $contract->value : null,
                'currency_code' => $contract->currency_code,
                'start_date' => $contract->start_date->toDateString(),
                'end_date' => $contract->end_date->toDateString(),
                'auto_renew' => $contract->auto_renew,
                'notice_period_days' => $contract->notice_period_days,
                'status' => $contract->status,
            ],
            'vendors' => Partner::query()
                ->whereHas('roles', fn ($q) => $q->where('role_type_id', fn ($sub) => $sub->select('id')->from('CRM.partner_role_types')->where('code', 'VENDOR')))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(UpdateContractRequest $request, PurContractHdr $contract)
    {
        $this->service->update($contract, $request->validated());

        return redirect()->route('purchase.contracts.show', $contract->id)->with('success', "Contract '{$contract->title}' updated.");
    }

    public function activate(PurContractHdr $contract)
    {
        $this->service->activate($contract);

        return redirect()->back()->with('success', "Contract '{$contract->title}' activated.");
    }

    public function terminate(PurContractHdr $contract)
    {
        $this->service->terminate($contract);

        return redirect()->back()->with('success', "Contract '{$contract->title}' terminated.");
    }

    public function renew(Request $request, PurContractHdr $contract)
    {
        $request->validate([
            'end_date' => ['required', 'date', 'after:start_date'],
            'value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->service->renew($contract, $request->input('end_date'), $request->input('value') ? (float) $request->input('value') : null);

        return redirect()->back()->with('success', "Contract '{$contract->title}' renewed.");
    }
}
