<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Events\SalesOrderConfirmed;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(
        protected CreditService $creditService,
        protected PricingService $pricingService,
    ) {}

    public function generateSoNumber(): string
    {
        $prefix = 'SO-'.now()->format('Ym').'-';
        $maxAttempts = 50;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $latest = SalesOrder::query()
                ->where('so_number', 'like', "{$prefix}%")
                ->orderByDesc('so_number')
                ->value('so_number');

            $nextSeq = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
                $nextSeq = ((int) $m[1]) + 1;
            }

            $candidate = $prefix.sprintf('%04d', $nextSeq + $i);
            if (! SalesOrder::query()->where('so_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $prefix.substr(uniqid(), -4);
    }

    /**
     * Create a new sales order.
     */
    public function create(array $data, ?int $userId): SalesOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $soNumber = $data['so_number'] ?? $this->generateSoNumber();

            $priceListId = $data['price_list_id'] ?? null;
            if (! $priceListId && ! empty($data['customer_id'])) {
                $resolved = $this->pricingService->resolvePriceList((int) $data['customer_id']);
                $priceListId = $resolved?->id;
            }

            $order = SalesOrder::create([
                'so_number' => $soNumber,
                'customer_id' => $data['customer_id'],
                'quote_id' => $data['quote_id'] ?? null,
                'price_list_id' => $priceListId,
                'status' => SalesOrder::STATUS_DRAFT,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncLines($order, $data['lines'] ?? []);

            return $order->load(['lines', 'customer', 'quote', 'priceList']);
        });
    }

    /**
     * Generic entry point for Vertical / Core modules (§3I / §5).
     * e.g., a Legal matter billing a retainer calls this directly.
     */
    public function createFromExternalRequest(array $payload): SalesOrder
    {
        return $this->create([
            'customer_id' => $payload['customer_id'],
            'price_list_id' => $payload['price_list_id'] ?? null,
            'subject_type' => $payload['subject_type'],
            'subject_id' => $payload['subject_id'],
            'lines' => $payload['lines'],
        ], $payload['created_by'] ?? null);
    }

    /**
     * Update a draft sales order.
     */
    public function update(SalesOrder $order, array $data): SalesOrder
    {
        if ($order->status !== SalesOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft orders can be modified.'],
            ]);
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'customer_id' => $data['customer_id'] ?? $order->customer_id,
                'price_list_id' => $data['price_list_id'] ?? $order->price_list_id,
                'subject_type' => $data['subject_type'] ?? $order->subject_type,
                'subject_id' => $data['subject_id'] ?? $order->subject_id,
            ]);

            if (isset($data['lines'])) {
                $order->lines()->delete();
                $this->syncLines($order, $data['lines']);
            }

            return $order->refresh()->load(['lines', 'customer', 'quote', 'priceList']);
        });
    }

    /**
     * Confirm a sales order (§3F/§3K synchronous credit check).
     */
    public function confirm(SalesOrder $order, bool $skipCreditCheck = false): SalesOrder
    {
        if ($order->status !== SalesOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft orders can be confirmed.'],
            ]);
        }

        $order->load(['lines', 'customer']);

        if ($order->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['An order must have at least one line before confirmation.'],
            ]);
        }

        if (! $skipCreditCheck) {
            $this->creditService->check($order);
        }

        $order->update(['status' => SalesOrder::STATUS_CONFIRMED]);
        event(new SalesOrderConfirmed($order));

        return $order;
    }

    /**
     * Cancel an order.
     * Cancelling after partial fulfillment/invoicing is blocked — must go through Returns (§3F).
     */
    public function cancel(SalesOrder $order): SalesOrder
    {
        $order->load('lines');

        $deliveredQty = (float) $order->qty_delivered_total;
        $invoicedQty = (float) $order->qty_invoiced_total;

        if ($deliveredQty > 0 || $invoicedQty > 0) {
            throw ValidationException::withMessages([
                'status' => ['Order cannot be cancelled because it has already been partially fulfilled or billed. Use the Returns engine instead.'],
            ]);
        }

        $order->update(['status' => SalesOrder::STATUS_CANCELLED]);

        return $order;
    }

    /**
     * Recalculate fulfillment status based on line quantities.
     */
    public function refreshFulfillmentStatus(SalesOrder $order): void
    {
        $order->load('lines');

        $allFulfilled = true;
        $anyDelivered = false;

        foreach ($order->lines as $line) {
            if ((float) $line->qty_delivered >= (float) $line->qty_ordered) {
                $anyDelivered = true;
            } elseif ((float) $line->qty_delivered > 0) {
                $anyDelivered = true;
                $allFulfilled = false;
            } else {
                $allFulfilled = false;
            }
        }

        if ($allFulfilled && $order->lines->isNotEmpty()) {
            $order->update(['status' => SalesOrder::STATUS_FULFILLED]);
        } elseif ($anyDelivered) {
            $order->update(['status' => SalesOrder::STATUS_PARTIALLY_FULFILLED]);
        }
    }

    private function syncLines(SalesOrder $order, array $lines): void
    {
        $lineNo = 1;
        foreach ($lines as $line) {
            $qty = (float) ($line['qty_ordered'] ?? 1);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $discount = (float) ($line['discount_amount'] ?? 0);
            $tax = (float) ($line['tax_amount'] ?? 0);
            $total = ($qty * $unitPrice);

            $order->lines()->create([
                'line_no' => $lineNo++,
                'item_type' => $line['item_type'] ?? 'service',
                'product_id' => $line['product_id'] ?? null,
                'description' => $line['description'] ?? '',
                'qty_ordered' => $qty,
                'qty_delivered' => 0,
                'qty_invoiced' => 0,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'line_total' => $total,
            ]);
        }
    }
}
