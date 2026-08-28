<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/** §3E — an OKR planning window, e.g. "2026 Q3". Deliberately separate from PERF.periods (see migration docblock). */
class OkrCycle extends Model
{
    protected $table = 'PERF.okr_cycles';

    protected $fillable = ['label', 'start_date', 'end_date', 'is_active'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function objectives()
    {
        return $this->hasMany(OkrObjective::class, 'cycle_id');
    }
}
