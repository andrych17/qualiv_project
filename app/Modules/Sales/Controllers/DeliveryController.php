<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Requests\StoreDeliveryRequest;
use App\Modules\Sales\Services\DeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryController extends Controller
{
    public function __construct(protected DeliveryService $deliveryService) {}

    public function index(Request $request): Response
    {
        $deliveries = Delivery::with(['order.customer', 'lines.salesOrderLine'])
            ->when($request->search, function ($q, $s) {
                $q->where('tracking_number', 'ilike', "%{$s}%")
                    ->orWhere('carrier', 'ilike', "%{$s}%")
                    ->orWhereHas('order', fn ($o) => $o->where('so_number', 'ilike', "%{$s}%"));
            })
            ->when($request->status, fn ($q, $st) => $q->where('status', $st))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sales/Deliveries/Index', [
            'deliveries' => $deliveries,
            'statuses' => Delivery::STATUSES,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(Request $request): Response
    {
        $order = null;
        if ($request->so_hdr_id) {
            $order = SalesOrder::with(['lines', 'customer'])->findOrFail($request->so_hdr_id);
        }

        $confirmedOrders = SalesOrder::with('customer')
            ->whereIn('status', [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED])
            ->get();

        return Inertia::render('Sales/Deliveries/Create', [
            'selectedOrder' => $order,
            'confirmedOrders' => $confirmedOrders,
        ]);
    }

    public function store(StoreDeliveryRequest $request): RedirectResponse
    {
        $delivery = $this->deliveryService->create($request->validated());

        return redirect()->route('sales.deliveries.show', $delivery)
            ->with('success', 'Delivery draft created.');
    }

    public function show(Delivery $delivery): Response
    {
        $delivery->load(['order.customer', 'order.lines', 'lines.salesOrderLine']);

        return Inertia::render('Sales/Deliveries/Show', [
            'delivery' => $delivery,
            'statuses' => Delivery::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, Delivery $delivery): RedirectResponse
    {
        $request->validate([
            'status' => ['required', 'in:'.implode(',', Delivery::STATUSES)],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'source_location_id' => ['nullable', 'integer'],
        ]);

        $this->deliveryService->updateStatus($delivery, $request->status, $request->only([
            'carrier',
            'tracking_number',
            'source_location_id',
        ]));

        return back()->with('success', "Delivery status updated to {$request->status}.");
    }
}
