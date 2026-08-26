<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $table = 'SALES.price_lists';

    protected $fillable = [
        'name',
        'currency',
        'territory_id',
        'customer_segment',
        'effective_from',
        'effective_to',
        'is_tenant_default',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_tenant_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function territory()
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function lines()
    {
        return $this->hasMany(PriceListLine::class, 'price_list_id');
    }
}
