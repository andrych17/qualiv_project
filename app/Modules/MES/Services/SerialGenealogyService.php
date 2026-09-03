<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\MaterialConsumption;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\SerialLink;

/**
 * MES_SPECS.md §3H — records which components went into which finished serial. Linked at the
 * point a finished serial is actually minted (Production Output, §3J) rather than at each
 * individual consumption, since that's the only point genealogy is well-defined: every
 * not-yet-linked `issue`-type material consumption on the order is treated as having fed the
 * serial that just completed. Correct for this build's one-serial-at-a-time completion
 * discipline (OperationExecutionService/BatchExecutionService both enforce qty = 1 for a
 * serial-tracked finished product); a multi-unit-per-completion model would need a different
 * allocation rule.
 */
class SerialGenealogyService
{
    public function linkOrderConsumptionsToSerial(ProdOrder $order, int $serialId, ?int $operationRef): void
    {
        $alreadyLinkedLotIds = SerialLink::query()->where('order_id', $order->id)->whereNotNull('component_lot_id')->pluck('component_lot_id');
        $alreadyLinkedSerialIds = SerialLink::query()->where('order_id', $order->id)->whereNotNull('component_serial_id')->pluck('component_serial_id');

        $unlinked = MaterialConsumption::query()
            ->where('order_id', $order->id)
            ->where('type', MaterialConsumption::TYPE_ISSUE)
            ->where(function ($query) use ($alreadyLinkedLotIds, $alreadyLinkedSerialIds) {
                $query->whereNotNull('lot_id')->whereNotIn('lot_id', $alreadyLinkedLotIds)
                    ->orWhere(function ($query) use ($alreadyLinkedSerialIds) {
                        $query->whereNotNull('serial_id')->whereNotIn('serial_id', $alreadyLinkedSerialIds);
                    })
                    ->orWhere(function ($query) {
                        $query->whereNull('lot_id')->whereNull('serial_id');
                    });
            })
            ->get();

        foreach ($unlinked as $consumption) {
            // §3H's own CHECK constraint requires a lot or serial reference — a plain
            // (untracked) component consumption has neither, so it has nothing to genealogy-link.
            if ($consumption->lot_id === null && $consumption->serial_id === null) {
                continue;
            }

            SerialLink::query()->create([
                'serial_id' => $serialId,
                'component_serial_id' => $consumption->serial_id,
                'component_lot_id' => $consumption->lot_id,
                'material_product_id' => $consumption->material_product_id,
                'order_id' => $order->id,
                'operation_ref' => $operationRef,
                'created_at' => now(),
            ]);
        }
    }
}
