<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class WrkflowCategory extends Model
{
    protected $table = 'WNE.wrkflow_categories';

    public $timestamps = false;

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function definitions()
    {
        return $this->hasMany(WrkflowDefinition::class, 'category_id');
    }
}
