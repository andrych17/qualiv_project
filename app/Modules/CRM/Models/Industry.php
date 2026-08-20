<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class Industry extends Model
{
    protected $table = 'CRM.industries';

    public $timestamps = false;

    protected $fillable = ['name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
