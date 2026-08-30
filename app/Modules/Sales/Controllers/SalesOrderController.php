<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Requests\StoreSalesOrderRequest;
use App\Modules\Sales\Services\BillingService;
use App\Modules\Sales\Services\CreditService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SalesOrderController extends Controller
{
    public function __construct(
        protected SalesOrderService $salesOrderService,
        protected CreditService $creditService,
        protected BillingService $billingService,
    ) {}

    public function index(Request $request): Response
    {
        $perPage = TableQuery::perPage($request->integer('per_page') ?: null, 20);
        $query = SalesOrder::with(['customer', 'lines', 'quote'])
            ->when($request->search, function ($q, $s) {
                $q->where('so_number', 'ilike', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$s}%"));
            })
            ->when($request->status, fn ($q, $st) => $q->where('status', $st))
            ->when($request->customer_id, fn ($q, $c) => $q->where('customer_id', $c));

        TableQuery::applySort($query, $request->sort, $request->direction, ['so_number', 'created_at', 'status'], 'created_at', 'desc');

        $orders = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Sales/Orders/Index', [
            'orders' => $orders,
            'statuses' => SalesOrder::STATUSES,
            'filters' => $request->only(['search', 'status', 'customer_id', 'sort', 'direction', 'per_page']),
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/Orders/Create', [
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'priceLists' => PriceList::with('lines')->where('is_active', true)->get(),
            'preselectedCustomerId' => $request->customer_id ? (int) $request->customer_id : null,
        ]);
    }

    public function store(StoreSalesOrderRequest $request): RedirectResponse
    {
        $order = $this->salesOrderService->create($request->validated(), $request->user()?->id);

        return redirect()->route('sales.orders.show', $order)
            ->with('success', "Sales Order {$order->so_number} created.");
    }

    public function show(SalesOrder $order): Response
    {
        $order->load(['lines', 'customer', 'quote', 'priceList.lines', 'creator', 'deliveries.lines.salesOrderLine', 'returns.lines']);

        $creditExposure = $this->creditService->getExposure($order->customer_id);

        // Fetch linked Accounting invoices
        $invoices = [];
        if (Schema::hasTable('ACCOUNTING.ar_invoices')) {
            $invoices = ArInvoice::with('lines')
                ->where('subject_type', 'sales.so_hdrs')
                ->where('subject_id', $order->id)
                ->orderByDesc('issue_date')
                ->get();
        }

        return Inertia::render('Sales/Orders/Show', [
            'order' => $order,
            'creditExposure' => $creditExposure,
            'invoices' => $invoices,
            'subtotal' => $order->subtotal,
            'totalDiscount' => $order->total_discount,
            'totalTax' => $order->total_tax,
            'totalAmount' => $order->total_amount,
            'qtyOrderedTotal' => $order->qty_ordered_total,
            'qtyDeliveredTotal' => $order->qty_delivered_total,
            'qtyInvoicedTotal' => $order->qty_invoiced_total,
        ]);
    }

    public function edit(SalesOrder $order): Response
    {
        $order->load(['lines', 'customer', 'priceList.lines']);

        return Inertia::render('Sales/Orders/Edit', [
            'order' => $order,
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'priceLists' => PriceList::with('lines')->where('is_active', true)->get(),
        ]);
    }

    public function update(StoreSalesOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->salesOrderService->update($order, $request->validated());

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Sales Order updated successfully.');
    }

    public function confirm(SalesOrder $order): RedirectResponse
    {
        $this->salesOrderService->confirm($order);

        return back()->with('success', "Sales Order {$order->so_number} confirmed.");
    }

    /**
     * §3K: the "without WNE, explicit admin action" override for a credit-blocked order.
     * Gated by the same menu.perm:SALES the rest of order management uses (same tier as
     * Returns' approve() / Commissions' approve() — no dedicated admin-only permission code
     * exists for this yet; anyone with Sales create rights, including STAFF per
     * SysConfigSeeder's trustee matrix, can invoke it).
     */
    public function confirmOverride(SalesOrder $order, Request $request): RedirectResponse
    {
        $this->salesOrderService->confirm($order, skipCreditCheck: true, overriddenBy: $request->user()?->id);

        return back()->with('success', "Sales Order {$order->so_number} confirmed with credit override.");
    }

    public function cancel(SalesOrder $order): RedirectResponse
    {
        $this->salesOrderService->cancel($order);

        return back()->with('success', "Sales Order {$order->so_number} cancelled.");
    }

    public function requestInvoice(SalesOrder $order, Request $request): RedirectResponse
    {
        $invoice = $this->billingService->generateInvoiceForOrder($order, $request->only(['issue_date', 'due_date', 'invoice_type']), $request->user()?->id);

        return back()->with('success', 'Invoice request submitted to Accounting (Invoice #'.($invoice ? $invoice->invoice_no : 'Draft').').');
    }

    public function destroy(SalesOrder $order): RedirectResponse
    {
        if ($order->status !== SalesOrder::STATUS_DRAFT) {
            return back()->withErrors(['status' => 'Only draft orders can be deleted.']);
        }

        $order->delete();

        return redirect()->route('sales.orders.index')
            ->with('success', 'Sales Order draft deleted.');
    }
}
