<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\PurInvoiceHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Requests\StorePurInvoiceRequest;
use App\Modules\Purchase\Services\InvoiceMatchingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceMatchingService $service,
    ) {}

    public function index(): Response
    {
        $invoices = PurInvoiceHdr::query()
            ->with(['order.supplier:id,name', 'creator:id,name'])
            ->withCount('lines')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurInvoiceHdr $inv) => [
                'id' => $inv->id,
                'uuid' => $inv->uuid,
                'supplier_invoice_no' => $inv->supplier_invoice_no,
                'supplier_invoice_date' => $inv->supplier_invoice_date?->toDateString(),
                'po_id' => $inv->po_id,
                'po_no' => $inv->order?->po_no,
                'supplier_name' => $inv->supplier?->name ?? $inv->order?->supplier?->name,
                'currency_code' => $inv->currency_code,
                'amount' => (float) $inv->amount,
                'match_status' => $inv->match_status,
                'status' => $inv->status,
                'lines_count' => $inv->lines_count,
                'ap_bill_id' => $inv->ap_bill_id,
            ]);

        return Inertia::render('Purchase/Invoices/Index', [
            'invoices' => $invoices,
        ]);
    }

    public function create(Request $request): Response
    {
        $poId = $request->query('po_id');

        $eligibleOrders = PurOrderHdr::query()
            ->whereIn('status', [
                PurOrderHdr::STATUS_APPROVED,
                PurOrderHdr::STATUS_SENT,
                PurOrderHdr::STATUS_ACKNOWLEDGED,
                PurOrderHdr::STATUS_PARTIALLY_RECEIVED,
                PurOrderHdr::STATUS_RECEIVED,
            ])
            ->with(['supplier:id,name', 'lines', 'receipts.lines'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurOrderHdr $po) => [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'supplier_id' => $po->supplier_id,
                'supplier_name' => $po->supplier?->name,
                'currency_code' => $po->currency_code,
                'total_amount' => (float) $po->total_amount,
                'status' => $po->status,
                'lines' => $po->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'line_no' => $l->line_no,
                    'description' => $l->description,
                    'qty_ordered' => (float) $l->qty_ordered,
                    'qty_received' => (float) $l->qty_received,
                    'unit_price' => (float) $l->unit_price,
                ]),
            ]);

        return Inertia::render('Purchase/Invoices/Create', [
            'eligibleOrders' => $eligibleOrders,
            'initialPoId' => $poId ? (int) $poId : null,
        ]);
    }

    public function store(StorePurInvoiceRequest $request)
    {
        $invoice = $this->service->captureInvoice($request->validated(), $request->user()->id);

        return redirect()->route('purchase.invoices.show', $invoice->id)->with('success', "Invoice {$invoice->supplier_invoice_no} captured and 3-way match computed.");
    }

    public function show(PurInvoiceHdr $invoice): Response
    {
        $invoice->load([
            'order.supplier:id,name',
            'order.requisition:id,pr_no',
            'order.receipts.lines',
            'supplier:id,name',
            'creator:id,name',
            'lines.poLine',
            'matches.poLine',
            'apBill:id,bill_no,status,amount',
        ]);

        return Inertia::render('Purchase/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'uuid' => $invoice->uuid,
                'supplier_invoice_no' => $invoice->supplier_invoice_no,
                'supplier_invoice_date' => $invoice->supplier_invoice_date?->toDateString(),
                'po_id' => $invoice->po_id,
                'po_no' => $invoice->order?->po_no,
                'supplier' => $invoice->supplier ? ['id' => $invoice->supplier->id, 'name' => $invoice->supplier->name] : null,
                'pr_no' => $invoice->order?->requisition?->pr_no,
                'currency_code' => $invoice->currency_code,
                'amount' => (float) $invoice->amount,
                'match_status' => $invoice->match_status,
                'status' => $invoice->status,
                'submission_channel' => $invoice->submission_channel,
                'ap_bill' => $invoice->apBill ? [
                    'id' => $invoice->apBill->id,
                    'bill_no' => $invoice->apBill->bill_no,
                    'status' => $invoice->apBill->status,
                    'amount' => (float) $invoice->apBill->amount,
                ] : null,
                'creator' => $invoice->creator ? ['id' => $invoice->creator->id, 'name' => $invoice->creator->name] : null,
                'created_at' => $invoice->created_at?->toDateTimeString(),
                'lines' => $invoice->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'po_line_id' => $l->po_line_id,
                    'description' => $l->poLine?->description ?? '—',
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'line_amount' => (float) $l->line_amount,
                ]),
                'matches' => $invoice->matches->map(fn ($m) => [
                    'id' => $m->id,
                    'po_line_id' => $m->po_line_id,
                    'description' => $m->poLine?->description ?? '—',
                    'po_qty' => (float) $m->po_qty,
                    'po_price' => (float) $m->po_price,
                    'gr_qty' => (float) $m->gr_qty,
                    'invoice_qty' => (float) $m->invoice_qty,
                    'invoice_price' => (float) $m->invoice_price,
                    'qty_variance_pct' => (float) $m->qty_variance_pct,
                    'price_variance_pct' => (float) $m->price_variance_pct,
                    'within_tolerance' => $m->within_tolerance,
                ]),
            ],
        ]);
    }

    public function rematch(PurInvoiceHdr $invoice)
    {
        $this->service->performThreeWayMatch($invoice);

        return redirect()->back()->with('success', "Three-way match recomputed for invoice {$invoice->supplier_invoice_no}.");
    }

    public function submit(Request $request, PurInvoiceHdr $invoice)
    {
        $this->service->submitForApproval($invoice, $request->user()->id);

        return redirect()->back()->with('success', "Invoice {$invoice->supplier_invoice_no} submitted for approval.");
    }

    public function approve(Request $request, PurInvoiceHdr $invoice)
    {
        $this->service->approve($invoice, $request->user()->id);

        return redirect()->back()->with('success', "Invoice {$invoice->supplier_invoice_no} approved and handed to Accounting AP.");
    }

    public function reject(Request $request, PurInvoiceHdr $invoice)
    {
        $request->validate(['reason' => ['nullable', 'string']]);
        $this->service->reject($invoice, $request->user()->id, $request->input('reason'));

        return redirect()->back()->with('success', "Invoice {$invoice->supplier_invoice_no} rejected.");
    }
}
