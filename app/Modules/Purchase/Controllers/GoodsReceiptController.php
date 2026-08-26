<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurReceiptHdr;
use App\Modules\Purchase\Requests\StoreGoodsReceiptRequest;
use App\Modules\Purchase\Services\GoodsReceiptService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function __construct(
        protected GoodsReceiptService $service,
    ) {}

    public function index(): Response
    {
        $receipts = PurReceiptHdr::query()
            ->with(['order.supplier:id,name', 'receiver:id,name'])
            ->withCount('lines')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurReceiptHdr $gr) => [
                'id' => $gr->id,
                'uuid' => $gr->uuid,
                'gr_no' => $gr->gr_no,
                'po_id' => $gr->po_id,
                'po_no' => $gr->order?->po_no,
                'supplier_name' => $gr->order?->supplier?->name,
                'receiver_name' => $gr->receiver?->name,
                'received_at' => $gr->received_at?->toDateTimeString(),
                'status' => $gr->status,
                'lines_count' => $gr->lines_count,
            ]);

        return Inertia::render('Purchase/Receipts/Index', [
            'receipts' => $receipts,
        ]);
    }

    public function create(Request $request): Response
    {
        $poId = $request->query('po_id');
        $selectedPo = null;

        if ($poId) {
            $selectedPo = PurOrderHdr::query()
                ->with(['supplier:id,name', 'lines'])
                ->find($poId);
        }

        $eligibleOrders = PurOrderHdr::query()
            ->whereIn('status', [
                PurOrderHdr::STATUS_APPROVED,
                PurOrderHdr::STATUS_SENT,
                PurOrderHdr::STATUS_ACKNOWLEDGED,
                PurOrderHdr::STATUS_PARTIALLY_RECEIVED,
            ])
            ->with(['supplier:id,name', 'lines'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurOrderHdr $po) => [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'supplier_name' => $po->supplier?->name,
                'status' => $po->status,
                'lines' => $po->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'line_no' => $l->line_no,
                    'description' => $l->description,
                    'qty_ordered' => (float) $l->qty_ordered,
                    'qty_received' => (float) $l->qty_received,
                    'remaining_qty' => max(0, (float) $l->qty_ordered - (float) $l->qty_received),
                    'unit_price' => (float) $l->unit_price,
                ]),
            ]);

        return Inertia::render('Purchase/Receipts/Create', [
            'eligibleOrders' => $eligibleOrders,
            'initialPoId' => $poId ? (int) $poId : null,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request)
    {
        $gr = $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('purchase.receipts.show', $gr->id)->with('success', "Goods receipt {$gr->gr_no} posted.");
    }

    public function show(PurReceiptHdr $receipt): Response
    {
        $receipt->load([
            'order.supplier:id,name',
            'order.requisition:id,pr_no',
            'receiver:id,name,email',
            'lines.poLine',
        ]);

        return Inertia::render('Purchase/Receipts/Show', [
            'receipt' => [
                'id' => $receipt->id,
                'uuid' => $receipt->uuid,
                'gr_no' => $receipt->gr_no,
                'po_id' => $receipt->po_id,
                'po_no' => $receipt->order?->po_no,
                'supplier' => $receipt->order?->supplier ? ['id' => $receipt->order->supplier->id, 'name' => $receipt->order->supplier->name] : null,
                'pr_no' => $receipt->order?->requisition?->pr_no,
                'receiver' => $receipt->receiver ? ['id' => $receipt->receiver->id, 'name' => $receipt->receiver->name] : null,
                'received_at' => $receipt->received_at?->toDateTimeString(),
                'status' => $receipt->status,
                'discrepancy_notes' => $receipt->discrepancy_notes,
                'lines' => $receipt->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'po_line_id' => $l->po_line_id,
                    'description' => $l->poLine?->description ?? '—',
                    'quantity_received' => (float) $l->quantity_received,
                    'qty_ordered' => $l->poLine ? (float) $l->poLine->qty_ordered : null,
                    'unit_cost' => $l->unit_cost !== null ? (float) $l->unit_cost : null,
                    'condition_notes' => $l->condition_notes,
                    'over_receipt_flag' => $l->over_receipt_flag,
                ]),
            ],
        ]);
    }
}
