<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'PURCHASE.categories';

    protected $fillable = [
        'name',
        'kind',
        'capex_opex',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function catalogItems()
    {
        return $this->hasMany(PurCatalogItem::class, 'category_id');
    }

    public function requisitionLines()
    {
        return $this->hasMany(PurRequisitionLine::class, 'category_id');
    }

    public function orderLines()
    {
        return $this->hasMany(PurOrderLine::class, 'category_id');
    }

    public function budgets()
    {
        return $this->hasMany(PurBudget::class, 'category_id');
    }
}
