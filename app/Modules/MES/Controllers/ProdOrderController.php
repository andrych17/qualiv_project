<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\QcInspectionPlan;
use App\Modules\MES\Models\QcSample;
use App\Modules\MES\Requests\StoreProdOrderRequest;
use App\Modules\MES\Requests\UpdateProdOrderRequest;
use App\Modules\MES\Services\ProdOrderService;
use App\Modules\MES\Services\ReworkService;
use App\Modules\MES\Services\YieldService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3A Production Order (Entry) — one header for both production models. */
class ProdOrderController extends Controller
{
    private const SORTABLE = ['order_number', 'planned_start', 'status', 'created_at'];

    public function __construct(
        protected ProdOrderService $service,
        protected YieldService $yield,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'production_model', 'status', 'sort', 'direction', 'per_page');

        $orders = ProdOrder::query()
            ->with('product:id,sku,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ProdOrder $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'product_sku' => $o->product?->sku,
                'product_name' => $o->product?->name,
                'production_model' => $o->production_model,
                'qty' => (float) $o->qty,
                'uom_code' => $o->uom_code,
                'planned_start' => $o->planned_start?->toDateString(),
                'status' => $o->status,
            ]);

        return Inertia::render('MES/ProdOrders/Index', [
            'orders' => $orders,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MES/ProdOrders/Create', [
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    public function store(StoreProdOrderRequest $request)
    {
        $order = $this->service->create($request->validated());

        return redirect()->route('mes.prodOrders.show', $order->id)->with('success', "Production order {$order->order_number} created.");
    }

    public function show(ProdOrder $prodOrder): Response
    {
        $prodOrder->load([
            'product:id,sku,name',
            'bom:id,version',
            'recipe:id,version',
            'routing:id,version',
            'routing.ops:id,routing_id,op_code,op_name',
            'warehouse:id,name',
            'parentOrder:id,order_number',
            'events' => fn ($query) => $query->orderByDesc('occurred_at')->with('user:id,name', 'machine:id,code'),
            'materialConsumptions' => fn ($query) => $query->orderByDesc('created_at')->with('material:id,sku,name', 'lot:id,batch_number', 'serial:id,serial_number'),
            'productionOutputs' => fn ($query) => $query->orderByDesc('created_at')->with('product:id,sku,name', 'lot:id,batch_number', 'serial:id,serial_number'),
            'serialLinks' => fn ($query) => $query->orderByDesc('created_at')->with('serial:id,serial_number', 'componentSerial:id,serial_number', 'componentLot:id,batch_number', 'material:id,sku,name'),
        ]);

        $reworkOrdersByOutputId = ProdOrder::query()
            ->where('source_type', ReworkService::SOURCE_TYPE)
            ->whereIn('source_id', $prodOrder->productionOutputs->pluck('id'))
            ->get(['id', 'order_number', 'source_id'])
            ->keyBy('source_id');

        // §3L: the QC panel only shows once a plan exists for this order's product — Basic
        // Quality (Phase 1) is opt-in, not a mandatory gate on completion (§2 Goals phasing).
        $qcPlan = QcInspectionPlan::query()->where('product_id', $prodOrder->product_id)->with('characteristics')->first();

        $qcSamples = QcSample::query()->where('order_id', $prodOrder->id)
            ->with('results.characteristic', 'takenBy:id,name')
            ->orderByDesc('taken_at')
            ->get();

        $qcHolds = $this->qcHoldsFor($prodOrder);

        return Inertia::render('MES/ProdOrders/Show', [
            'order' => $this->toDetailData($prodOrder, $reworkOrdersByOutputId),
            'locations' => $prodOrder->warehouse_id ? $this->locationOptions($prodOrder->warehouse_id) : [],
            'routingOps' => $prodOrder->routing ? $prodOrder->routing->ops->map(fn ($op) => [
                'value' => $op->id,
                'label' => "{$op->op_code} — {$op->op_name}",
            ])->all() : [],
            'qcPlan' => $qcPlan ? [
                'id' => $qcPlan->id,
                'name' => $qcPlan->name,
                'characteristics' => $qcPlan->characteristics->map(fn ($c) => [
                    'id' => $c->id,
                    'characteristic_name' => $c->characteristic_name,
                    'spec_type' => $c->spec_type,
                    'target_value' => $c->target_value !== null ? (float) $c->target_value : null,
                    'min_value' => $c->min_value !== null ? (float) $c->min_value : null,
                    'max_value' => $c->max_value !== null ? (float) $c->max_value : null,
                    'uom_code' => $c->uom_code,
                ]),
            ] : null,
            'qcSamples' => $qcSamples->map(fn (QcSample $s) => [
                'id' => $s->id,
                'sample_number' => $s->sample_number,
                'taken_by_name' => $s->takenBy?->name,
                'taken_at' => $s->taken_at?->toDateTimeString(),
                'results' => $s->results->map(fn ($r) => [
                    'characteristic_name' => $r->characteristic?->characteristic_name,
                    'actual_value' => $r->actual_value !== null ? (float) $r->actual_value : null,
                    'result' => $r->result,
                ]),
            ]),
            'qcHolds' => $qcHolds,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function qcHoldsFor(ProdOrder $order)
    {
        $lotIds = $order->productionOutputs->pluck('lot_id')->filter()->values();
        $serialIds = $order->productionOutputs->pluck('serial_id')->filter()->values();
        $outputIds = $order->productionOutputs->pluck('id');

        return QcHold::query()
            ->with('releasedBy:id,name')
            ->where(function ($query) use ($lotIds, $serialIds, $outputIds) {
                $query->where(fn ($q) => $q->where('subject_type', 'inventory.stock_batches')->whereIn('subject_id', $lotIds))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'inventory.stock_serials')->whereIn('subject_id', $serialIds))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'mes.mes_production_outputs')->whereIn('subject_id', $outputIds));
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (QcHold $h) => [
                'id' => $h->id,
                'subject_type' => $h->subject_type,
                'subject_id' => $h->subject_id,
                'reason' => $h->reason,
                'status' => $h->status,
                'released_by_name' => $h->releasedBy?->name,
                'released_at' => $h->released_at?->toDateTimeString(),
                'created_at' => $h->created_at?->toDateTimeString(),
            ]);
    }

    public function edit(ProdOrder $prodOrder): Response
    {
        if ($prodOrder->status !== ProdOrder::STATUS_DRAFT) {
            abort(422, 'Only a draft production order can be edited.');
        }

        return Inertia::render('MES/ProdOrders/Edit', [
            'order' => $this->toFormData($prodOrder),
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    public function update(UpdateProdOrderRequest $request, ProdOrder $prodOrder)
    {
        try {
            $this->service->update($prodOrder, $request->validated());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('mes.prodOrders.show', $prodOrder->id)->with('success', 'Production order updated.');
    }

    public function destroy(ProdOrder $prodOrder)
    {
        try {
            $this->service->delete($prodOrder);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('mes.prodOrders.index')->with('success', 'Production order deleted.');
    }

    public function release(Request $request, ProdOrder $prodOrder)
    {
        try {
            $this->service->release($prodOrder, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Production order {$prodOrder->order_number} released.");
    }

    public function cancel(ProdOrder $prodOrder)
    {
        try {
            $this->service->cancel($prodOrder);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Production order {$prodOrder->order_number} cancelled.");
    }

    /** @return array<string, mixed> */
    private function toFormData(ProdOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'product_id' => $order->product_id,
            'product_label' => $order->product ? "{$order->product->sku} — {$order->product->name}" : null,
            'production_model' => $order->production_model,
            'qty' => (float) $order->qty,
            'uom_code' => $order->uom_code,
            'planned_start' => $order->planned_start?->toDateTimeString(),
            'planned_end' => $order->planned_end?->toDateTimeString(),
            'priority' => $order->priority,
            'warehouse_id' => $order->warehouse_id,
            'line_area' => $order->line_area,
        ];
    }

    /**
     * @param  Collection<int, ProdOrder>  $reworkOrdersByOutputId  keyed by `mes_production_outputs.id` — the rework child order (if any) that output row was sent to (§3N)
     * @return array<string, mixed>
     */
    private function toDetailData(ProdOrder $order, $reworkOrdersByOutputId): array
    {
        $yield = $this->yield->forOrder($order->id);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'product' => $order->product ? ['id' => $order->product->id, 'sku' => $order->product->sku, 'name' => $order->product->name] : null,
            'production_model' => $order->production_model,
            'bom_version' => $order->bom?->version,
            'recipe_version' => $order->recipe?->version,
            'routing_version' => $order->routing?->version,
            'qty' => (float) $order->qty,
            'uom_code' => $order->uom_code,
            'planned_start' => $order->planned_start?->toDateTimeString(),
            'planned_end' => $order->planned_end?->toDateTimeString(),
            'actual_start' => $order->actual_start?->toDateTimeString(),
            'actual_end' => $order->actual_end?->toDateTimeString(),
            'priority' => $order->priority,
            'warehouse_name' => $order->warehouse?->name,
            'line_area' => $order->line_area,
            'status' => $order->status,
            'parent_order' => $order->parentOrder ? ['id' => $order->parentOrder->id, 'order_number' => $order->parentOrder->order_number] : null,
            'is_rework_order' => $order->source_type === ReworkService::SOURCE_TYPE,
            'created_at' => $order->created_at?->toDateTimeString(),
            'yield' => $yield,
            'events' => $order->events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'payload' => $e->payload,
                'occurred_at' => $e->occurred_at?->toDateTimeString(),
                'user_name' => $e->user?->name,
                'machine_code' => $e->machine?->code,
            ]),
            'material_consumptions' => $order->materialConsumptions->map(fn ($c) => [
                'id' => $c->id,
                'material_sku' => $c->material?->sku,
                'material_name' => $c->material?->name,
                'lot_number' => $c->lot?->batch_number,
                'serial_number' => $c->serial?->serial_number,
                'qty' => (float) $c->qty,
                'uom_code' => $c->uom_code,
                'type' => $c->type,
                'created_at' => $c->created_at?->toDateTimeString(),
            ]),
            'production_outputs' => $order->productionOutputs->map(fn ($o) => [
                'id' => $o->id,
                'output_type' => $o->output_type,
                'product_sku' => $o->product?->sku,
                'product_name' => $o->product?->name,
                'lot_number' => $o->lot?->batch_number,
                'serial_number' => $o->serial?->serial_number,
                'qty' => (float) $o->qty,
                'uom_code' => $o->uom_code,
                'reason_code' => $o->reason_code,
                'disposition' => $o->disposition,
                'created_at' => $o->created_at?->toDateTimeString(),
                'rework_order' => ($reworkOrder = $reworkOrdersByOutputId->get($o->id))
                    ? ['id' => $reworkOrder->id, 'order_number' => $reworkOrder->order_number]
                    : null,
            ]),
            'serial_links' => $order->serialLinks->map(fn ($l) => [
                'id' => $l->id,
                'serial_number' => $l->serial?->serial_number,
                'component_serial_number' => $l->componentSerial?->serial_number,
                'component_lot_number' => $l->componentLot?->batch_number,
                'material_sku' => $l->material?->sku,
                'material_name' => $l->material?->name,
                'created_at' => $l->created_at?->toDateTimeString(),
            ]),
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function warehouseOptions(): array
    {
        return Warehouse::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Warehouse $w) => ['value' => $w->id, 'label' => $w->name])
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function locationOptions(int $warehouseId): array
    {
        return Location::query()
            ->where('warehouse_id', $warehouseId)
            ->orderBy('code')
            ->get(['id', 'code'])
            ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->code])
            ->all();
    }
}
