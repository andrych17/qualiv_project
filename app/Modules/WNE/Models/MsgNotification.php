<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MsgNotification extends Model
{
    protected $table = 'WNE.msg_notifications';

    public $timestamps = false;

    public const RECIPIENT_USER = 'user';

    public const RECIPIENT_ROLE = 'role';

    public const RECIPIENT_MANAGER_OF_USER = 'manager_of_user';

    /** §3G: a webhook_call has no user/role recipient — the target is an external URL, read off the source step. */
    public const RECIPIENT_WEBHOOK = 'webhook';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'category_code', 'recipient_type', 'recipient_user_id', 'recipient_role',
        'subject_type', 'subject_id', 'subject', 'body', 'data', 'status', 'error', 'created_at',
    ];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
    ];

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function deliveries()
    {
        return $this->hasMany(MsgNotificationDelivery::class, 'notification_id');
    }

    /** §3I: "sent" the moment ANY channel succeeds — an email bounce must never hide a successful in-app delivery. */
    public function recomputeStatus(): void
    {
        $deliveries = $this->deliveries()->get();

        if ($deliveries->isEmpty()) {
            return;
        }

        $anySucceeded = $deliveries->whereIn('status', [MsgNotificationDelivery::STATUS_SENT, MsgNotificationDelivery::STATUS_DELIVERED])->isNotEmpty();
        $allTerminal = $deliveries->every(fn (MsgNotificationDelivery $d) => in_array($d->status, MsgNotificationDelivery::TERMINAL_STATUSES, true));

        if ($anySucceeded) {
            $this->update(['status' => self::STATUS_SENT]);
        } elseif ($allTerminal) {
            $this->update(['status' => self::STATUS_FAILED]);
        }
    }
}
