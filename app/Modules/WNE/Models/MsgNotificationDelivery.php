<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class MsgNotificationDelivery extends Model
{
    protected $table = 'WNE.msg_notification_deliveries';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BOUNCED = 'bounced';

    /** §3M: a delivery being re-attempted after a failure — not terminal, still in flight. */
    public const STATUS_RETRYING = 'retrying';

    /** §3M: max attempts exhausted — mirrored into a WNE.msg_dead_letters row for admin resend/discard. */
    public const STATUS_DEAD_LETTERED = 'dead_lettered';

    public const TERMINAL_STATUSES = [
        self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_FAILED, self::STATUS_BOUNCED, self::STATUS_DEAD_LETTERED,
    ];

    /** §3O: once our own send pipeline settles here, no provider webhook may ever move the status again — these are decided with more context (retry policy exhausted) than a stray/duplicate/out-of-order callback has. */
    private const PIPELINE_OWNED_TERMINAL_STATUSES = [self::STATUS_FAILED, self::STATUS_DEAD_LETTERED];

    /**
     * §3O: providers redeliver and batch webhooks with no ordering guarantee — a delivery's
     * `status` column must only ever move forward, never be dragged back by a late/duplicate
     * callback (e.g. a delayed 'sent' arriving after 'delivered' already landed). Equal rank
     * blocks too, so whichever of delivered/bounced is reported first simply wins and the
     * other is a no-op on the status column (still recorded as its own msg_delivery_events
     * row regardless — this guard only gates the *delivery* row, never the append-only log).
     */
    private const STATUS_RANK = [
        self::STATUS_PENDING => 0,
        self::STATUS_RETRYING => 0,
        self::STATUS_SENT => 1,
        self::STATUS_DELIVERED => 2,
        self::STATUS_BOUNCED => 2,
        self::STATUS_FAILED => 3,
        self::STATUS_DEAD_LETTERED => 3,
    ];

    protected $fillable = [
        'notification_id', 'channel', 'status', 'provider_message_id', 'error', 'sent_at', 'delivered_at', 'attempt', 'retry_history',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'retry_history' => 'array',
    ];

    public function notification()
    {
        return $this->belongsTo(MsgNotification::class, 'notification_id');
    }

    /**
     * §3O: this delivery's full append-only timeline, oldest first. `id` is the tiebreak, not
     * just a secondary sort — several internal pipeline events can share the same wall-clock
     * moment, and a provider's own reported `occurred_at` isn't guaranteed to match processing
     * order either.
     */
    public function events()
    {
        return $this->hasMany(MsgDeliveryEvent::class, 'delivery_id')->orderBy('occurred_at')->orderBy('id');
    }

    /** §3O guard — see STATUS_RANK's docblock. */
    public function canAdvanceStatusTo(string $newStatus): bool
    {
        if (in_array($this->status, self::PIPELINE_OWNED_TERMINAL_STATUSES, true)) {
            return false;
        }

        return (self::STATUS_RANK[$newStatus] ?? 0) > (self::STATUS_RANK[$this->status] ?? 0);
    }
}
