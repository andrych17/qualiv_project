<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Requests\StoreReturnRequest;
use App\Modules\Sales\Services\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReturnController extends Controller
{
    public function __construct(protected ReturnService $returnService) {}

    public function index(Request $request): Response
    {
        $returns = SalesReturn::with(['customer', 'order', 'replacementOrder', 'lines'])
            ->when($request->search, function ($q, $s) {
                $q->where('reason_code', 'ilike', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$s}%"));
            })
            ->when($request->status, fn ($q, $st) => $q->where('status', $st))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sales/Returns/Index', [
            'returns' => $returns,
            'statuses' => SalesReturn::STATUSES,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(Request $request): Response
    {
        $order = null;
        if ($request->so_hdr_id) {
            $order = SalesOrder::with(['lines', 'customer'])->findOrFail($request->so_hdr_id);
        }

        return Inertia::render('Sales/Returns/Create', [
            'selectedOrder' => $order,
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'orders' => SalesOrder::query()->select(['id', 'so_number', 'customer_id'])->get(),
        ]);
    }

    public function store(StoreReturnRequest $request): RedirectResponse
    {
        $return = $this->returnService->create($request->validated(), $request->user()?->id);

        return redirect()->route('sales.returns.show', $return)
            ->with('success', 'Return request created.');
    }

    public function show(SalesReturn $return): Response
    {
        $return->load(['customer', 'order.lines', 'replacementOrder', 'lines.salesOrderLine', 'creator']);

        return Inertia::render('Sales/Returns/Show', [
            'returnRecord' => $return,
        ]);
    }

    public function approve(SalesReturn $return): RedirectResponse
    {
        $this->returnService->approve($return);

        return back()->with('success', 'Return approved.');
    }

    public function receive(SalesReturn $return): RedirectResponse
    {
        $this->returnService->markReceived($return);

        return back()->with('success', 'Return items marked as received.');
    }

    public function refund(SalesReturn $return, Request $request): RedirectResponse
    {
        $this->returnService->processRefund($return, $request->user()?->id);

        return back()->with('success', 'Refund processed.');
    }

    public function replace(SalesReturn $return, Request $request): RedirectResponse
    {
        $newOrder = $this->returnService->processReplacement($return, $request->user()?->id);

        return redirect()->route('sales.orders.show', $newOrder)
            ->with('success', "Replacement Sales Order #{$newOrder->so_number} created.");
    }
}
