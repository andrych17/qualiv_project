<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3B — manually entered or imported forecast row; syncs a 1:1 demand header/line. */
class DemandForecast extends Model
{
    protected $table = 'PP.pp_demand_forecasts';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = ['product_id', 'period_start', 'qty', 'source', 'note', 'created_by'];

    protected $casts = [
        'period_start' => 'date',
        'qty' => 'decimal:4',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('product', function ($query) use ($search) {
                $query->where('sku', 'ilike', '%'.$search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%');
            });
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
