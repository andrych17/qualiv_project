<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $table = 'CRM.leads';

    public const STAGE_NEW = 'new';

    public const STAGE_CONTACTED = 'contacted';

    public const STAGE_QUALIFIED = 'qualified';

    public const STAGE_CONVERTED = 'converted';

    public const STAGE_DISQUALIFIED = 'disqualified';

    /** Stages a plain drag/PATCH may set directly — Converted/Disqualified need their own action (§3D, DESIGN.md). */
    public const DIRECT_STAGES = [self::STAGE_NEW, self::STAGE_CONTACTED, self::STAGE_QUALIFIED];

    protected $fillable = [
        'name', 'company_name', 'source_id', 'stage', 'owner_id',
        'estimated_value', 'next_action_at', 'notes',
        'converted_partner_id', 'disqualify_reason',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'next_action_at' => 'datetime',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('company_name', 'ilike', '%'.$search.'%');
            });
        })->when($filters['stage'] ?? null, function ($query, $stage) {
            $query->where('stage', $stage);
        });
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function convertedPartner()
    {
        return $this->belongsTo(Partner::class, 'converted_partner_id');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('logged_at');
    }
}
