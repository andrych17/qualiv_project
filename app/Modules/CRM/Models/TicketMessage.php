<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $table = 'CRM.hd_ticket_messages';

    public $timestamps = false;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INTERNAL_NOTE = 'internal_note';

    protected $fillable = ['ticket_id', 'direction', 'body', 'sender_id', 'sender_name', 'sent_at'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
