<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Concerns\HasSlaState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServiceCase extends Model
{
    use HasSlaState;

    protected $table = 'CRM.svc_cases';

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

    /** §3E: closing is final for reporting, but reopenable within this many days. */
    public const REOPEN_GRACE_DAYS = 7;

    protected $fillable = [
        'partner_id', 'subject', 'category_id', 'priority', 'status',
        'assigned_to', 'sla_due_at', 'subject_type', 'subject_id', 'closed_at',
    ];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'closed_at' => 'datetime',
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
        });

        $query->filterSlaState($filters['sla_state'] ?? null);
    }

    public function canReopen(): bool
    {
        if ($this->status !== self::STATUS_CLOSED || $this->closed_at === null) {
            return false;
        }

        return now()->lessThanOrEqualTo($this->closed_at->copy()->addDays(self::REOPEN_GRACE_DAYS));
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

    public function activities()
    {
        return $this->hasMany(ServiceCaseActivity::class, 'case_id')->orderByDesc('logged_at');
    }
}
