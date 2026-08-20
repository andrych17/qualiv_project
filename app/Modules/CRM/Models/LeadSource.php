<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    protected $table = 'CRM.lead_sources';

    public $timestamps = false;

    protected $fillable = ['name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
