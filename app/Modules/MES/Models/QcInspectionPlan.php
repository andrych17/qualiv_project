<?php

namespace App\Modules\MES\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3L — a named inspection plan, optionally scoped to one product. */
class QcInspectionPlan extends Model
{
    protected $table = 'MES.mes_qc_inspection_plans';

    public $timestamps = false;

    protected $fillable = ['product_id', 'name'];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('name', 'ilike', '%'.$search.'%');
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function characteristics()
    {
        return $this->hasMany(QcCharacteristic::class, 'plan_id');
    }
}
