<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3G — Indonesian fiscal tax classification (Kelompok 1-4, Bangunan Permanen/Non-Permanen), tenant-editable. */
class AssetGroup extends Model
{
    protected $table = 'ACCOUNTING.fa_asset_groups';

    protected $fillable = [
        'company_id', 'code', 'name', 'is_building',
        'fiscal_useful_life_months', 'fiscal_straight_line_rate', 'fiscal_declining_rate', 'is_active',
    ];

    protected $casts = [
        'is_building' => 'boolean',
        'is_active' => 'boolean',
        'fiscal_straight_line_rate' => 'decimal:4',
        'fiscal_declining_rate' => 'decimal:4',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assets()
    {
        return $this->hasMany(FixedAsset::class, 'asset_group_id');
    }
}
