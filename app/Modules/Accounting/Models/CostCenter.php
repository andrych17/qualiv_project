<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3B/§3I — the platform's canonical financial cost-center dimension. */
class CostCenter extends Model
{
    protected $table = 'ACCOUNTING.cost_centers';

    public $timestamps = false;

    protected $fillable = ['company_id', 'code', 'name', 'parent_cost_center_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_cost_center_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_cost_center_id');
    }
}
