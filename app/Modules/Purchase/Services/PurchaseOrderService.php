<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurOrderLine;
use App\Modules\Purchase\Models\PurOrderRevision;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Models\VendorProfile;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        protected ?WorkflowService $workflowService = null,
    ) {
        $this->workflowService ??= app(WorkflowService::class);
    }

    public function generatePoNumber(): string
    {
        $prefix = 'PO-'.now()->format('Ym').'-';
        $maxAttempts = 50;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $latest = PurOrderHdr::query()
                ->where('po_no', 'like', "{$prefix}%")
                ->orderByDesc('po_no')
                ->value('po_no');

            $nextSeq = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
                $nextSeq = ((int) $m[1]) + 1;
            }

            $candidate = $prefix.sprintf('%04d', $nextSeq + $i);
            if (! PurOrderHdr::query()->where('po_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $prefix.substr(uniqid(), -4);
    }

    public function create(array $data, int $userId): PurOrderHdr
    {
        return DB::transaction(function () use ($data, $userId) {
            $poNo = $data['po_no'] ?? $this->generatePoNumber();
            $supplierId = (int) $data['supplier_id'];

            // Default from vendor profile if available
            $profile = VendorProfile::query()->where('partner_id', $supplierId)->first();
            $paymentTerms = $data['payment_terms_days'] ?? $profile?->payment_terms_days ?? 30;
            $incoterms = $data['incoterms'] ?? $profile?->incoterms ?? null;
            $currency = $data['currency_code'] ?? $profile?->preferred_currency ?? 'IDR';

            $lines = $data['lines'] ?? [];
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($lines as $line) {
                $lineSubtotal = ((float) ($line['qty_ordered'] ?? 0)) * ((float) ($line['unit_price'] ?? 0));
                $lineTax = (float) ($line['tax_amount'] ?? 0);
                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;
            }

            $totalAmount = $subtotal + $taxAmount;

            $po = PurOrderHdr::create([
                'po_no' => $poNo,
                'supplier_id' => $supplierId,
                'pr_id' => $data['pr_id'] ?? null,
                'rfx_id' => $data['rfx_id'] ?? null,
                'ship_to' => $data['ship_to'] ?? null,
                'bill_to' => $data['bill_to'] ?? null,
                'currency_code' => $currency,
                'incoterms' => $incoterms,
                'payment_terms_days' => $paymentTerms,
                'status' => PurOrderHdr::STATUS_DRAFT,
                'revision_no' => 1,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by' => $userId,
            ]);

            $lineNo = 1;
            foreach ($lines as $line) {
                $po->lines()->create([
                    'line_no' => $lineNo++,
                    'catalog_item_id' => $line['catalog_item_id'] ?? null,
                    'description' => $line['description'],
                    'qty_ordered' => $line['qty_ordered'],
                    'qty_received' => 0,
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $line['tax_amount'] ?? 0,
                    'expected_delivery_date' => $line['expected_delivery_date'] ?? $po->expected_delivery_date,
                    'category_id' => $line['category_id'] ?? null,
                    'local_content_pct' => $line['local_content_pct'] ?? null,
                ]);
            }

            return $po->fresh(['lines', 'supplier', 'requisition']);
        });
    }

    /**
     * §3B/§3D: Convert an approved PR directly to a PO.
     */
    public function createFromRequisition(PurRequisitionHdr $pr, array $overrides, int $userId): PurOrderHdr
    {
        if ($pr->status !== PurRequisitionHdr::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'pr' => ['Only approved requisitions can be converted to Purchase Orders.'],
            ]);
        }

        return DB::transaction(function () use ($pr, $overrides, $userId) {
            $supplierId = $overrides['supplier_id'] ?? null;
            if (! $supplierId) {
                // Check if any catalog item has preferred supplier
                foreach ($pr->lines as $line) {
                    if ($line->catalogItem?->preferred_supplier_id) {
                        $supplierId = $line->catalogItem->preferred_supplier_id;
                        break;
                    }
                }
            }

            if (! $supplierId) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['A supplier must be selected for the Purchase Order.'],
                ]);
            }

            $lines = [];
            foreach ($pr->lines as $line) {
                $lines[] = [
                    'catalog_item_id' => $line->catalog_item_id,
                    'description' => $line->description,
                    'qty_ordered' => $line->qty,
                    'unit_price' => $line->estimated_unit_price,
                    'tax_amount' => 0,
                    'expected_delivery_date' => $overrides['expected_delivery_date'] ?? $pr->needed_by?->toDateString(),
                    'category_id' => $line->category_id,
                    'local_content_pct' => $line->local_content_pct,
                ];
            }

            $poData = array_merge([
                'supplier_id' => $supplierId,
                'pr_id' => $pr->id,
                'lines' => $lines,
                'expected_delivery_date' => $pr->needed_by?->toDateString(),
            ], $overrides);

            $po = $this->create($poData, $userId);

            // Mark PR as converted
            $pr->status = PurRequisitionHdr::STATUS_CONVERTED;
            $pr->save();

            return $po;
        });
    }

    /**
     * §3D: Update PO. If already approved/sent/acknowledged, records tracked amendment snapshot.
     */
    public function update(PurOrderHdr $po, array $data, int $userId): PurOrderHdr
    {
        if (in_array($po->status, [PurOrderHdr::STATUS_CLOSED, PurOrderHdr::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot modify a closed or cancelled Purchase Order.'],
            ]);
        }

        return DB::transaction(function () use ($po, $data, $userId) {
            $isAmendment = ! in_array($po->status, [PurOrderHdr::STATUS_DRAFT, PurOrderHdr::STATUS_PENDING_APPROVAL], true);

            if ($isAmendment) {
                // Record snapshot before applying changes
                $po->loadMissing(['lines', 'supplier']);
                $snapshot = [
                    'header' => $po->only([
                        'id', 'po_no', 'supplier_id', 'pr_id', 'ship_to', 'bill_to',
                        'currency_code', 'incoterms', 'payment_terms_days', 'status',
                        'revision_no', 'subtotal', 'tax_amount', 'total_amount',
                        'expected_delivery_date', 'ack_status',
                    ]),
                    'lines' => $po->lines->map(fn ($l) => $l->only([
                        'id', 'line_no', 'catalog_item_id', 'description', 'qty_ordered',
                        'qty_received', 'unit_price', 'tax_amount', 'expected_delivery_date',
                        'category_id', 'local_content_pct',
                    ]))->toArray(),
                ];

                PurOrderRevision::create([
                    'po_id' => $po->id,
                    'revision_no' => $po->revision_no,
                    'snapshot' => $snapshot,
                    'revised_by' => $userId,
                    'revised_at' => now(),
                ]);

                $po->revision_no += 1;
            }

            $lines = $data['lines'] ?? [];
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($lines as $line) {
                $lineSubtotal = ((float) ($line['qty_ordered'] ?? 0)) * ((float) ($line['unit_price'] ?? 0));
                $lineTax = (float) ($line['tax_amount'] ?? 0);
                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;
            }
            $totalAmount = $subtotal + $taxAmount;

            $supplierId = (int) ($data['supplier_id'] ?? $po->supplier_id);

            $po->update([
                'supplier_id' => $supplierId,
                'ship_to' => $data['ship_to'] ?? $po->ship_to,
                'bill_to' => $data['bill_to'] ?? $po->bill_to,
                'currency_code' => $data['currency_code'] ?? $po->currency_code,
                'incoterms' => $data['incoterms'] ?? $po->incoterms,
                'payment_terms_days' => $data['payment_terms_days'] ?? $po->payment_terms_days,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $po->expected_delivery_date,
            ]);

            // Preserve qty_received if existing lines match by id
            $existingQtyReceived = $po->lines()->pluck('qty_received', 'id')->toArray();

            $po->lines()->delete();

            $lineNo = 1;
            foreach ($lines as $line) {
                $lineId = $line['id'] ?? null;
                $qtyReceived = $lineId && isset($existingQtyReceived[$lineId]) ? $existingQtyReceived[$lineId] : 0;

                $po->lines()->create([
                    'line_no' => $lineNo++,
                    'catalog_item_id' => $line['catalog_item_id'] ?? null,
                    'description' => $line['description'],
                    'qty_ordered' => $line['qty_ordered'],
                    'qty_received' => $qtyReceived,
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $line['tax_amount'] ?? 0,
                    'expected_delivery_date' => $line['expected_delivery_date'] ?? $po->expected_delivery_date,
                    'category_id' => $line['category_id'] ?? null,
                    'local_content_pct' => $line['local_content_pct'] ?? null,
                ]);
            }

            return $po->fresh(['lines', 'supplier', 'revisions']);
        });
    }

    public function submit(PurOrderHdr $po, int $userId): PurOrderHdr
    {
        if ($po->status !== PurOrderHdr::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft purchase orders can be submitted for approval.'],
            ]);
        }

        $po->status = PurOrderHdr::STATUS_PENDING_APPROVAL;
        $po->save();

        try {
            if ($this->workflowService) {
                $this->workflowService->start(
                    'purchase.po_approval',
                    PurOrderHdr::class,
                    $po->id,
                    [
                        'po_no' => $po->po_no,
                        'supplier_id' => $po->supplier_id,
                        'total_amount' => $po->total_amount,
                        'currency_code' => $po->currency_code,
                    ],
                    $userId
                );
            }
        } catch (WorkflowEngineException) {
            // Standalone mode: pending approval without active WNE definition
        }

        return $po;
    }

    public function approve(PurOrderHdr $po, int $userId): PurOrderHdr
    {
        if ($po->status !== PurOrderHdr::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => ['Only pending approval purchase orders can be approved.'],
            ]);
        }

        $po->status = PurOrderHdr::STATUS_APPROVED;
        $po->save();

        return $po;
    }

    public function reject(PurOrderHdr $po, int $userId, ?string $reason = null): PurOrderHdr
    {
        if ($po->status !== PurOrderHdr::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => ['Only pending approval purchase orders can be rejected.'],
            ]);
        }

        $po->status = PurOrderHdr::STATUS_DRAFT;
        $po->save();

        return $po;
    }

    public function sendToSupplier(PurOrderHdr $po, int $userId): PurOrderHdr
    {
        if (! in_array($po->status, [PurOrderHdr::STATUS_APPROVED, PurOrderHdr::STATUS_SENT], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only approved purchase orders can be sent to suppliers.'],
            ]);
        }

        $po->status = PurOrderHdr::STATUS_SENT;
        $po->save();

        return $po;
    }

    public function recordAcknowledgment(PurOrderHdr $po, string $ackStatus, ?string $notes = null, ?int $userId = null): PurOrderHdr
    {
        if (! in_array($ackStatus, [PurOrderHdr::ACK_ACCEPTED, PurOrderHdr::ACK_ACCEPTED_WITH_CHANGES, PurOrderHdr::ACK_REJECTED], true)) {
            throw ValidationException::withMessages([
                'ack_status' => ['Invalid acknowledgment status.'],
            ]);
        }

        $po->ack_status = $ackStatus;
        if ($ackStatus === PurOrderHdr::ACK_ACCEPTED) {
            $po->status = PurOrderHdr::STATUS_ACKNOWLEDGED;
        }
        $po->save();

        return $po;
    }

    public function close(PurOrderHdr $po, int $userId): PurOrderHdr
    {
        $po->status = PurOrderHdr::STATUS_CLOSED;
        $po->save();

        return $po;
    }

    /**
     * §3D: Cancelling a PO with existing receipts/invoices against it is blocked;
     * must be closed instead, to preserve the three-way-match audit trail.
     */
    public function cancel(PurOrderHdr $po, int $userId): PurOrderHdr
    {
        $hasReceivedGoods = $po->lines()->where('qty_received', '>', 0)->exists();

        if ($hasReceivedGoods) {
            throw ValidationException::withMessages([
                'status' => ['PO cannot be cancelled because goods have already been received. It must be closed instead to preserve audit trails.'],
            ]);
        }

        $po->status = PurOrderHdr::STATUS_CANCELLED;
        $po->save();

        return $po;
    }
}
