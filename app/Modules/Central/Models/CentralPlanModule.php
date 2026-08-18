<?php

namespace App\Modules\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralPlanModule extends Model
{
    use CentralConnection;

    protected $fillable = [
        'plan_code',
        'module_code',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CentralPlan::class, 'plan_code', 'code');
    }
}
