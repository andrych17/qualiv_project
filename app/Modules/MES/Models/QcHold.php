<?php

namespace App\Modules\MES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * MES_SPECS.md §3L — a hold against an order, batch, or output lot/serial, auto-created on a
 * finished-goods QC fail. Record-only in this build: it doesn't block any Inventory operation
 * (no `quality_status`/held-vs-sellable concept exists in the Inventory module today) — see the
 * migration's own note for why that's this build's boundary, not an oversight.
 */
class QcHold extends Model
{
    protected $table = 'MES.mes_qc_holds';

    public $timestamps = false;

    public const STATUS_OPEN = 'open';

    public const STATUS_RELEASED = 'released';

    protected $fillable = ['subject_type', 'subject_id', 'reason', 'status', 'released_by', 'released_at', 'created_at'];

    protected $casts = [
        'released_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
