<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Events\DeliveryShipped;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryService
{
    public function __construct(
        protected SalesOrderService $salesOrderService,
    ) {}

    /**
     * Create a delivery draft for a confirmed/partially fulfilled sales order.
     */
    public function create(array $data): Delivery
    {
        return DB::transaction(function () use ($data) {
            $order = SalesOrder::findOrFail($data['so_hdr_id']);

            if (! in_array($order->status, [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Deliveries can only be created for confirmed or partially fulfilled orders.'],
                ]);
            }

            $delivery = Delivery::create([
                'so_hdr_id' => $order->id,
                'status' => Delivery::STATUS_PENDING,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'source_location_id' => $data['source_location_id'] ?? null,
            ]);

            $lines = $data['lines'] ?? [];
            foreach ($lines as $lineData) {
                $soLine = SalesOrderLine::findOrFail($lineData['so_line_id']);
                $qtyToShip = (float) ($lineData['qty_shipped'] ?? 0);

                if ($qtyToShip <= 0) {
                    continue;
                }

                // Check remaining quantity
                $remaining = (float) $soLine->qty_ordered - (float) $soLine->qty_delivered;
                if ($qtyToShip > $remaining) {
                    throw ValidationException::withMessages([
                        'lines' => ["Quantity to ship ({$qtyToShip}) exceeds remaining unfulfilled quantity ({$remaining}) for line #{$soLine->line_no}."],
                    ]);
                }

                $delivery->lines()->create([
                    'so_line_id' => $soLine->id,
                    'qty_shipped' => $qtyToShip,
                ]);
            }

            return $delivery->load(['lines.salesOrderLine', 'order']);
        });
    }

    /**
     * Advance delivery status lifecycle.
     */
    public function updateStatus(Delivery $delivery, string $newStatus, array $extra = []): Delivery
    {
        if ($delivery->status === Delivery::STATUS_DELIVERED || $delivery->status === Delivery::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => ['Completed or cancelled deliveries cannot change status.'],
            ]);
        }

        return DB::transaction(function () use ($delivery, $newStatus, $extra) {
            $delivery->load(['lines.salesOrderLine', 'order']);

            if ($newStatus === Delivery::STATUS_SHIPPED) {
                $delivery->shipped_at = now();
                $delivery->carrier = $extra['carrier'] ?? $delivery->carrier;
                $delivery->tracking_number = $extra['tracking_number'] ?? $delivery->tracking_number;

                // Inventory integration (§3H/§5): call InventoryService::issue if available and products exist
                $this->postToInventoryIfApplicable($delivery, $extra['source_location_id'] ?? $delivery->source_location_id);

                // Update qty_delivered on sales order lines
                foreach ($delivery->lines as $dLine) {
                    $soLine = $dLine->salesOrderLine;
                    if ($soLine) {
                        $soLine->increment('qty_delivered', (float) $dLine->qty_shipped);
                    }
                }

                // Recalculate order fulfillment status
                $this->salesOrderService->refreshFulfillmentStatus($delivery->order);

                event(new DeliveryShipped($delivery));
            } elseif ($newStatus === Delivery::STATUS_DELIVERED) {
                $delivery->delivered_at = now();
            }

            $delivery->status = $newStatus;
            $delivery->save();

            return $delivery->refresh()->load(['lines.salesOrderLine', 'order']);
        });
    }

    protected function postToInventoryIfApplicable(Delivery $delivery, ?int $locationId): void
    {
        // Only run if Inventory module service is resolvable in container
        if (! class_exists(InventoryService::class)) {
            return;
        }

        try {
            $inventoryService = app(InventoryService::class);
            $issueLines = [];

            foreach ($delivery->lines as $dLine) {
                $soLine = $dLine->salesOrderLine;
                if ($soLine && $soLine->item_type === 'product' && $soLine->product_id) {
                    $issueLines[] = [
                        'product_id' => $soLine->product_id,
                        'qty' => (float) $dLine->qty_shipped,
                        'location_id' => $locationId,
                    ];
                }
            }

            if (! empty($issueLines)) {
                $issue = $inventoryService->issue([
                    'movement_date' => now()->toDateString(),
                    'reason' => 'Sales Delivery '.$delivery->uuid,
                    'subject_type' => 'sales.dlv_hdrs',
                    'subject_id' => $delivery->id,
                    'lines' => $issueLines,
                ]);

                $delivery->inventory_goods_issue_id = $issue->id;
            }
        } catch (\Throwable $e) {
            // If Inventory issue throws validation (e.g. out of stock), let it bubble or rethrow
            throw $e;
        }
    }
}
