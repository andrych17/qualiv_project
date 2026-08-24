<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §3H — maps an Inventory item (override) or category (default) to the GL accounts a
 * movement posts against. `inventory_item_id`/`inventory_category_id` are unconstrained
 * soft references, not Eloquent relations — see the migration docblock for why. Exactly one
 * of the two is set per row; InventoryGlMappingService enforces that, not this model.
 */
class InventoryGlMapping extends Model
{
    protected $table = 'ACCOUNTING.inventory_gl_mappings';

    protected $fillable = [
        'uuid', 'company_id', 'inventory_item_id', 'inventory_category_id',
        'inventory_asset_account_id', 'cogs_account_id', 'grni_account_id', 'adjustment_account_id',
        'created_by',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function inventoryAssetAccount()
    {
        return $this->belongsTo(Account::class, 'inventory_asset_account_id');
    }

    public function cogsAccount()
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    public function grniAccount()
    {
        return $this->belongsTo(Account::class, 'grni_account_id');
    }

    public function adjustmentAccount()
    {
        return $this->belongsTo(Account::class, 'adjustment_account_id');
    }
}
