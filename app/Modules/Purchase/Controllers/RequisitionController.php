<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\CostCenter;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Requests\StoreRequisitionRequest;
use App\Modules\Purchase\Requests\UpdateRequisitionRequest;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\RequisitionService;
use App\Modules\Purchase\Services\VendorProfileService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionController extends Controller
{
    public function __construct(
        protected RequisitionService $service,
        protected PurchaseOrderService $poService,
        protected VendorProfileService $vendorService,
    ) {}

    public function index(): Response
    {
        $requisitions = PurRequisitionHdr::query()
            ->with(['requester:id,name', 'costCenter:id,code,name'])
            ->withCount('lines')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurRequisitionHdr $pr) => [
                'id' => $pr->id,
                'uuid' => $pr->uuid,
                'pr_no' => $pr->pr_no,
                'requester_name' => $pr->requester?->name,
                'cost_center_name' => $pr->costCenter ? "{$pr->costCenter->code} - {$pr->costCenter->name}" : null,
                'needed_by' => $pr->needed_by?->toDateString(),
                'status' => $pr->status,
                'estimated_total' => (float) $pr->estimated_total,
                'budget_warning' => $pr->budget_warning,
                'duplicate_warning' => $pr->duplicate_warning,
                'lines_count' => $pr->lines_count,
                'created_at' => $pr->created_at?->toDateString(),
            ]);

        return Inertia::render('Purchase/Requisitions/Index', [
            'requisitions' => $requisitions,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchase/Requisitions/Create', [
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capex_opex']),
            'catalogItems' => PurCatalogItem::query()->where('is_active', true)->orderBy('description')->get([
                'id', 'item_code', 'description', 'negotiated_price', 'category_id', 'unit',
            ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(StoreRequisitionRequest $request)
    {
        $pr = $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('purchase.requisitions.show', $pr->id)->with('success', "Requisition {$pr->pr_no} created.");
    }

    public function show(PurRequisitionHdr $requisition): Response
    {
        $requisition->load([
            'requester:id,name,email',
            'costCenter:id,code,name',
            'creator:id,name',
            'lines.catalogItem:id,item_code,description,unit',
            'lines.category:id,name,kind,capex_opex',
            'orders:id,po_no,status,total_amount',
        ]);

        return Inertia::render('Purchase/Requisitions/Show', [
            'requisition' => [
                'id' => $requisition->id,
                'uuid' => $requisition->uuid,
                'pr_no' => $requisition->pr_no,
                'requester' => $requisition->requester ? ['id' => $requisition->requester->id, 'name' => $requisition->requester->name, 'email' => $requisition->requester->email] : null,
                'cost_center' => $requisition->costCenter ? ['id' => $requisition->costCenter->id, 'code' => $requisition->costCenter->code, 'name' => $requisition->costCenter->name] : null,
                'creator' => $requisition->creator ? ['id' => $requisition->creator->id, 'name' => $requisition->creator->name] : null,
                'needed_by' => $requisition->needed_by?->toDateString(),
                'subject_type' => $requisition->subject_type,
                'subject_id' => $requisition->subject_id,
                'status' => $requisition->status,
                'estimated_total' => (float) $requisition->estimated_total,
                'budget_warning' => $requisition->budget_warning,
                'duplicate_warning' => $requisition->duplicate_warning,
                'notes' => $requisition->notes,
                'created_at' => $requisition->created_at?->toDateTimeString(),
                'lines' => $requisition->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'line_no' => $l->line_no,
                    'catalog_item' => $l->catalogItem ? ['id' => $l->catalogItem->id, 'item_code' => $l->catalogItem->item_code, 'description' => $l->catalogItem->description, 'unit' => $l->catalogItem->unit] : null,
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'estimated_unit_price' => (float) $l->estimated_unit_price,
                    'line_total' => ((float) $l->qty) * ((float) $l->estimated_unit_price),
                    'category' => $l->category ? ['id' => $l->category->id, 'name' => $l->category->name, 'kind' => $l->category->kind] : null,
                    'local_content_pct' => $l->local_content_pct !== null ? (float) $l->local_content_pct : null,
                ]),
                'orders' => $requisition->orders->map(fn ($o) => [
                    'id' => $o->id,
                    'po_no' => $o->po_no,
                    'status' => $o->status,
                    'total_amount' => (float) $o->total_amount,
                ]),
            ],
            'eligiblePartners' => $this->vendorService->eligiblePartners(),
        ]);
    }

    public function edit(PurRequisitionHdr $requisition): Response
    {
        $requisition->load(['lines']);

        return Inertia::render('Purchase/Requisitions/Edit', [
            'requisition' => [
                'id' => $requisition->id,
                'pr_no' => $requisition->pr_no,
                'requester_id' => $requisition->requester_id,
                'cost_center_id' => $requisition->cost_center_id,
                'needed_by' => $requisition->needed_by?->toDateString(),
                'subject_type' => $requisition->subject_type,
                'subject_id' => $requisition->subject_id,
                'status' => $requisition->status,
                'notes' => $requisition->notes,
                'lines' => $requisition->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'catalog_item_id' => $l->catalog_item_id,
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'estimated_unit_price' => (float) $l->estimated_unit_price,
                    'category_id' => $l->category_id,
                    'local_content_pct' => $l->local_content_pct !== null ? (float) $l->local_content_pct : null,
                ]),
            ],
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capex_opex']),
            'catalogItems' => PurCatalogItem::query()->where('is_active', true)->orderBy('description')->get([
                'id', 'item_code', 'description', 'negotiated_price', 'category_id', 'unit',
            ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function update(UpdateRequisitionRequest $request, PurRequisitionHdr $requisition)
    {
        $this->service->update($requisition, $request->validated(), $request->user()->id);

        return redirect()->route('purchase.requisitions.show', $requisition->id)->with('success', 'Requisition updated.');
    }

    public function submit(Request $request, PurRequisitionHdr $requisition)
    {
        $this->service->submit($requisition, $request->user()->id);

        return back()->with('success', 'Requisition submitted for approval.');
    }

    public function approve(Request $request, PurRequisitionHdr $requisition)
    {
        $this->service->approve($requisition, $request->user()->id);

        return back()->with('success', 'Requisition approved.');
    }

    public function reject(Request $request, PurRequisitionHdr $requisition)
    {
        $this->service->reject($requisition, $request->user()->id, $request->input('reason'));

        return back()->with('success', 'Requisition rejected.');
    }

    public function cancel(Request $request, PurRequisitionHdr $requisition)
    {
        $this->service->cancel($requisition, $request->user()->id);

        return back()->with('success', 'Requisition cancelled.');
    }

    public function convertToPo(Request $request, PurRequisitionHdr $requisition)
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'expected_delivery_date' => ['nullable', 'date'],
        ]);

        if (! Partner::query()->whereKey($validated['supplier_id'])->exists()) {
            return back()->withErrors(['supplier_id' => 'The selected supplier is invalid.']);
        }

        $po = $this->poService->createFromRequisition($requisition, $validated, $request->user()->id);

        return redirect()->route('purchase.orders.show', $po->id)->with('success', "Converted to Purchase Order {$po->po_no}.");
    }
}
