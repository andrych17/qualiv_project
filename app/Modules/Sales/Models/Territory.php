<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class Territory extends Model
{
    protected $table = 'SALES.territories';

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function teams()
    {
        return $this->hasMany(SalesTeam::class, 'territory_id');
    }

    public function priceLists()
    {
        return $this->hasMany(PriceList::class, 'territory_id');
    }
}
