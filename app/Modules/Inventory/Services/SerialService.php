<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockSerial;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * §3M — the identity registry for `tracking_mode = serial` products: creates one row per
 * unit on receipt, flips status on issue, moves location on transfer. Purely an identity/
 * location ledger — it does not participate in costing (see StockSerial's docblock).
 */
class SerialService
{
    /**
     * §3D: receipt creates N new serial rows, one per entered number. Rejects any number
     * that already exists anywhere in the tenant — a serial is a manufacturer identity, so a
     * duplicate almost always means a mis-scan/mis-type, not a legitimate second unit.
     *
     * @param  list<string>  $serialNumbers
     */
    public function receive(int $productId, array $serialNumbers, int $warehouseId, int $locationId, int $stockLedgerId): void
    {
        foreach ($serialNumbers as $serialNumber) {
            if (StockSerial::query()->where('serial_number', $serialNumber)->exists()) {
                throw ValidationException::withMessages(['lines' => "Serial number \"{$serialNumber}\" is already on record — check for a duplicate scan/entry."]);
            }
        }

        foreach ($serialNumbers as $serialNumber) {
            StockSerial::query()->create([
                'product_id' => $productId,
                'serial_number' => $serialNumber,
                'status' => StockSerial::STATUS_IN_STOCK,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'stock_ledger_id' => $stockLedgerId,
            ]);
        }
    }

    /**
     * §3E: issue must name the exact unit(s) leaving, never just a quantity. Each serial
     * must currently be in_stock at the issuing location — location goes null once issued
     * (no longer "in" any warehouse), status flips to issued.
     *
     * @param  list<string>  $serialNumbers
     */
    public function issue(int $productId, array $serialNumbers, int $warehouseId, int $locationId, int $stockLedgerId): void
    {
        $serials = $this->lockMatching($productId, $serialNumbers);

        foreach ($serials as $serial) {
            if ($serial->status !== StockSerial::STATUS_IN_STOCK) {
                throw ValidationException::withMessages(['lines' => "Serial \"{$serial->serial_number}\" is not in stock (status: {$serial->status})."]);
            }
            if ($serial->warehouse_id !== $warehouseId || $serial->location_id !== $locationId) {
                throw ValidationException::withMessages(['lines' => "Serial \"{$serial->serial_number}\" is not currently at the selected location."]);
            }
        }

        foreach ($serials as $serial) {
            $serial->update([
                'status' => StockSerial::STATUS_ISSUED,
                'warehouse_id' => null,
                'location_id' => null,
                'stock_ledger_id' => $stockLedgerId,
            ]);
        }
    }

    /**
     * §3F: a transfer moves the same units — status stays in_stock, only location changes.
     *
     * @param  list<string>  $serialNumbers
     */
    public function transfer(int $productId, array $serialNumbers, int $sourceWarehouseId, int $sourceLocationId, int $destinationWarehouseId, int $destinationLocationId, int $stockLedgerId): void
    {
        $serials = $this->lockMatching($productId, $serialNumbers);

        foreach ($serials as $serial) {
            if ($serial->status !== StockSerial::STATUS_IN_STOCK || $serial->warehouse_id !== $sourceWarehouseId || $serial->location_id !== $sourceLocationId) {
                throw ValidationException::withMessages(['lines' => "Serial \"{$serial->serial_number}\" is not currently in stock at the source location."]);
            }
        }

        foreach ($serials as $serial) {
            $serial->update([
                'warehouse_id' => $destinationWarehouseId,
                'location_id' => $destinationLocationId,
                'stock_ledger_id' => $stockLedgerId,
            ]);
        }
    }

    /** @param  list<string>  $serialNumbers @return \Illuminate\Support\Collection<int, StockSerial> */
    private function lockMatching(int $productId, array $serialNumbers): Collection
    {
        $serials = StockSerial::query()
            ->where('product_id', $productId)
            ->whereIn('serial_number', $serialNumbers)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial_number');

        foreach ($serialNumbers as $serialNumber) {
            if (! $serials->has($serialNumber)) {
                throw ValidationException::withMessages(['lines' => "Serial number \"{$serialNumber}\" was not found for this product."]);
            }
        }

        return $serials->values();
    }
}
