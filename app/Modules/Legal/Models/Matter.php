<?php

namespace App\Modules\Legal\Models;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Matter extends Model
{
    protected $table = 'LEGAL.matters';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_ON_HOLD, self::STATUS_CLOSED];

    protected $fillable = [
        'uuid',
        'code',
        'title',
        'matter_type',
        'partner_id',
        'assigned_to',
        'status',
        'opened_at',
        'target_close_at',
        'converted_from_lead_id',
        'notes',
    ];

    protected $casts = [
        'opened_at' => 'date',
        'target_close_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Matter $matter) {
            if (empty($matter->uuid)) {
                $matter->uuid = (string) Str::uuid();
            }
            if (empty($matter->opened_at)) {
                $matter->opened_at = now()->toDateString();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'ilike', '%'.$search.'%')
                    ->orWhere('title', 'ilike', '%'.$search.'%');
            });
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedFromLead()
    {
        return $this->belongsTo(Lead::class, 'converted_from_lead_id');
    }

    public function deeds()
    {
        return $this->hasMany(Deed::class);
    }
}
