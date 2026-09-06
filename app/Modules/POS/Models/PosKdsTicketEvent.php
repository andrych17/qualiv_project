<?php

namespace App\Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3O / §4 — Append-only KDS Status Event History.
 */
class PosKdsTicketEvent extends Model
{
    protected $table = 'POS.pos_kds_ticket_events';

    public $timestamps = false;

    public const STATUS_NEW = 'new';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_SERVED = 'served';

    public const STATUS_REFIRED = 'refired';

    protected $fillable = [
        'txn_line_id',
        'status',
        'occurred_at',
        'user_id',
        'note',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PosTxnLine::class, 'txn_line_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
