<?php

namespace App\Modules\POS\Services;

use App\Modules\POS\Models\PosKdsStation;
use App\Modules\POS\Models\PosKdsTicketEvent;
use App\Modules\POS\Models\PosProductKdsRouting;
use App\Modules\POS\Models\PosTable;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Models\PosTxnLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS_SPECS.md §3M, §3N, §3O — Restaurant Extension Service (Floor/Table, KDS & Bill Ops).
 */
class PosRestaurantService
{
    public function __construct(
        protected PosCartService $cartService,
    ) {}

    public function openTable(int $tableId, int $sessionId, string $diningMode = PosTxnHdr::DINING_DINE_IN): PosTxnHdr
    {
        return DB::transaction(function () use ($tableId, $sessionId, $diningMode) {
            $table = PosTable::query()->findOrFail($tableId);

            if ($table->status === PosTable::STATUS_OCCUPIED) {
                // Return active transaction if exists
                $existingTxn = $table->activeTransaction;
                if ($existingTxn) {
                    return $existingTxn;
                }
            }

            $txn = $this->cartService->createDraftTransaction($sessionId, [
                'table_id' => $table->id,
                'dining_mode' => $diningMode,
            ]);

            $table->update(['status' => PosTable::STATUS_OCCUPIED]);

            return $txn;
        });
    }

    public function moveTable(int $fromTableId, int $toTableId): void
    {
        DB::transaction(function () use ($fromTableId, $toTableId) {
            $fromTable = PosTable::query()->findOrFail($fromTableId);
            $toTable = PosTable::query()->findOrFail($toTableId);

            $activeTxn = $fromTable->activeTransaction;
            if (! $activeTxn) {
                throw ValidationException::withMessages(['from_table' => ['Source table has no active order.']]);
            }

            if ($toTable->status === PosTable::STATUS_OCCUPIED && $toTable->id !== $fromTableId) {
                throw ValidationException::withMessages(['to_table' => ['Destination table is already occupied.']]);
            }

            $activeTxn->update(['table_id' => $toTable->id]);
            $fromTable->update(['status' => PosTable::STATUS_AVAILABLE]);
            $toTable->update(['status' => PosTable::STATUS_OCCUPIED]);
        });
    }

    public function mergeTables(int $sourceTableId, int $targetTableId): void
    {
        DB::transaction(function () use ($sourceTableId, $targetTableId) {
            $sourceTable = PosTable::query()->findOrFail($sourceTableId);
            $targetTable = PosTable::query()->findOrFail($targetTableId);

            $sourceTxn = $sourceTable->activeTransaction;
            $targetTxn = $targetTable->activeTransaction;

            if (! $sourceTxn || ! $targetTxn) {
                throw ValidationException::withMessages(['table' => ['Both source and target tables must have active transactions to merge.']]);
            }

            // Move lines from source to target
            $startLineNo = (int) PosTxnLine::query()->where('txn_id', $targetTxn->id)->max('line_no') + 1;
            foreach ($sourceTxn->lines as $line) {
                $line->update([
                    'txn_id' => $targetTxn->id,
                    'line_no' => $startLineNo++,
                ]);
            }

            $this->cartService->recalculateTxnTotals($targetTxn);

            $sourceTxn->update([
                'status' => PosTxnHdr::STATUS_CANCELLED,
                'notes' => "Merged into transaction #{$targetTxn->id} (Receipt: {$targetTxn->receipt_number})",
            ]);

            $sourceTable->update(['status' => PosTable::STATUS_AVAILABLE]);
        });
    }

    public function routeToKds(PosTxnHdr $txn): void
    {
        DB::transaction(function () use ($txn) {
            $lines = $txn->lines()->whereNull('kds_status')->get();

            foreach ($lines as $line) {
                $stationId = $line->kds_station_id;

                if (! $stationId && $line->product_id) {
                    $routing = PosProductKdsRouting::query()
                        ->where('product_id', $line->product_id)
                        ->first();
                    $stationId = $routing?->kds_station_id;
                }

                if (! $stationId) {
                    // Fallback to first active KDS station
                    $stationId = PosKdsStation::query()->value('id');
                }

                if ($stationId) {
                    $line->update([
                        'kds_station_id' => $stationId,
                        'kds_status' => PosTxnLine::KDS_NEW,
                    ]);

                    PosKdsTicketEvent::query()->create([
                        'txn_line_id' => $line->id,
                        'status' => PosKdsTicketEvent::STATUS_NEW,
                        'user_id' => auth()->id(),
                        'occurred_at' => now(),
                    ]);
                }
            }
        });
    }

    public function updateKdsLineStatus(int $lineId, string $status, ?int $userId = null, ?string $note = null): PosTxnLine
    {
        return DB::transaction(function () use ($lineId, $status, $userId, $note) {
            $line = PosTxnLine::query()->findOrFail($lineId);

            $kdsStatus = $status === PosKdsTicketEvent::STATUS_REFIRED ? PosTxnLine::KDS_NEW : $status;

            $line->update(['kds_status' => $kdsStatus]);

            PosKdsTicketEvent::query()->create([
                'txn_line_id' => $line->id,
                'status' => $status,
                'user_id' => $userId ?: auth()->id(),
                'note' => $note,
                'occurred_at' => now(),
            ]);

            return $line->refresh();
        });
    }

    public function getKdsQueue(?int $stationId = null): array
    {
        $query = PosTxnLine::query()
            ->with(['transaction.table', 'product', 'modifiers'])
            ->whereNotNull('kds_status')
            ->whereIn('kds_status', [PosTxnLine::KDS_NEW, PosTxnLine::KDS_PREPARING, PosTxnLine::KDS_READY]);

        if ($stationId !== null) {
            $query->where('kds_station_id', $stationId);
        }

        return $query->orderBy('id')->get()->toArray();
    }
}
