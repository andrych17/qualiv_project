<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Events\QuotationConverted;
use App\Modules\Sales\Events\QuotationSent;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        protected PricingService $pricingService,
        protected SalesOrderService $salesOrderService,
    ) {}

    /**
     * Create a new draft quotation (revision 1).
     */
    public function create(array $data, ?int $userId): Quotation
    {
        return DB::transaction(function () use ($data, $userId) {
            $quoteGroupId = (string) Str::uuid();

            $priceListId = $data['price_list_id'] ?? null;
            if (! $priceListId && ! empty($data['customer_id'])) {
                $resolved = $this->pricingService->resolvePriceList((int) $data['customer_id']);
                $priceListId = $resolved?->id;
            }

            $quote = Quotation::create([
                'quote_group_id' => $quoteGroupId,
                'revision_no' => 1,
                'customer_id' => $data['customer_id'],
                'opportunity_id' => $data['opportunity_id'] ?? null,
                'price_list_id' => $priceListId,
                'validity_date' => $data['validity_date'] ?? null,
                'status' => Quotation::STATUS_DRAFT,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncLines($quote, $data['lines'] ?? []);

            return $quote->load(['lines', 'customer', 'opportunity', 'priceList']);
        });
    }

    /**
     * Update quotation. If in draft, updates in-place. If sent/approved, creates a new immutable revision.
     */
    public function updateOrRevise(Quotation $quote, array $data, ?int $userId): Quotation
    {
        if ($quote->status === Quotation::STATUS_DRAFT) {
            return DB::transaction(function () use ($quote, $data) {
                $quote->update([
                    'customer_id' => $data['customer_id'] ?? $quote->customer_id,
                    'opportunity_id' => $data['opportunity_id'] ?? $quote->opportunity_id,
                    'price_list_id' => $data['price_list_id'] ?? $quote->price_list_id,
                    'validity_date' => $data['validity_date'] ?? $quote->validity_date,
                    'subject_type' => $data['subject_type'] ?? $quote->subject_type,
                    'subject_id' => $data['subject_id'] ?? $quote->subject_id,
                ]);

                if (isset($data['lines'])) {
                    $quote->lines()->delete();
                    $this->syncLines($quote, $data['lines']);
                }

                return $quote->refresh()->load(['lines', 'customer', 'opportunity', 'priceList']);
            });
        }

        // Sent / approved quotes: create new revision (never overwrite)
        return DB::transaction(function () use ($quote, $data, $userId) {
            $latestRevision = Quotation::where('quote_group_id', $quote->quote_group_id)
                ->max('revision_no') ?? 1;

            $newRevision = Quotation::create([
                'quote_group_id' => $quote->quote_group_id,
                'revision_no' => $latestRevision + 1,
                'customer_id' => $data['customer_id'] ?? $quote->customer_id,
                'opportunity_id' => $data['opportunity_id'] ?? $quote->opportunity_id,
                'price_list_id' => $data['price_list_id'] ?? $quote->price_list_id,
                'validity_date' => $data['validity_date'] ?? $quote->validity_date,
                'status' => Quotation::STATUS_DRAFT,
                'subject_type' => $data['subject_type'] ?? $quote->subject_type,
                'subject_id' => $data['subject_id'] ?? $quote->subject_id,
                'created_by' => $userId,
            ]);

            $lines = $data['lines'] ?? $quote->lines->map(fn ($l) => [
                'item_type' => $l->item_type,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'discount_amount' => $l->discount_amount,
                'tax_amount' => $l->tax_amount,
            ])->toArray();

            $this->syncLines($newRevision, $lines);

            return $newRevision->load(['lines', 'customer', 'opportunity', 'priceList']);
        });
    }

    /**
     * Send quotation to customer.
     */
    public function send(Quotation $quote): Quotation
    {
        if (! in_array($quote->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => ["Only draft or approved quotations can be marked as sent."],
            ]);
        }

        $quote->update(['status' => Quotation::STATUS_SENT]);
        event(new QuotationSent($quote));

        return $quote;
    }

    /**
     * Convert quotation to Sales Order.
     */
    public function convertToOrder(Quotation $quote, ?int $userId): SalesOrder
    {
        if ($quote->status === Quotation::STATUS_CONVERTED) {
            throw ValidationException::withMessages([
                'status' => ['This quotation has already been converted to a sales order.'],
            ]);
        }

        return DB::transaction(function () use ($quote, $userId) {
            $quote->load('lines');

            $linesData = $quote->lines->map(fn ($l) => [
                'item_type' => $l->item_type,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'qty_ordered' => $l->quantity,
                'unit_price' => $l->unit_price,
                'discount_amount' => $l->discount_amount,
                'tax_amount' => $l->tax_amount,
            ])->toArray();

            $order = $this->salesOrderService->create([
                'customer_id' => $quote->customer_id,
                'quote_id' => $quote->id,
                'price_list_id' => $quote->price_list_id,
                'subject_type' => $quote->subject_type,
                'subject_id' => $quote->subject_id,
                'lines' => $linesData,
            ], $userId);

            $quote->update([
                'status' => Quotation::STATUS_CONVERTED,
                'converted_so_id' => $order->id,
            ]);

            event(new QuotationConverted($quote, $order));

            return $order;
        });
    }

    /**
     * Clone an expired quotation into a fresh draft.
     */
    public function cloneExpired(Quotation $quote, ?int $userId): Quotation
    {
        return DB::transaction(function () use ($quote, $userId) {
            $quote->load('lines');

            $newQuote = Quotation::create([
                'quote_group_id' => (string) Str::uuid(),
                'revision_no' => 1,
                'customer_id' => $quote->customer_id,
                'opportunity_id' => $quote->opportunity_id,
                'price_list_id' => $quote->price_list_id,
                'validity_date' => now()->addDays(30)->toDateString(),
                'status' => Quotation::STATUS_DRAFT,
                'subject_type' => $quote->subject_type,
                'subject_id' => $quote->subject_id,
                'created_by' => $userId,
            ]);

            $linesData = $quote->lines->map(fn ($l) => [
                'item_type' => $l->item_type,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'discount_amount' => $l->discount_amount,
                'tax_amount' => $l->tax_amount,
            ])->toArray();

            $this->syncLines($newQuote, $linesData);

            return $newQuote->load(['lines', 'customer']);
        });
    }

    private function syncLines(Quotation $quote, array $lines): void
    {
        $lineNo = 1;
        foreach ($lines as $line) {
            $qty = (float) ($line['quantity'] ?? 1);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $discount = (float) ($line['discount_amount'] ?? 0);
            $tax = (float) ($line['tax_amount'] ?? 0);
            $total = ($qty * $unitPrice);

            $quote->lines()->create([
                'line_no' => $lineNo++,
                'item_type' => $line['item_type'] ?? 'service',
                'product_id' => $line['product_id'] ?? null,
                'description' => $line['description'] ?? '',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'line_total' => $total,
            ]);
        }
    }
}
