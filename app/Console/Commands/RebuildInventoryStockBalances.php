<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\StockBalanceRebuildService;
use Illuminate\Console\Command;

/**
 * §3H integrity safety net. Tenant-scoped — run per tenant via stancl's `tenants:run`, same
 * convention as dms:apply-retention-policies.
 */
class RebuildInventoryStockBalances extends Command
{
    protected $signature = 'inventory:rebuild-stock-balances {--product= : Only this product ID}';

    protected $description = 'Regenerate INVENTORY.stock_balances from stock_ledger (§3H) — a cache rebuild, never the source of truth';

    public function handle(StockBalanceRebuildService $rebuild): int
    {
        $productId = $this->option('product') ? (int) $this->option('product') : null;

        $count = $rebuild->rebuild($productId);

        $this->info("Rebuilt {$count} stock balance row(s) from the ledger.");

        return self::SUCCESS;
    }
}
