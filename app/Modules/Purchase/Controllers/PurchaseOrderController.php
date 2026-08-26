<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchase\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\VendorProfileService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $service,
        protected VendorProfileService $vendorService,
    ) {}

    public function index(): Response
    {
        $orders = PurOrderHdr::query()
            ->with(['supplier:id,name', 'requisition:id,pr_no'])
            ->withCount('lines')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurOrderHdr $po) => [
                'id' => $po->id,
                'uuid' => $po->uuid,
                'po_no' => $po->po_no,
                'supplier_name' => $po->supplier?->name,
                'pr_no' => $po->requisition?->pr_no,
                'status' => $po->status,
                'revision_no' => $po->revision_no,
                'currency_code' => $po->currency_code,
                'total_amount' => (float) $po->total_amount,
                'expected_delivery_date' => $po->expected_delivery_date?->toDateString(),
                'ack_status' => $po->ack_status,
                'lines_count' => $po->lines_count,
                'created_at' => $po->created_at?->toDateString(),
            ]);

        return Inertia::render('Purchase/Orders/Index', [
            'orders' => $orders,
        ]);
    }

    public function create(Request $request): Response
    {
        $fromPrId = $request->query('from_pr');
        $initialPr = null;
        if ($fromPrId) {
            $initialPr = PurRequisitionHdr::query()
                ->with(['lines.catalogItem', 'lines.category'])
                ->find($fromPrId);
        }

        return Inertia::render('Purchase/Orders/Create', [
            'eligiblePartners' => $this->vendorService->eligiblePartners(),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capex_opex']),
            'catalogItems' => PurCatalogItem::query()->where('is_active', true)->orderBy('description')->get([
                'id', 'item_code', 'description', 'negotiated_price', 'category_id', 'unit', 'preferred_supplier_id',
            ]),
            'approvedPrs' => PurRequisitionHdr::query()
                ->where('status', PurRequisitionHdr::STATUS_APPROVED)
                ->orderByDesc('id')
                ->get(['id', 'pr_no', 'estimated_total']),
            'initialPr' => $initialPr ? [
                'id' => $initialPr->id,
                'pr_no' => $initialPr->pr_no,
                'needed_by' => $initialPr->needed_by?->toDateString(),
                'lines' => $initialPr->lines->map(fn ($l) => [
                    'catalog_item_id' => $l->catalog_item_id,
                    'description' => $l->description,
                    'qty_ordered' => (float) $l->qty,
                    'unit_price' => (float) $l->estimated_unit_price,
                    'tax_amount' => 0,
                    'category_id' => $l->category_id,
                    'local_content_pct' => $l->local_content_pct !== null ? (float) $l->local_content_pct : null,
                ]),
            ] : null,
        ]);
    }

    public function store(StorePurchaseOrderRequest $request)
    {
        $po = $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('purchase.orders.show', $po->id)->with('success', "Purchase Order {$po->po_no} created.");
    }

    public function show(PurOrderHdr $order): Response
    {
        $order->load([
            'supplier:id,name',
            'requisition:id,pr_no',
            'creator:id,name',
            'lines.catalogItem:id,item_code,description,unit',
            'lines.category:id,name,kind',
            'revisions.revisedBy:id,name',
            'receipts.receiver:id,name',
            'invoices:id,po_id,supplier_invoice_no,amount,currency_code,match_status,status,supplier_invoice_date',
        ]);

        return Inertia::render('Purchase/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'po_no' => $order->po_no,
                'supplier' => $order->supplier ? ['id' => $order->supplier->id, 'name' => $order->supplier->name] : null,
                'pr' => $order->requisition ? ['id' => $order->requisition->id, 'pr_no' => $order->requisition->pr_no] : null,
                'creator' => $order->creator ? ['id' => $order->creator->id, 'name' => $order->creator->name] : null,
                'ship_to' => $order->ship_to,
                'bill_to' => $order->bill_to,
                'currency_code' => $order->currency_code,
                'incoterms' => $order->incoterms,
                'payment_terms_days' => $order->payment_terms_days,
                'status' => $order->status,
                'revision_no' => $order->revision_no,
                'subtotal' => (float) $order->subtotal,
                'tax_amount' => (float) $order->tax_amount,
                'total_amount' => (float) $order->total_amount,
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'ack_status' => $order->ack_status,
                'created_at' => $order->created_at?->toDateTimeString(),
                'lines' => $order->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'line_no' => $l->line_no,
                    'catalog_item' => $l->catalogItem ? ['id' => $l->catalogItem->id, 'item_code' => $l->catalogItem->item_code, 'description' => $l->catalogItem->description, 'unit' => $l->catalogItem->unit] : null,
                    'description' => $l->description,
                    'qty_ordered' => (float) $l->qty_ordered,
                    'qty_received' => (float) $l->qty_received,
                    'unit_price' => (float) $l->unit_price,
                    'tax_amount' => (float) $l->tax_amount,
                    'line_total' => (((float) $l->qty_ordered) * ((float) $l->unit_price)) + ((float) $l->tax_amount),
                    'expected_delivery_date' => $l->expected_delivery_date?->toDateString(),
                    'category' => $l->category ? ['id' => $l->category->id, 'name' => $l->category->name] : null,
                    'local_content_pct' => $l->local_content_pct !== null ? (float) $l->local_content_pct : null,
                ]),
                'revisions' => $order->revisions->map(fn ($r) => [
                    'id' => $r->id,
                    'revision_no' => $r->revision_no,
                    'revised_by' => $r->revisedBy?->name,
                    'revised_at' => $r->revised_at?->toDateTimeString(),
                    'snapshot' => $r->snapshot,
                ]),
                'receipts' => $order->receipts->map(fn ($gr) => [
                    'id' => $gr->id,
                    'gr_no' => $gr->gr_no,
                    'received_at' => $gr->received_at?->toDateTimeString(),
                    'receiver_name' => $gr->receiver?->name,
                    'status' => $gr->status,
                ]),
                'invoices' => $order->invoices->map(fn ($inv) => [
                    'id' => $inv->id,
                    'supplier_invoice_no' => $inv->supplier_invoice_no,
                    'supplier_invoice_date' => $inv->supplier_invoice_date?->toDateString(),
                    'amount' => (float) $inv->amount,
                    'match_status' => $inv->match_status,
                    'status' => $inv->status,
                ]),
            ],
        ]);
    }

    public function edit(PurOrderHdr $order): Response
    {
        $order->load(['lines']);

        return Inertia::render('Purchase/Orders/Edit', [
            'order' => [
                'id' => $order->id,
                'po_no' => $order->po_no,
                'supplier_id' => $order->supplier_id,
                'pr_id' => $order->pr_id,
                'ship_to' => $order->ship_to,
                'bill_to' => $order->bill_to,
                'currency_code' => $order->currency_code,
                'incoterms' => $order->incoterms,
                'payment_terms_days' => $order->payment_terms_days,
                'status' => $order->status,
                'revision_no' => $order->revision_no,
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'lines' => $order->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'catalog_item_id' => $l->catalog_item_id,
                    'description' => $l->description,
                    'qty_ordered' => (float) $l->qty_ordered,
                    'unit_price' => (float) $l->unit_price,
                    'tax_amount' => (float) $l->tax_amount,
                    'expected_delivery_date' => $l->expected_delivery_date?->toDateString(),
                    'category_id' => $l->category_id,
                    'local_content_pct' => $l->local_content_pct !== null ? (float) $l->local_content_pct : null,
                ]),
            ],
            'eligiblePartners' => $this->vendorService->eligiblePartners(),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capex_opex']),
            'catalogItems' => PurCatalogItem::query()->where('is_active', true)->orderBy('description')->get([
                'id', 'item_code', 'description', 'negotiated_price', 'category_id', 'unit',
            ]),
        ]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurOrderHdr $order)
    {
        $this->service->update($order, $request->validated(), $request->user()->id);

        return redirect()->route('purchase.orders.show', $order->id)->with('success', 'Purchase Order updated.');
    }

    public function submit(Request $request, PurOrderHdr $order)
    {
        $this->service->submit($order, $request->user()->id);

        return back()->with('success', 'Purchase Order submitted for approval.');
    }

    public function approve(Request $request, PurOrderHdr $order)
    {
        $this->service->approve($order, $request->user()->id);

        return back()->with('success', 'Purchase Order approved.');
    }

    public function reject(Request $request, PurOrderHdr $order)
    {
        $this->service->reject($order, $request->user()->id, $request->input('reason'));

        return back()->with('success', 'Purchase Order rejected.');
    }

    public function send(Request $request, PurOrderHdr $order)
    {
        $this->service->sendToSupplier($order, $request->user()->id);

        return back()->with('success', 'Purchase Order sent to supplier.');
    }

    public function acknowledge(Request $request, PurOrderHdr $order)
    {
        $validated = $request->validate([
            'ack_status' => ['required', 'string', 'in:accepted,accepted_with_changes,rejected'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->service->recordAcknowledgment($order, $validated['ack_status'], $validated['notes'] ?? null, $request->user()->id);

        return back()->with('success', 'Supplier acknowledgment recorded.');
    }

    public function close(Request $request, PurOrderHdr $order)
    {
        $this->service->close($order, $request->user()->id);

        return back()->with('success', 'Purchase Order closed.');
    }

    public function cancel(Request $request, PurOrderHdr $order)
    {
        $this->service->cancel($order, $request->user()->id);

        return back()->with('success', 'Purchase Order cancelled.');
    }
}
