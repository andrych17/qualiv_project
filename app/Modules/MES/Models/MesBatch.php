<?php

namespace App\Modules\MES\Models;

use App\Modules\PP\Models\Recipe;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3I — Process Execution: one production run against a recipe. Named `MesBatch` (not `Batch`) to stay unambiguous next to Inventory's own `StockBatch` (lot) concept — a process batch and a stock lot are different things that happen to share a common English word. */
class MesBatch extends Model
{
    protected $table = 'MES.mes_batches';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['order_id', 'batch_number', 'recipe_id', 'status', 'planned_qty', 'actual_yield_pct'];

    protected $casts = [
        'planned_qty' => 'decimal:4',
        'actual_yield_pct' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(ProdOrder::class, 'order_id');
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function ingredients()
    {
        return $this->hasMany(BatchIngredient::class, 'batch_id');
    }

    public function phases()
    {
        return $this->hasMany(BatchPhase::class, 'batch_id');
    }
}
