<?php

namespace App\Modules\MES\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\MES\Models\MaterialConsumption;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3J — Material Consumption. `issue` draws material out of stock
 * (`InventoryService::issue()`), `return` puts unused material back
 * (`InventoryService::receive()`) — MES never writes `INVENTORY.stock_ledger` directly (§5
 * Technical Notes). Valuation of a production return is out of scope here (posted at
 * `unit_cost = 0`) — costing a production-floor return correctly needs the original issue's
 * valuation layer, a Costing-module concern §3J itself doesn't specify.
 */
class MaterialConsumptionService
{
    public function __construct(
        protected InventoryService $inventory,
        protected ProdEventService $events,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function record(ProdOrder $order, array $data, int $userId): MaterialConsumption
    {
        $this->assertOrderIsExecutable($order);

        $product = Product::query()->findOrFail($data['material_product_id']);
        $type = $data['type'];

        return DB::transaction(function () use ($order, $data, $userId, $product, $type) {
            $lotId = $data['lot_id'] ?? null;
            $serialId = null;

            if ($type === MaterialConsumption::TYPE_ISSUE) {
                $issue = $this->inventory->issue([
                    'warehouse_id' => $order->warehouse_id,
                    'issue_date' => now()->toDateString(),
                    'subject_type' => 'mes.mes_material_consumptions',
                    'reason' => "MES {$order->order_number} material issue",
                    'lines' => [[
                        'product_id' => $product->id,
                        'batch_id' => $lotId,
                        'qty' => $data['qty'],
                        'uom_id' => $product->base_uom_id,
                        'source_location_id' => $data['location_id'],
                        'serial_numbers' => ! empty($data['serial_number']) ? [$data['serial_number']] : null,
                    ]],
                ]);
                $lotId = $issue->lines->first()?->batch_id;
                $serialId = $this->resolveSerialId($product->id, $data['serial_number'] ?? null);
            } else {
                $batchNumber = $lotId ? StockBatch::query()->find($lotId)?->batch_number : null;

                $receipt = $this->inventory->receive([
                    'warehouse_id' => $order->warehouse_id,
                    'receipt_date' => now()->toDateString(),
                    'subject_type' => 'mes.mes_material_consumptions',
                    'reference_number' => "MES {$order->order_number} material return",
                    'lines' => [[
                        'product_id' => $product->id,
                        'qty' => $data['qty'],
                        'uom_id' => $product->base_uom_id,
                        'unit_cost' => 0,
                        'destination_location_id' => $data['location_id'] ?? null,
                        'batch_number' => $batchNumber,
                    ]],
                ]);
                $lotId = $receipt->lines->first()?->batch_id;
            }

            $consumption = MaterialConsumption::query()->create([
                'order_id' => $order->id,
                'operation_ref' => $data['operation_ref'] ?? null,
                'material_product_id' => $product->id,
                'lot_id' => $lotId,
                'serial_id' => $serialId,
                'qty' => $data['qty'],
                'uom_code' => $data['uom_code'] ?? $order->uom_code,
                'type' => $type,
                'created_at' => now(),
            ]);

            $this->events->record(
                $order->id,
                $type === MaterialConsumption::TYPE_ISSUE ? ProdEvent::TYPE_MATERIAL_ISSUED : ProdEvent::TYPE_MATERIAL_RETURNED,
                ['material_product_id' => $product->id, 'sku' => $product->sku, 'qty' => (float) $data['qty']],
                $userId,
                operationRef: $data['operation_ref'] ?? null,
            );

            return $consumption;
        });
    }

    private function assertOrderIsExecutable(ProdOrder $order): void
    {
        if (! $order->warehouse_id) {
            throw ValidationException::withMessages(['warehouse_id' => 'This production order has no warehouse set — set one (Edit) before recording material movements.']);
        }
        if (! in_array($order->status, [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED], true)) {
            throw ValidationException::withMessages(['status' => 'Material can only be consumed against a released production order.']);
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
