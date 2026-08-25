<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UomConversion;
use Illuminate\Validation\ValidationException;

/**
 * Costing and the ledger always operate in a product's base UoM (§3B/§3J) — a line entered
 * in an additional UoM (e.g. a receipt in Box) is converted once here before it ever
 * reaches the costing strategy or the ledger row.
 */
class UomConversionResolver
{
    /** @return array{0: float, 1: float} [base_qty, base_unit_cost] */
    public function toBaseUnits(Product $product, int $uomId, float $qty, float $unitCost): array
    {
        if ($uomId === $product->base_uom_id) {
            return [$qty, $unitCost];
        }

        $factor = UomConversion::query()
            ->where('product_id', $product->id)
            ->where('uom_id', $uomId)
            ->value('conversion_factor');

        if ($factor === null) {
            throw ValidationException::withMessages([
                'lines' => "No UoM conversion is set up for {$product->sku} in the selected unit — add one on the product first.",
            ]);
        }

        $factor = (float) $factor;

        return [$qty * $factor, $unitCost / $factor];
    }
}
