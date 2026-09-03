<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3D — MRP output; release is the Planning → Execution seam (§3D Rules/Logic). */
class PlannedOrder extends Model
{
    protected $table = 'PP.pp_planned_orders';

    public const TYPE_PRODUCTION = 'production';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_TRANSFER = 'transfer';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_FIRMED = 'firmed';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'mrp_run_id', 'plan_number', 'order_type', 'product_id', 'qty', 'need_by_date',
        'source_type', 'source_id', 'bom_id', 'recipe_id', 'status', 'scenario_id',
        'released_subject_type', 'released_subject_id', 'released_at',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'need_by_date' => 'date',
        'released_at' => 'datetime',
    ];

    public function scopeBaseline(Builder $query): void
    {
        $query->whereNull('scenario_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('product', function ($query) use ($search) {
                $query->where('sku', 'ilike', '%'.$search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%');
            });
        })->when($filters['order_type'] ?? null, function ($query, $type) {
            $query->where('order_type', $type);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function mrpRun()
    {
        return $this->belongsTo(MrpRun::class, 'mrp_run_id');
    }
}
