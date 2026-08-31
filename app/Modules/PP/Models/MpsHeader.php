<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3C — one MPS row per (product, scenario); baseline row has scenario_id = null. */
class MpsHeader extends Model
{
    protected $table = 'PP.pp_mps_hdrs';

    protected $fillable = ['product_id', 'scenario_id'];

    public function scopeBaseline(Builder $query): void
    {
        $query->whereNull('scenario_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function lines()
    {
        return $this->hasMany(MpsLine::class, 'mps_hdr_id')->orderBy('period_start');
    }
}
