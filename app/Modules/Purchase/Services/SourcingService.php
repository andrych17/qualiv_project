<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Models\PurRfxHdr;
use App\Modules\Purchase\Models\PurRfxInvitation;
use App\Modules\Purchase\Models\PurRfxLine;
use App\Modules\Purchase\Models\PurRfxResponse;
use App\Modules\Purchase\Models\PurRfxResponseLine;
use App\Modules\WorkflowEngine\Exceptions\WorkflowEngineException;
use App\Modules\WorkflowEngine\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SourcingService
{
    public function __construct(
        protected PurchaseOrderService $poService,
    ) {}

    public function generateRfxNo(): string
    {
        $prefix = 'RFQ-'.date('Ym').'-';

        $lastRfx = PurRfxHdr::query()
            ->where('rfx_no', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $seq = 1;
        if ($lastRfx && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $lastRfx->rfx_no, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): PurRfxHdr
    {
        return DB::transaction(function () use ($data, $userId) {
            $rfxNo = $this->generateRfxNo();

            $rfx = PurRfxHdr::create([
                'rfx_no' => $rfxNo,
                'type' => $data['type'] ?? PurRfxHdr::TYPE_RFQ,
                'pr_id' => $data['pr_id'] ?? null,
                'due_date' => $data['due_date'],
                'status' => PurRfxHdr::STATUS_DRAFT,
                'created_by' => $userId,
            ]);

            // Create Lines
            foreach ($data['lines'] as $idx => $line) {
                PurRfxLine::create([
                    'rfx_id' => $rfx->id,
                    'line_no' => $idx + 1,
                    'description' => $line['description'],
                    'qty' => (float) $line['qty'],
                ]);
            }

            // Create Invitations
            if (! empty($data['suppliers'])) {
                foreach ($data['suppliers'] as $supplierId) {
                    PurRfxInvitation::create([
                        'rfx_id' => $rfx->id,
                        'supplier_id' => $supplierId,
                    ]);
                }
            }

            return $rfx->load(['lines', 'invitations.supplier']);
        });
    }

    /** @param array<int> $supplierIds */
    public function createFromRequisition(PurRequisitionHdr $pr, array $supplierIds, string $dueDate, int $userId): PurRfxHdr
    {
        $lines = $pr->lines->map(fn ($l) => [
            'description' => $l->description,
            'qty' => (float) $l->quantity,
        ])->toArray();

        return $this->create([
            'pr_id' => $pr->id,
            'due_date' => $dueDate,
            'lines' => $lines,
            'suppliers' => $supplierIds,
        ], $userId);
    }

    public function sendToSuppliers(PurRfxHdr $rfx): PurRfxHdr
    {
        $rfx->status = PurRfxHdr::STATUS_RESPONSES_OPEN;
        $rfx->save();

        // Optional notification dispatch
        try {
            if (class_exists(WorkflowService::class) && app()->bound(WorkflowService::class)) {
                app(WorkflowService::class)->start(
                    'purchase.rfx_dispatched',
                    'purchase.pur_rfx_hdrs',
                    $rfx->id,
                    $rfx->created_by
                );
            }
        } catch (WorkflowEngineException) {
            // Graceful fallback
        }

        return $rfx->refresh();
    }

    /**
     * Records a quote from an invited supplier (§3C).
     *
     * @param  array<int, array{price: float, lead_time_days?: int, notes?: string}>  $lineQuotes
     */
    public function recordResponse(PurRfxInvitation $invitation, array $lineQuotes, ?string $notes = null): PurRfxResponse
    {
        return DB::transaction(function () use ($invitation, $lineQuotes, $notes) {
            $response = PurRfxResponse::firstOrCreate([
                'invitation_id' => $invitation->id,
            ], [
                'notes' => $notes,
            ]);

            foreach ($lineQuotes as $rfxLineId => $quote) {
                PurRfxResponseLine::updateOrCreate([
                    'response_id' => $response->id,
                    'rfx_line_id' => $rfxLineId,
                ], [
                    'price' => (float) $quote['price'],
                    'lead_time_days' => $quote['lead_time_days'] ?? null,
                    'notes' => $quote['notes'] ?? null,
                ]);
            }

            $invitation->responded_at = now();
            $invitation->save();

            if ($invitation->rfx->status === PurRfxHdr::STATUS_SENT || $invitation->rfx->status === PurRfxHdr::STATUS_DRAFT) {
                $invitation->rfx->status = PurRfxHdr::STATUS_RESPONSES_OPEN;
                $invitation->rfx->save();
            }

            return $response->load('lines');
        });
    }

    /**
     * Awards winning suppliers per line and generates Purchase Orders (§3C).
     *
     * @param  array<int, int>  $lineAwards  Key: rfx_line_id, Value: awarded_supplier_id
     * @return array<PurOrderHdr>
     */
    public function awardAndGenerateOrders(PurRfxHdr $rfx, array $lineAwards, int $userId): array
    {
        return DB::transaction(function () use ($rfx, $lineAwards, $userId) {
            // 1. Update awarded supplier on each line
            foreach ($lineAwards as $lineId => $supplierId) {
                PurRfxLine::where('id', $lineId)
                    ->where('rfx_id', $rfx->id)
                    ->update(['awarded_supplier_id' => $supplierId]);
            }

            $rfx->load(['lines.responseLines.response.invitation', 'invitations']);

            // 2. Group awarded lines by supplier
            $groupedBySupplier = [];
            foreach ($rfx->lines as $line) {
                if ($line->awarded_supplier_id) {
                    $groupedBySupplier[$line->awarded_supplier_id][] = $line;
                }
            }

            if (empty($groupedBySupplier)) {
                throw new InvalidArgumentException('At least one line item must be awarded to generate Purchase Orders.');
            }

            $generatedOrders = [];

            // 3. Generate PO for each distinct winning supplier
            foreach ($groupedBySupplier as $supplierId => $lines) {
                $poLines = [];
                foreach ($lines as $line) {
                    // Find quoted price from this supplier's response
                    $quotePrice = 0;
                    foreach ($line->responseLines as $respLine) {
                        if ($respLine->response?->invitation?->supplier_id === $supplierId) {
                            $quotePrice = (float) $respLine->price;
                            break;
                        }
                    }

                    $poLines[] = [
                        'description' => $line->description,
                        'qty_ordered' => (float) $line->qty,
                        'unit_price' => $quotePrice,
                    ];
                }

                $po = $this->poService->create([
                    'supplier_id' => $supplierId,
                    'pr_id' => $rfx->pr_id,
                    'notes' => "Generated from RFQ award {$rfx->rfx_no}",
                    'lines' => $poLines,
                ], $userId);

                $generatedOrders[] = $po;
            }

            $rfx->status = PurRfxHdr::STATUS_AWARDED;
            $rfx->save();

            return $generatedOrders;
        });
    }

    public function cancel(PurRfxHdr $rfx): PurRfxHdr
    {
        $rfx->status = PurRfxHdr::STATUS_CANCELLED;
        $rfx->save();

        return $rfx->refresh();
    }
}
