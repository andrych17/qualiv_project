<?php

namespace App\Modules\MES\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3J — Production Output. Every output row (finished/co-product/by-product/
 * waste alike — §3J: "a lightweight 'waste' product category is sufficient, no MES-specific
 * product concept") posts through `InventoryService::receive()`, which mints a fresh lot/
 * serial at receipt time for a batch-/serial-tracked product (§3J Rules/Logic). Standard-cost
 * valuation of production output is out of scope here (posted at `unit_cost = 0`) — §3J's own
 * text doesn't specify a costing rule, and a real one needs BOM/Recipe cost rollup, a Costing-
 * module concern beyond this section.
 */
class ProductionOutputService
{
    public function __construct(
        protected InventoryService $inventory,
        protected ProdEventService $events,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function record(ProdOrder $order, array $data, int $userId): ProductionOutput
    {
        $this->assertOrderIsExecutable($order);

        $product = Product::query()->findOrFail($data['product_id']);

        return DB::transaction(function () use ($order, $data, $userId, $product) {
            $receipt = $this->inventory->receive([
                'warehouse_id' => $order->warehouse_id,
                'receipt_date' => now()->toDateString(),
                'subject_type' => 'mes.mes_production_outputs',
                'reference_number' => "MES {$order->order_number} {$data['output_type']} output",
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => $data['qty'],
                    'uom_id' => $product->base_uom_id,
                    'unit_cost' => 0,
                    'destination_location_id' => $data['location_id'] ?? null,
                    'batch_number' => $data['lot_number'] ?? null,
                    'serial_numbers' => ! empty($data['serial_number']) ? [$data['serial_number']] : null,
                ]],
            ]);

            $lotId = $receipt->lines->first()?->batch_id;
            $serialId = $this->resolveSerialId($product->id, $data['serial_number'] ?? null);

            $isWaste = $data['output_type'] === ProductionOutput::TYPE_WASTE;

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id,
                'operation_ref' => $data['operation_ref'] ?? null,
                'output_type' => $data['output_type'],
                'product_id' => $product->id,
                'qty' => $data['qty'],
                'uom_code' => $data['uom_code'] ?? $order->uom_code,
                'lot_id' => $lotId,
                'serial_id' => $serialId,
                'reason_code' => $isWaste ? ($data['reason_code'] ?? null) : null,
                'disposition' => $isWaste ? ($data['disposition'] ?? null) : null,
                'created_at' => now(),
            ]);

            $this->events->record(
                $order->id,
                ProdEvent::TYPE_OUTPUT_PRODUCED,
                ['output_type' => $data['output_type'], 'product_id' => $product->id, 'sku' => $product->sku, 'qty' => (float) $data['qty']],
                $userId,
                operationRef: $data['operation_ref'] ?? null,
            );

            return $output;
        });
    }

    private function assertOrderIsExecutable(ProdOrder $order): void
    {
        if (! $order->warehouse_id) {
            throw ValidationException::withMessages(['warehouse_id' => 'This production order has no warehouse set — set one (Edit) before recording production output.']);
        }
        if (! in_array($order->status, [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED], true)) {
            throw ValidationException::withMessages(['status' => 'Output can only be recorded against a released production order.']);
        }
    }

    private function resolveSerialId(int $productId, ?string $serialNumber): ?int
    {
        if (! $serialNumber) {
            return null;
        }

        return StockSerial::query()->where('product_id', $productId)->where('serial_number', $serialNumber)->value('id');
    }
}
