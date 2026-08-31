<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\DemandForecast;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * PP_SPECS.md §3B — additive and read-mostly: never edits a Sales order or an Inventory
 * reorder point, only reads them to compute a demand row (§3B Rules/Logic). Every write here
 * lands as one `pp_demand_hdrs` row (+lines) per source event, upserted by `subject_type`/
 * `subject_id` so re-running a sync (safety stock recalculation, a re-saved forecast) never
 * duplicates rows.
 */
class DemandAggregationService
{
    public function __construct(protected AvailabilityService $availability) {}

    // --- Forecasts (master data; each row syncs its own 1:1 demand header/line) ---

    /** @param  array<string, mixed>  $data */
    public function createForecast(array $data): DemandForecast
    {
        return DB::transaction(function () use ($data) {
            $forecast = DemandForecast::query()->create($this->forecastAttributes($data));
            $this->syncForecastDemand($forecast);

            return $forecast;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateForecast(DemandForecast $forecast, array $data): DemandForecast
    {
        return DB::transaction(function () use ($forecast, $data) {
            $forecast->update($this->forecastAttributes($data));
            $this->syncForecastDemand($forecast);

            return $forecast->refresh();
        });
    }

    public function deleteForecast(DemandForecast $forecast): void
    {
        DB::transaction(function () use ($forecast) {
            $this->deleteBySubject(DemandForecast::class, $forecast->id);
            $forecast->delete();
        });
    }

    private function syncForecastDemand(DemandForecast $forecast): void
    {
        $header = $this->upsertHeader(DemandHeader::SOURCE_FORECAST, DemandForecast::class, $forecast->id, $forecast->period_start);

        $header->lines()->delete();
        DemandLine::query()->create([
            'demand_hdr_id' => $header->id,
            'product_id' => $forecast->product_id,
            'need_by_date' => $forecast->period_start,
            'qty' => $forecast->qty,
        ]);
    }

    // --- Manual demand (planner-entered header + lines document) ---

    /** @param  array<string, mixed>  $data */
    public function createManual(array $data): DemandHeader
    {
        return DB::transaction(function () use ($data) {
            $header = DemandHeader::query()->create([
                'source_type' => DemandHeader::SOURCE_MANUAL,
                'demand_date' => $data['demand_date'],
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->syncManualLines($header, $data['lines'] ?? []);

            return $header->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateManual(DemandHeader $header, array $data): DemandHeader
    {
        return DB::transaction(function () use ($header, $data) {
            $header->update([
                'demand_date' => $data['demand_date'],
                'note' => $data['note'] ?? null,
            ]);
            $this->syncManualLines($header, $data['lines'] ?? []);

            return $header->refresh()->load('lines');
        });
    }

    public function deleteManual(DemandHeader $header): void
    {
        $header->delete();
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncManualLines(DemandHeader $header, array $lines): void
    {
        $header->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['product_id']) || empty($line['qty'])) {
                continue;
            }

            DemandLine::query()->create([
                'demand_hdr_id' => $header->id,
                'product_id' => $line['product_id'],
                'need_by_date' => $line['need_by_date'],
                'qty' => $line['qty'],
            ]);
        }
    }

    // --- Sales order sync (event-driven — SyncDemandFromSalesOrder listens for SalesOrderConfirmed) ---

    public function syncFromSalesOrder(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $header = $this->upsertHeader(DemandHeader::SOURCE_SALES_ORDER, SalesOrder::class, $order->id, now()->toDateString());

            $header->lines()->delete();
            foreach ($order->lines as $line) {
                if ($line->product_id === null) {
                    continue;
                }

                // Sales orders carry no promised/delivery date yet (SALES_SPECS.md §3F) — the
                // order's own confirmation date stands in as "needed now" until one exists.
                DemandLine::query()->create([
                    'demand_hdr_id' => $header->id,
                    'product_id' => $line->product_id,
                    'need_by_date' => now()->toDateString(),
                    'qty' => $line->qty_ordered,
                ]);
            }
        });
    }

    // --- Safety stock shortfall (on-demand recalculation) ---

    /** @return int number of item planning params whose safety-stock demand row changed */
    public function recalculateSafetyStockDemand(): int
    {
        return DB::transaction(function () {
            $changed = 0;

            foreach (ItemPlanningParam::query()->where('safety_stock_qty', '>', 0)->get() as $param) {
                $available = $this->availability->totalAvailableQty($param->product_id);
                $shortfall = (float) $param->safety_stock_qty - $available;

                if ($shortfall > 0) {
                    $header = $this->upsertHeader(DemandHeader::SOURCE_SAFETY_STOCK, ItemPlanningParam::class, $param->id, now()->toDateString());
                    $header->lines()->delete();
                    DemandLine::query()->create([
                        'demand_hdr_id' => $header->id,
                        'product_id' => $param->product_id,
                        'need_by_date' => now()->toDateString(),
                        'qty' => $shortfall,
                    ]);
                    $changed++;
                } elseif ($this->deleteBySubject(ItemPlanningParam::class, $param->id)) {
                    $changed++;
                }
            }

            return $changed;
        });
    }

    private function upsertHeader(string $sourceType, string $subjectType, int $subjectId, string $demandDate): DemandHeader
    {
        return DemandHeader::query()->updateOrCreate(
            ['subject_type' => $subjectType, 'subject_id' => $subjectId],
            ['source_type' => $sourceType, 'demand_date' => $demandDate, 'created_by' => auth()->id()],
        );
    }

    private function deleteBySubject(string $subjectType, int $subjectId): bool
    {
        $header = DemandHeader::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->first();
        if ($header === null) {
            return false;
        }

        $header->delete();

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function forecastAttributes(array $data): array
    {
        return [
            'product_id' => $data['product_id'],
            'period_start' => $data['period_start'],
            'qty' => $data['qty'],
            'source' => $data['source'] ?? DemandForecast::SOURCE_MANUAL,
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ];
    }
}
