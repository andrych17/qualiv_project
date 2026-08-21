<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * §3O — append-only lifecycle log, no update/delete at the app layer (same convention as
 * WrkflowAuditLog). Every entry is written via log(), never touched again.
 */
class MsgDeliveryEvent extends Model
{
    protected $table = 'WNE.msg_delivery_events';

    public $timestamps = false;

    public const EVENT_CREATED = 'created';

    public const EVENT_QUEUED = 'queued';

    public const EVENT_SENDING = 'sending';

    public const EVENT_SENT = 'sent';

    public const EVENT_DELIVERED = 'delivered';

    public const EVENT_OPENED = 'opened';

    public const EVENT_BOUNCED = 'bounced';

    public const EVENT_FAILED = 'failed';

    public const EVENT_RETRYING = 'retrying';

    public const EVENT_DEAD_LETTERED = 'dead_lettered';

    protected $fillable = ['delivery_id', 'event_type', 'occurred_at', 'provider_payload'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'provider_payload' => 'array',
    ];

    public function delivery()
    {
        return $this->belongsTo(MsgNotificationDelivery::class, 'delivery_id');
    }

    public static function log(int $deliveryId, string $eventType, array $providerPayload = [], ?Carbon $occurredAt = null): self
    {
        return static::query()->create([
            'delivery_id' => $deliveryId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt ?? now(),
            'provider_payload' => $providerPayload,
        ]);
    }
}
