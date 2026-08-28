<?php

namespace App\Modules\Inventory\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBarcode;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\UomConversion;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public const ENTITY = 'inventory_product';

    public function __construct(
        protected CustomFieldService $customFields,
        protected ConfigService $config,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Product
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($data, $custom) {
            $product = Product::query()->create($this->productAttributes($data));

            $this->syncBarcodes($product, $data['barcodes'] ?? []);
            $this->syncUomConversions($product, $data['uom_conversions'] ?? []);
            $this->customFields->sync(self::ENTITY, $product->id, $custom);

            return $product;
        });
    }

    /**
     * §3B: changing `costing_method` OR `tracking_mode` on a product with open valuation
     * layers is blocked — both switches corrupt costing mid-flight for the same reason
     * (e.g. AverageStrategy expects exactly one open layer per product/warehouse/batch, but
     * flipping `tracking_mode` after receipts already exist strands stock under the old
     * batch-scoping, and a costing_method flip has the analogous FIFO/Average mismatch).
     * Fully consumed (remaining_qty = 0) layers don't block it — they're immutable history,
     * untouched by either strategy, so there's nothing left for a switch to corrupt.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        $newMethod = $data['costing_method'] ?? null;
        $newTracking = $data['tracking_mode'] ?? null;
        $costingChanged = ! empty($newMethod) && $newMethod !== $product->costing_method;
        $trackingChanged = ! empty($newTracking) && $newTracking !== $product->tracking_mode;

        if (($costingChanged || $trackingChanged) && $this->hasOpenValuationLayers($product)) {
            $field = $trackingChanged ? 'tracking_mode' : 'costing_method';
            $setting = $trackingChanged ? 'tracking mode' : 'costing method';
            throw ValidationException::withMessages([
                $field => "This product has open stock — issue out all remaining quantity before changing its {$setting}.",
            ]);
        }

        return DB::transaction(function () use ($product, $data, $custom) {
            $product->update($this->productAttributes($data, $product));

            $this->syncBarcodes($product, $data['barcodes'] ?? []);
            $this->syncUomConversions($product, $data['uom_conversions'] ?? []);
            $this->customFields->sync(self::ENTITY, $product->id, $custom);

            return $product->refresh();
        });
    }

    /**
     * §3B: deactivating a product blocks new receipts/issues but never hides historical
     * ledger entries — enforced by Goods Receipt/Issue (§3D/§3E) once those ship; here it's
     * only the master-data flag, never a row delete (stock_ledger will FK against this row).
     */
    public function deactivate(Product $product): void
    {
        $product->update(['is_active' => false]);
    }

    private function hasOpenValuationLayers(Product $product): bool
    {
        return StockValuationLayer::query()
            ->where('product_id', $product->id)
            ->where('remaining_qty', '>', 0.00005)
            ->exists();
    }

    /** @param  array<string, mixed>  $data */
    private function productAttributes(array $data, ?Product $existing = null): array
    {
        $costingMethod = $data['costing_method'] ?? null;
        if (empty($costingMethod)) {
            $costingMethod = $existing?->costing_method
                ?? $this->config->get('INVENTORY', 'DEFAULT_COSTING_METHOD')
                ?? Product::COSTING_FIFO;
        }

        return [
            'sku' => $data['sku'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category_id' => empty($data['category_id']) ? null : $data['category_id'],
            'base_uom_id' => $data['base_uom_id'],
            'costing_method' => $costingMethod,
            'reorder_point' => $data['reorder_point'] ?? 0,
            'reorder_quantity' => $data['reorder_quantity'] ?? 0,
            'tracking_mode' => $data['tracking_mode'] ?? Product::TRACKING_NONE,
            'abc_class' => $data['abc_class'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncBarcodes(Product $product, array $rows): void
    {
        $rows = array_values(array_filter($rows, fn ($row) => ! empty($row['barcode'])));

        $values = array_map(fn ($row) => $row['barcode'], $rows);
        if (count($values) !== count(array_unique($values))) {
            throw ValidationException::withMessages(['barcodes' => 'Duplicate barcode value within this product.']);
        }

        if (count(array_filter($rows, fn ($row) => ($row['type'] ?? null) === ProductBarcode::TYPE_PRIMARY)) > 1) {
            throw ValidationException::withMessages(['barcodes' => 'Only one primary barcode is allowed per product.']);
        }

        if ($values !== [] && ProductBarcode::query()->whereIn('barcode', $values)->where('product_id', '!=', $product->id)->exists()) {
            throw ValidationException::withMessages(['barcodes' => 'One or more barcodes are already used by another product.']);
        }

        ProductBarcode::query()->where('product_id', $product->id)->delete();

        foreach ($rows as $row) {
            ProductBarcode::query()->create([
                'product_id' => $product->id,
                'barcode' => $row['barcode'],
                'type' => $row['type'] ?? ProductBarcode::TYPE_PRIMARY,
                'unit_multiplier' => $row['unit_multiplier'] ?? 1,
            ]);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncUomConversions(Product $product, array $rows): void
    {
        $rows = array_values(array_filter($rows, fn ($row) => ! empty($row['uom_id'])));

        $uomIds = array_map(fn ($row) => (int) $row['uom_id'], $rows);
        if (in_array((int) $product->base_uom_id, $uomIds, true)) {
            throw ValidationException::withMessages(['uom_conversions' => 'The base UoM cannot also be listed as an additional UoM.']);
        }
        if (count($uomIds) !== count(array_unique($uomIds))) {
            throw ValidationException::withMessages(['uom_conversions' => 'Duplicate UoM within this product.']);
        }

        UomConversion::query()->where('product_id', $product->id)->delete();

        foreach ($rows as $row) {
            UomConversion::query()->create([
                'product_id' => $product->id,
                'uom_id' => $row['uom_id'],
                'conversion_factor' => $row['conversion_factor'] ?? 1,
            ]);
        }
    }
}
