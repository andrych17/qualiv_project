<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MsgDeadLetter extends Model
{
    protected $table = 'WNE.msg_dead_letters';

    public $timestamps = false;

    protected $fillable = [
        'delivery_id', 'notification_id', 'channel', 'recipient_user_id', 'subject', 'body', 'data',
        'failure_history', 'resent_at', 'resent_by', 'discarded_at', 'discarded_by', 'created_at',
    ];

    protected $casts = [
        'data' => 'array',
        'failure_history' => 'array',
        'resent_at' => 'datetime',
        'discarded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function delivery()
    {
        return $this->belongsTo(MsgNotificationDelivery::class, 'delivery_id');
    }

    public function notification()
    {
        return $this->belongsTo(MsgNotification::class, 'notification_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function resentBy()
    {
        return $this->belongsTo(User::class, 'resent_by');
    }

    public function discardedBy()
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }

    public function isActioned(): bool
    {
        return $this->resent_at !== null || $this->discarded_at !== null;
    }
}
