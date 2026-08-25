<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Validation\ValidationException;

/**
 * §3L — batches are created two ways: implicitly, find-or-create'd from a free-text lot
 * number on a Goods Receipt / Adjustment-in line (`resolve()`); or explicitly, via the
 * Batches admin screen for pre-registering a lot before stock against it exists. Either path
 * ends at the same `stock_batches` row, so Issue/Transfer's "pick an existing batch" selects
 * see it regardless of how it was created.
 */
class BatchService
{
    /**
     * Finds this product's batch by lot number, or creates it. Expiry/manufacture/supplier
     * are set only on create — an existing lot's metadata isn't silently overwritten by
     * whatever a later receipt line happened to type into those optional fields.
     */
    public function resolve(int $productId, string $batchNumber, ?string $expiryDate = null, ?string $manufactureDate = null, ?string $supplierReference = null): StockBatch
    {
        $batch = StockBatch::query()->where('product_id', $productId)->where('batch_number', $batchNumber)->first();
        if ($batch) {
            return $batch;
        }

        return StockBatch::query()->create([
            'product_id' => $productId,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'manufacture_date' => $manufactureDate,
            'supplier_reference' => $supplierReference,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): StockBatch
    {
        $this->assertUnique($data['product_id'], $data['batch_number']);

        return StockBatch::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(StockBatch $batch, array $data): StockBatch
    {
        if ($data['batch_number'] !== $batch->batch_number) {
            $this->assertUnique($batch->product_id, $data['batch_number'], $batch->id);
        }

        $batch->update([
            'batch_number' => $data['batch_number'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'manufacture_date' => $data['manufacture_date'] ?? null,
            'supplier_reference' => $data['supplier_reference'] ?? null,
        ]);

        return $batch->refresh();
    }

    private function assertUnique(int $productId, string $batchNumber, ?int $excludeId = null): void
    {
        $exists = StockBatch::query()
            ->where('product_id', $productId)
            ->where('batch_number', $batchNumber)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['batch_number' => 'This lot number is already used by this product.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'product_id' => $data['product_id'],
            'batch_number' => $data['batch_number'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'manufacture_date' => $data['manufacture_date'] ?? null,
            'supplier_reference' => $data['supplier_reference'] ?? null,
        ];
    }
}
