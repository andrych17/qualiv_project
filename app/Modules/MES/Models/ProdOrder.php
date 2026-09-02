<?php

namespace App\Modules\MES\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3A — single order header for both production models; `production_model` is the one branch point every other MES engine reads (§5 Technical Notes). */
class ProdOrder extends Model
{
    protected $table = 'MES.mes_prod_order_hdrs';

    public const MODEL_ASSEMBLY = 'assembly';

    public const MODEL_PROCESS = 'process';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RELEASED = 'released';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_number', 'product_id', 'production_model', 'bom_id', 'recipe_id', 'routing_id',
        'qty', 'uom_code', 'planned_start', 'planned_end', 'actual_start', 'actual_end',
        'priority', 'warehouse_id', 'line_area', 'status', 'parent_order_id', 'source_type', 'source_id',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'planned_start' => 'datetime',
        'planned_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('order_number', 'ilike', '%'.$search.'%')
                ->orWhereHas('product', function ($query) use ($search) {
                    $query->where('sku', 'ilike', '%'.$search.'%')
                        ->orWhere('name', 'ilike', '%'.$search.'%');
                });
        })->when($filters['production_model'] ?? null, function ($query, $model) {
            $query->where('production_model', $model);
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
        return $this->belongsTo(Bom::class, 'bom_id');
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function routing()
    {
        return $this->belongsTo(Routing::class, 'routing_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parentOrder()
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function events()
    {
        return $this->hasMany(ProdEvent::class, 'order_id');
    }

    public function materialConsumptions()
    {
        return $this->hasMany(MaterialConsumption::class, 'order_id');
    }

    public function productionOutputs()
    {
        return $this->hasMany(ProductionOutput::class, 'order_id');
    }

    public function serialLinks()
    {
        return $this->hasMany(SerialLink::class, 'order_id');
    }

    /** MVP: batch split/merge (§3I's `mes_batch_relations`, table only, no UI yet) means a real tenant could have more than one — but this build's execution flow only ever creates/drives one batch per order. */
    public function batches()
    {
        return $this->hasMany(MesBatch::class, 'order_id');
    }
}
