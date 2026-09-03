<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3B / §4 — POS Branch.
 */
class PosBranch extends Model
{
    protected $table = 'POS.pos_branches';
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function terminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class, 'branch_id');
    }

    public function floors(): HasMany
    {
        return $this->hasMany(PosFloor::class, 'branch_id');
    }

    public function kdsStations(): HasMany
    {
        return $this->hasMany(PosKdsStation::class, 'branch_id');
    }
}
