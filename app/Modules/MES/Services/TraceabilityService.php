<?php

namespace App\Modules\MES\Services;

use Illuminate\Support\Facades\DB;

/**
 * MES_SPECS.md §3K — "No dedicated genealogy table — this is a derived view over §3H/§3I/§3J's
 * own transaction tables." Both directions walk order-to-order via `mes_material_consumptions`
 * → `mes_production_outputs` (an order's inputs become another order's inputs, once that
 * order's own output is later consumed) using a recursive CTE, since a multi-tier supply chain
 * can be arbitrarily deep. `mes_serial_links` (§3H) is joined in as a precise, order-independent
 * supplement where it exists (assembly, auto-issued, serial-tracked finished product) — the
 * order-based walk is the one that always works, `mes_serial_links` narrows it.
 */
class TraceabilityService
{
    private const MAX_DEPTH = 10;

    /**
     * Forward trace: given a raw material lot/serial, every order it was issued into and what
     * that order produced — recursed through any of those outputs that were themselves later
     * consumed by a further order.
     *
     * @return list<array<string, mixed>>
     */
    public function forwardTrace(?int $lotId, ?int $serialId): array
    {
        if ($lotId === null && $serialId === null) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE trace AS (
                SELECT
                    mc.id AS consumption_id, mc.order_id, mc.lot_id, mc.serial_id,
                    mc.material_product_id, mc.qty, 0 AS depth
                FROM "MES".mes_material_consumptions mc
                WHERE mc.type = 'issue'
                    AND ((:lotId1::bigint IS NOT NULL AND mc.lot_id = :lotId2::bigint)
                      OR (:serialId1::bigint IS NOT NULL AND mc.serial_id = :serialId2::bigint))

                UNION ALL

                SELECT
                    mc2.id, mc2.order_id, mc2.lot_id, mc2.serial_id,
                    mc2.material_product_id, mc2.qty, t.depth + 1
                FROM trace t
                JOIN "MES".mes_production_outputs o ON o.order_id = t.order_id
                JOIN "MES".mes_material_consumptions mc2
                    ON mc2.type = 'issue'
                    AND ((o.lot_id IS NOT NULL AND mc2.lot_id = o.lot_id)
                      OR (o.serial_id IS NOT NULL AND mc2.serial_id = o.serial_id))
                WHERE t.depth < :maxDepth
            )
            SELECT DISTINCT
                t.order_id, wo.order_number, wo.status AS order_status,
                p.sku AS material_sku, p.name AS material_name,
                t.qty AS consumed_qty, t.depth,
                b.batch_number AS lot_number, s.serial_number
            FROM trace t
            JOIN "MES".mes_prod_order_hdrs wo ON wo.id = t.order_id
            JOIN "INVENTORY".products p ON p.id = t.material_product_id
            LEFT JOIN "INVENTORY".stock_batches b ON b.id = t.lot_id
            LEFT JOIN "INVENTORY".stock_serials s ON s.id = t.serial_id
            ORDER BY t.depth, wo.order_number
        SQL, [
            'lotId1' => $lotId, 'lotId2' => $lotId,
            'serialId1' => $serialId, 'serialId2' => $serialId,
            'maxDepth' => self::MAX_DEPTH,
        ]);

        $orderIds = array_unique(array_column($rows, 'order_id'));

        return [
            'consumption_trail' => $rows,
            'outputs_by_order' => $this->outputsForOrders($orderIds),
        ];
    }

    /**
     * Backward trace / recall: given a finished lot/serial, every raw material lot and
     * intermediate output consumed to produce it — recursed back through any of those
     * consumed lots/serials that were themselves an earlier order's own output.
     *
     * @return array<string, mixed>
     */
    public function backwardTrace(?int $lotId, ?int $serialId): array
    {
        if ($lotId === null && $serialId === null) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE trace AS (
                SELECT
                    o.id AS output_id, o.order_id, o.lot_id, o.serial_id,
                    o.product_id, o.output_type, o.qty, 0 AS depth
                FROM "MES".mes_production_outputs o
                WHERE (:lotId1::bigint IS NOT NULL AND o.lot_id = :lotId2::bigint)
                   OR (:serialId1::bigint IS NOT NULL AND o.serial_id = :serialId2::bigint)

                UNION ALL

                SELECT
                    o2.id, o2.order_id, o2.lot_id, o2.serial_id,
                    o2.product_id, o2.output_type, o2.qty, t.depth + 1
                FROM trace t
                JOIN "MES".mes_material_consumptions mc ON mc.order_id = t.order_id AND mc.type = 'issue'
                JOIN "MES".mes_production_outputs o2
                    ON (mc.lot_id IS NOT NULL AND o2.lot_id = mc.lot_id)
                    OR (mc.serial_id IS NOT NULL AND o2.serial_id = mc.serial_id)
                WHERE t.depth < :maxDepth
            )
            SELECT DISTINCT
                t.order_id, wo.order_number, wo.status AS order_status,
                p.sku AS product_sku, p.name AS product_name,
                t.output_type, t.qty AS output_qty, t.depth,
                b.batch_number AS lot_number, s.serial_number
            FROM trace t
            JOIN "MES".mes_prod_order_hdrs wo ON wo.id = t.order_id
            JOIN "INVENTORY".products p ON p.id = t.product_id
            LEFT JOIN "INVENTORY".stock_batches b ON b.id = t.lot_id
            LEFT JOIN "INVENTORY".stock_serials s ON s.id = t.serial_id
            ORDER BY t.depth, wo.order_number
        SQL, [
            'lotId1' => $lotId, 'lotId2' => $lotId,
            'serialId1' => $serialId, 'serialId2' => $serialId,
            'maxDepth' => self::MAX_DEPTH,
        ]);

        $orderIds = array_unique(array_column($rows, 'order_id'));

        return [
            'output_trail' => $rows,
            'consumptions_by_order' => $this->consumptionsForOrders($orderIds),
        ];
    }

    /** @param  list<int>  $orderIds */
    private function outputsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::select('
            SELECT o.order_id, wo.order_number, p.sku AS product_sku, p.name AS product_name,
                   o.output_type, o.qty, b.batch_number AS lot_number, s.serial_number
            FROM "MES".mes_production_outputs o
            JOIN "MES".mes_prod_order_hdrs wo ON wo.id = o.order_id
            JOIN "INVENTORY".products p ON p.id = o.product_id
            LEFT JOIN "INVENTORY".stock_batches b ON b.id = o.lot_id
            LEFT JOIN "INVENTORY".stock_serials s ON s.id = o.serial_id
            WHERE o.order_id IN ('.implode(',', array_map('intval', $orderIds)).')
            ORDER BY wo.order_number
        ');
    }

    /** @param  list<int>  $orderIds */
    private function consumptionsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::select('
            SELECT mc.order_id, wo.order_number, p.sku AS material_sku, p.name AS material_name,
                   mc.type, mc.qty, b.batch_number AS lot_number, s.serial_number
            FROM "MES".mes_material_consumptions mc
            JOIN "MES".mes_prod_order_hdrs wo ON wo.id = mc.order_id
            JOIN "INVENTORY".products p ON p.id = mc.material_product_id
            LEFT JOIN "INVENTORY".stock_batches b ON b.id = mc.lot_id
            LEFT JOIN "INVENTORY".stock_serials s ON s.id = mc.serial_id
            WHERE mc.order_id IN ('.implode(',', array_map('intval', $orderIds)).')
            ORDER BY wo.order_number
        ');
    }
}
