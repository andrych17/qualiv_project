<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\Routing;
use App\Modules\MES\Models\RoutingOp;
use App\Modules\MES\Models\Station;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\BomLine;
use App\Modules\PP\Models\Recipe;
use App\Modules\PP\Models\RecipeIngredient;

/** Shared bootstrap for MES module tests — plan activation, admin login, and master-data fixtures. */
trait SetsUpMES
{
    use SetsUpInventory;

    protected function loginAsMesAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function adminUserId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    protected function makeWorkCenter(string $code = 'WC-1', array $attrs = []): WorkCenter
    {
        return WorkCenter::query()->create([
            'code' => $code,
            'name' => $attrs['name'] ?? "Work Center {$code}",
            'area_line' => $attrs['area_line'] ?? null,
            'type' => $attrs['type'] ?? WorkCenter::TYPE_DISCRETE,
        ]);
    }

    protected function makeMachine(WorkCenter $workCenter, string $code = 'M-1', array $attrs = []): Machine
    {
        return Machine::query()->create([
            'work_center_id' => $workCenter->id,
            'code' => $code,
            'name' => $attrs['name'] ?? "Machine {$code}",
            'status' => $attrs['status'] ?? Machine::STATUS_IDLE,
        ]);
    }

    protected function makeStation(string $code = 'ST-1', array $attrs = []): Station
    {
        return Station::query()->create([
            'work_center_id' => $attrs['work_center_id'] ?? null,
            'machine_id' => $attrs['machine_id'] ?? null,
            'code' => $code,
            'name' => $attrs['name'] ?? "Station {$code}",
        ]);
    }

    /** PP.pp_boms — MES only reads the active row (§3B boundary note), never writes it. */
    protected function makeBom(int $productId, array $attrs = []): Bom
    {
        return Bom::query()->create([
            'product_id' => $productId,
            'version' => $attrs['version'] ?? 1,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    /** PP.pp_recipes — MES only reads the active row (§3B boundary note), never writes it. */
    protected function makeRecipe(int $productId, array $attrs = []): Recipe
    {
        return Recipe::query()->create([
            'product_id' => $productId,
            'version' => $attrs['version'] ?? 1,
            'batch_size' => $attrs['batch_size'] ?? 100,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeRouting(int $productId, array $attrs = []): Routing
    {
        return Routing::query()->create([
            'product_id' => $productId,
            'version' => $attrs['version'] ?? 1,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeRoutingOp(Routing $routing, WorkCenter $workCenter, array $attrs = []): RoutingOp
    {
        return RoutingOp::query()->create([
            'routing_id' => $routing->id,
            'seq' => $attrs['seq'] ?? 10,
            'op_code' => $attrs['op_code'] ?? 'OP1',
            'op_name' => $attrs['op_name'] ?? 'Assemble',
            'work_center_id' => $workCenter->id,
            'setup_time_minutes' => $attrs['setup_time_minutes'] ?? 0,
            'run_time_minutes' => $attrs['run_time_minutes'] ?? 10,
            'queue_time_minutes' => $attrs['queue_time_minutes'] ?? 0,
            'standard_output_qty' => $attrs['standard_output_qty'] ?? null,
            'auto_issue_components' => $attrs['auto_issue_components'] ?? true,
            'is_rework_destination' => $attrs['is_rework_destination'] ?? false,
        ]);
    }

    protected function makeBomLine(Bom $bom, int $componentProductId, array $attrs = []): BomLine
    {
        return BomLine::query()->create([
            'bom_id' => $bom->id,
            'component_product_id' => $componentProductId,
            'qty_per_parent_unit' => $attrs['qty_per_parent_unit'] ?? 1,
            'uom_code' => $attrs['uom_code'] ?? null,
            'scrap_pct' => $attrs['scrap_pct'] ?? 0,
        ]);
    }

    protected function makeRecipeIngredient(Recipe $recipe, int $rawMaterialProductId, array $attrs = []): RecipeIngredient
    {
        return RecipeIngredient::query()->create([
            'recipe_id' => $recipe->id,
            'raw_material_product_id' => $rawMaterialProductId,
            'qty_per_batch' => $attrs['qty_per_batch'] ?? 1,
            'uom_code' => $attrs['uom_code'] ?? null,
        ]);
    }

    /** Seeds real on-hand stock via the actual InventoryService::receive() call (mirrors FacadeAndRelationsTest's own pattern) — MES's own issue path needs real stock_balances rows to draw down. */
    protected function receiveStock(Warehouse $warehouse, int $productId, float $qty, int $uomId, ?int $locationId = null, ?string $batchNumber = null, ?string $serialNumber = null): void
    {
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $productId,
                'qty' => $qty,
                'uom_id' => $uomId,
                'unit_cost' => 1,
                'destination_location_id' => $locationId,
                'batch_number' => $batchNumber,
                'serial_numbers' => $serialNumber ? [$serialNumber] : null,
            ]],
        ]);
    }
}
