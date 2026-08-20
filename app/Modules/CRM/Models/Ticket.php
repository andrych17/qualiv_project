<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Concerns\HasSlaState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasSlaState;

    protected $table = 'CRM.hd_tickets';

    // Same lifecycle vocabulary as ServiceCase (§3E) — CRM_SPECS.md §3F: "List/detail
    // views mirror After Sales Service." Unlike ServiceCase, closing here carries no
    // reopen-grace-window rule — §3F's Rules section doesn't state one.
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_WAITING_ON_PARTNER = 'waiting_on_partner';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_ON_PARTNER,
        self::STATUS_RESOLVED, self::STATUS_CLOSED,
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const CHANNELS = ['email', 'phone', 'web_form', 'in_app'];

    protected $fillable = [
        'partner_id', 'requester_name', 'requester_contact', 'subject', 'category_id',
        'priority', 'status', 'assigned_to', 'sla_due_at', 'channel',
    ];

    protected $casts = [
        'sla_due_at' => 'datetime',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('subject', 'ilike', '%'.$search.'%');
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['priority'] ?? null, function ($query, $priority) {
            $query->where('priority', $priority);
        })->when($filters['assigned_to'] ?? null, function ($query, $assignedTo) {
            $query->where('assigned_to', $assignedTo);
        })->when($filters['channel'] ?? null, function ($query, $channel) {
            $query->where('channel', $channel);
        });

        $query->filterSlaState($filters['sla_state'] ?? null);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function category()
    {
        return $this->belongsTo(TicketCategory::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(TicketMessage::class)->orderBy('sent_at');
    }

    public function requesterLabel(): string
    {
        return $this->partner?->name ?? $this->requester_name ?? 'Unknown';
    }
}
