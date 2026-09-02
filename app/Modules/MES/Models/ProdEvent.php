<?php

namespace App\Modules\MES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * MES_SPECS.md §3C — append-only production event ledger row. Immutable: corrections are new
 * events, never `UPDATE`/`DELETE` on a past row (§3C Rules/Logic) — this model exposes no
 * update path beyond `create()`.
 */
class ProdEvent extends Model
{
    protected $table = 'MES.mes_prod_events';

    public $timestamps = false;

    public const TYPE_ORDER_RELEASED = 'order_released';

    public const TYPE_MATERIAL_ISSUED = 'material_issued';

    public const TYPE_MATERIAL_RETURNED = 'material_returned';

    public const TYPE_OPERATION_STARTED = 'operation_started';

    public const TYPE_OPERATION_PAUSED = 'operation_paused';

    public const TYPE_OPERATION_COMPLETED = 'operation_completed';

    public const TYPE_MACHINE_STARTED = 'machine_started';

    public const TYPE_MACHINE_STOPPED = 'machine_stopped';

    public const TYPE_PARAMETER_RECORDED = 'parameter_recorded';

    public const TYPE_QC_SAMPLE_TAKEN = 'qc_sample_taken';

    public const TYPE_SCRAP_RECORDED = 'scrap_recorded';

    public const TYPE_OUTPUT_PRODUCED = 'output_produced';

    public const TYPE_DOWNTIME_STARTED = 'downtime_started';

    public const TYPE_DOWNTIME_ENDED = 'downtime_ended';

    public const TYPE_BATCH_SPLIT = 'batch_split';

    public const TYPE_BATCH_MERGED = 'batch_merged';

    /** @var list<string> every event_type the DB CHECK constraint allows (§3C). */
    public const TYPES = [
        self::TYPE_ORDER_RELEASED, self::TYPE_MATERIAL_ISSUED, self::TYPE_MATERIAL_RETURNED,
        self::TYPE_OPERATION_STARTED, self::TYPE_OPERATION_PAUSED, self::TYPE_OPERATION_COMPLETED,
        self::TYPE_MACHINE_STARTED, self::TYPE_MACHINE_STOPPED, self::TYPE_PARAMETER_RECORDED,
        self::TYPE_QC_SAMPLE_TAKEN, self::TYPE_SCRAP_RECORDED, self::TYPE_OUTPUT_PRODUCED,
        self::TYPE_DOWNTIME_STARTED, self::TYPE_DOWNTIME_ENDED, self::TYPE_BATCH_SPLIT, self::TYPE_BATCH_MERGED,
    ];

    protected $fillable = [
        'order_id', 'batch_id', 'operation_ref', 'event_type', 'payload', 'occurred_at', 'user_id', 'machine_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['order_id'] ?? null, function ($query, $orderId) {
            $query->where('order_id', $orderId);
        })->when($filters['event_type'] ?? null, function ($query, $eventType) {
            $query->where('event_type', $eventType);
        })->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('order', function ($query) use ($search) {
                $query->where('order_number', 'ilike', '%'.$search.'%');
            });
        });
    }

    public function order()
    {
        return $this->belongsTo(ProdOrder::class, 'order_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
