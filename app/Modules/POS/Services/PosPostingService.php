<?php

namespace App\Modules\POS\Services;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POS_SPECS.md §3J, §3K, §3P — POS Posting Engine (Inventory Issue & AR Accounting Boundary).
 */
class PosPostingService
{
    public function postToInventory(PosTxnHdr $txn): void
    {
        $terminal = $txn->terminal;
        $warehouseId = $terminal->warehouse_id;

        $unpostedLines = $txn->lines()
            ->where('inventory_posted', false)
            ->whereNotNull('product_id')
            ->get();

        if ($unpostedLines->isEmpty()) {
            return;
        }

        // Check default source location for warehouse
        $defaultLocation = Location::query()
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->first();

        $issueLines = [];
        $allowOversell = (ConfigConst::query()
            ->where('const_group', 'POS')
            ->where('group_code', 'POS_ALLOW_OVERSELL')
            ->value('value') ?: 'Y') === 'Y';

        foreach ($unpostedLines as $line) {
            $product = Product::query()->with('baseUom')->find($line->product_id);
            if (! $product) {
                continue;
            }

            // Recipe consumption (§3P): check if product has recipe in PP schema
            $recipeExists = DB::table('information_schema.tables')
                ->where('table_schema', 'PP')
                ->where('table_name', 'recipes')
                ->exists();

            if ($recipeExists) {
                $recipe = DB::table('PP.recipes')
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->first();

                if ($recipe) {
                    $ingredients = DB::table('PP.recipe_ingredients')
                        ->where('recipe_id', $recipe->id)
                        ->get();

                    foreach ($ingredients as $ing) {
                        $qtyNeeded = (float) $ing->qty * (float) $line->qty;
                        $ingProduct = Product::query()->find($ing->product_id);
                        if ($ingProduct) {
                            $issueLines[] = [
                                'product_id' => $ingProduct->id,
                                'qty' => $qtyNeeded,
                                'uom_id' => $ing->uom_id ?? $ingProduct->base_uom_id,
                                'source_location_id' => $defaultLocation?->id,
                            ];
                        }
                    }
                    continue;
                }
            }

            // Standard product issue
            $issueLines[] = [
                'product_id' => $product->id,
                'qty' => (float) $line->qty,
                'uom_id' => $product->base_uom_id,
                'batch_id' => $line->batch_id,
                'source_location_id' => $defaultLocation?->id,
                'serial_numbers' => $line->serial_id ? [$line->serial?->serial_number] : null,
            ];
        }

        if (! empty($issueLines)) {
            try {
                if (app()->bound(InventoryService::class)) {
                    app(InventoryService::class)->issue([
                        'warehouse_id' => $warehouseId,
                        'issue_date' => now()->toDateString(),
                        'reason' => 'pos_sale',
                        'subject_type' => 'pos.pos_txn_hdrs',
                        'subject_id' => $txn->id,
                        'lines' => $issueLines,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("POS inventory issue for txn #{$txn->id} deferred/warning: ".$e->getMessage());
                if (! $allowOversell) {
                    throw $e;
                }
            }
        }

        // Mark lines as posted
        $txn->lines()->whereIn('id', $unpostedLines->pluck('id'))->update(['inventory_posted' => true]);
    }

    public function postToAccounting(PosTxnHdr $txn): void
    {
        // §3J AR Boundary:
        // Walk-in transactions are summarized at session-close journal entry.
        // On-account transactions for named customers route through Sales/AR.
        if ($txn->is_on_account && $txn->customer_id) {
            // Check if Sales module is available
            $salesOrderExists = DB::table('information_schema.tables')
                ->where('table_schema', 'SALES')
                ->where('table_name', 'so_hdrs')
                ->exists();

            if ($salesOrderExists) {
                $soId = DB::table('SALES.so_hdrs')->insertGetId([
                    'order_number' => "SO-POS-{$txn->receipt_number}",
                    'customer_id' => $txn->customer_id,
                    'order_date' => now()->toDateString(),
                    'status' => 'confirmed',
                    'subtotal' => $txn->subtotal,
                    'tax_amount' => $txn->tax_total,
                    'discount_amount' => $txn->discount_total,
                    'grand_total' => $txn->grand_total,
                    'notes' => "Auto-generated from POS Receipt {$txn->receipt_number}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $txn->update(['sales_order_subject_id' => $soId]);
            }
        }
    }
}
