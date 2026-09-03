<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Location;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Requests\StoreBatchPhaseCompleteRequest;
use App\Modules\MES\Requests\StoreBatchRequest;
use App\Modules\MES\Services\BatchExecutionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MES_SPECS.md §3I — Batch / Phase UI (process). Dedicated layout, not `AppLayout` (§5
 * Technical Notes). MVP: one batch drives an order's execution (`mes_batch_relations`
 * split/merge is schema-only in this build, §4 note) — `latestBatch()` is the batch this page
 * always acts on.
 */
class BatchExecutionController extends Controller
{
    public function __construct(protected BatchExecutionService $service) {}

    public function show(ProdOrder $prodOrder): Response
    {
        $this->assertProcess($prodOrder);

        $prodOrder->load(['product:id,sku,name', 'recipe:id,version,batch_size', 'warehouse:id,name']);
        $batch = $this->latestBatch($prodOrder);

        if (! $batch) {
            return Inertia::render('MES/ShopFloor/Batch', [
                'order' => $this->orderPayload($prodOrder),
                'batch' => null,
                'locations' => [],
            ]);
        }

        $batch->load(['ingredients.rawMaterial:id,sku,name', 'phases' => fn ($q) => $q->orderBy('seq'), 'phases.processPhase.parameters', 'phases.readings']);

        $currentPhase = $batch->phases->firstWhere('status', 'running') ?? $batch->phases->firstWhere('status', 'paused');

        return Inertia::render('MES/ShopFloor/Batch', [
            'order' => $this->orderPayload($prodOrder),
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'status' => $batch->status,
                'planned_qty' => (float) $batch->planned_qty,
                'actual_yield_pct' => $batch->actual_yield_pct !== null ? (float) $batch->actual_yield_pct : null,
                'ingredients' => $batch->ingredients->map(fn ($i) => [
                    'sku' => $i->rawMaterial?->sku,
                    'name' => $i->rawMaterial?->name,
                    'resolved_qty' => (float) $i->resolved_qty,
                    'uom_code' => $i->uom_code,
                ]),
                'phases' => $batch->phases->map(fn ($p) => [
                    'id' => $p->id,
                    'seq' => $p->seq,
                    'phase_name' => $p->processPhase?->phase_name,
                    'status' => $p->status,
                    'start_at' => $p->start_at?->toDateTimeString(),
                    'end_at' => $p->end_at?->toDateTimeString(),
                    'is_last' => $p->seq === $batch->phases->max('seq'),
                    'parameters' => $p->processPhase?->parameters?->map(fn ($param) => [
                        'id' => $param->id,
                        'parameter_code' => $param->parameter_code,
                        'target_value' => $param->target_value !== null ? (float) $param->target_value : null,
                        'min_value' => $param->min_value !== null ? (float) $param->min_value : null,
                        'max_value' => $param->max_value !== null ? (float) $param->max_value : null,
                        'uom_code' => $param->uom_code,
                    ]) ?? [],
                    'readings' => $p->readings->map(fn ($r) => [
                        'process_parameter_id' => $r->process_parameter_id,
                        'value' => (float) $r->value,
                        'recorded_at' => $r->recorded_at?->toDateTimeString(),
                    ]),
                ]),
            ],
            'currentPhaseId' => $currentPhase?->id,
            'locations' => $prodOrder->warehouse_id ? $this->locationOptions($prodOrder->warehouse_id) : [],
        ]);
    }

    public function store(StoreBatchRequest $request, ProdOrder $prodOrder)
    {
        $this->assertProcess($prodOrder);

        try {
            $this->service->create($prodOrder, $request->validated());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('mes.shopFloor.batch.show', $prodOrder->id)->with('success', 'Batch created.');
    }

    public function start(Request $request, ProdOrder $prodOrder)
    {
        $this->assertProcess($prodOrder);
        $batch = $this->latestBatch($prodOrder);
        if (! $batch) {
            return back()->with('error', 'No batch to start.');
        }

        try {
            $this->service->start($batch, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Batch {$batch->batch_number} started.");
    }

    public function pause(Request $request, ProdOrder $prodOrder)
    {
        $this->assertProcess($prodOrder);
        $batch = $this->latestBatch($prodOrder);
        if (! $batch) {
            return back()->with('error', 'No batch to pause.');
        }

        try {
            $this->service->pause($batch, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Batch {$batch->batch_number} paused.");
    }

    public function resume(ProdOrder $prodOrder)
    {
        $this->assertProcess($prodOrder);
        $batch = $this->latestBatch($prodOrder);
        if (! $batch) {
            return back()->with('error', 'No batch to resume.');
        }

        try {
            $this->service->resume($batch);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Batch {$batch->batch_number} resumed.");
    }

    public function completePhase(StoreBatchPhaseCompleteRequest $request, ProdOrder $prodOrder)
    {
        $this->assertProcess($prodOrder);
        $batch = $this->latestBatch($prodOrder);
        if (! $batch) {
            return back()->with('error', 'No batch running.');
        }

        $phase = $batch->phases()->whereIn('status', ['running', 'paused'])->first();
        if (! $phase) {
            return back()->with('error', 'No phase currently running.');
        }

        try {
            $this->service->completePhase($batch, $phase, $request->validated('readings', []), $request->user()->id, $request->validated('location_id'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Phase completed.');
    }

    private function assertProcess(ProdOrder $order): void
    {
        if ($order->production_model !== ProdOrder::MODEL_PROCESS) {
            abort(422, 'Batch Execution is for process-model orders — use the Shop Floor Operation UI for an assembly-model order.');
        }
    }

    private function latestBatch(ProdOrder $order)
    {
        return $order->batches()->latest('id')->first();
    }

    /** @return array<string, mixed> */
    private function orderPayload(ProdOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'product' => $order->product ? ['sku' => $order->product->sku, 'name' => $order->product->name] : null,
            'qty' => (float) $order->qty,
            'uom_code' => $order->uom_code,
            'status' => $order->status,
            'recipe_batch_size' => $order->recipe ? (float) $order->recipe->batch_size : null,
            'warehouse_name' => $order->warehouse?->name,
        ];
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
