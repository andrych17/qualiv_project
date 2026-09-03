<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Requests\StoreOperationCompleteRequest;
use App\Modules\MES\Services\OperationExecutionService;
use App\Modules\PP\Models\BomLine;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3G — Shop Floor Operation UI (assembly). Dedicated layout, not `AppLayout` (§5 Technical Notes). */
class ShopFloorController extends Controller
{
    public function __construct(
        protected OperationExecutionService $service,
        protected InventoryService $inventory,
    ) {}

    public function show(ProdOrder $prodOrder): Response
    {
        $this->assertAssembly($prodOrder);

        $prodOrder->load(['product:id,sku,name', 'routing.ops' => fn ($q) => $q->orderBy('seq'), 'warehouse:id,name']);

        $statuses = $this->service->statusesFor($prodOrder);
        $currentOp = $this->service->currentOp($prodOrder);

        return Inertia::render('MES/ShopFloor/Show', [
            'order' => [
                'id' => $prodOrder->id,
                'order_number' => $prodOrder->order_number,
                'product' => $prodOrder->product ? ['id' => $prodOrder->product->id, 'sku' => $prodOrder->product->sku, 'name' => $prodOrder->product->name] : null,
                'qty' => (float) $prodOrder->qty,
                'uom_code' => $prodOrder->uom_code,
                'status' => $prodOrder->status,
                'warehouse_name' => $prodOrder->warehouse?->name,
            ],
            'ops' => $prodOrder->routing ? $prodOrder->routing->ops->map(fn ($op) => [
                'id' => $op->id,
                'seq' => $op->seq,
                'op_code' => $op->op_code,
                'op_name' => $op->op_name,
                'status' => $statuses[$op->id] ?? null,
                'is_current' => $currentOp?->id === $op->id,
                'auto_issue_components' => $op->auto_issue_components,
            ]) : [],
            'currentOp' => $currentOp ? [
                'id' => $currentOp->id,
                'op_code' => $currentOp->op_code,
                'op_name' => $currentOp->op_name,
                'standard_output_qty' => $currentOp->standard_output_qty !== null ? (float) $currentOp->standard_output_qty : null,
                'auto_issue_components' => $currentOp->auto_issue_components,
                'status' => $statuses[$currentOp->id] ?? null,
                'is_last' => $currentOp->id === $prodOrder->routing?->ops?->last()?->id,
            ] : null,
            'components' => $this->componentAvailability($prodOrder),
            'locations' => $prodOrder->warehouse_id ? $this->locationOptions($prodOrder->warehouse_id) : [],
        ]);
    }

    public function start(Request $request, ProdOrder $prodOrder)
    {
        $this->assertAssembly($prodOrder);
        $op = $this->service->currentOp($prodOrder);
        if (! $op) {
            return back()->with('error', 'No operation left to start.');
        }

        try {
            $this->service->start($prodOrder, $op, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$op->op_code} started.");
    }

    public function pause(Request $request, ProdOrder $prodOrder)
    {
        $this->assertAssembly($prodOrder);
        $op = $this->service->currentOp($prodOrder);
        if (! $op) {
            return back()->with('error', 'No operation to pause.');
        }

        try {
            $this->service->pause($prodOrder, $op, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$op->op_code} paused.");
    }

    public function resume(Request $request, ProdOrder $prodOrder)
    {
        $this->assertAssembly($prodOrder);
        $op = $this->service->currentOp($prodOrder);
        if (! $op) {
            return back()->with('error', 'No operation to resume.');
        }

        try {
            $this->service->resume($prodOrder, $op);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$op->op_code} resumed.");
    }

    public function complete(StoreOperationCompleteRequest $request, ProdOrder $prodOrder)
    {
        $this->assertAssembly($prodOrder);
        $op = $this->service->currentOp($prodOrder);
        if (! $op) {
            return back()->with('error', 'No operation to complete.');
        }

        try {
            $this->service->complete($prodOrder, $op, $request->validated(), $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$op->op_code} completed.");
    }

    private function assertAssembly(ProdOrder $order): void
    {
        if ($order->production_model !== ProdOrder::MODEL_ASSEMBLY) {
            abort(422, 'The Shop Floor Operation UI is for assembly-model orders — use Batch Execution for a process-model order.');
        }
    }

    /** §3G: "Component availability strip... reads InventoryService::checkAvailability() per component line — read-only warning, does not block starting the operation." */
    private function componentAvailability(ProdOrder $order): array
    {
        if ($order->bom_id === null || $order->warehouse_id === null) {
            return [];
        }

        return BomLine::query()->where('bom_id', $order->bom_id)->with('component:id,sku,name')->get()
            ->map(fn (BomLine $line) => [
                'sku' => $line->component?->sku,
                'name' => $line->component?->name,
                'qty_per_parent_unit' => (float) $line->qty_per_parent_unit,
                'available' => $this->inventory->checkAvailability($line->component_product_id, $order->warehouse_id),
            ])
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
