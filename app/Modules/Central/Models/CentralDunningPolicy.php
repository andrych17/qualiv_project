<?php

namespace App\Modules\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralDunningPolicy extends Model
{
    use CentralConnection;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'reminder_offsets_days',
        'cutoff_days_after_due',
        'cutoff_action',
    ];

    protected function casts(): array
    {
        return [
            'reminder_offsets_days' => 'array',
            'cutoff_days_after_due' => 'integer',
        ];
    }
}
