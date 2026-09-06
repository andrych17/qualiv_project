<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3M / §4 — Restaurant Floor.
 */
class PosFloor extends Model
{
    protected $table = 'POS.pos_floors';

    public $timestamps = false;

    protected $fillable = [
        'branch_id',
        'name',
        'layout_ref',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PosBranch::class, 'branch_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(PosTable::class, 'floor_id');
    }
}
