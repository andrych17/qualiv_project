<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CommissionPlan extends Model
{
    protected $table = 'SALES.commission_plans';

    public const BASIS_FLAT_PCT = 'flat_pct';
    public const BASIS_TIERED = 'tiered';

    public const APPLIES_TO_TEAM = 'team';
    public const APPLIES_TO_REP = 'rep';

    protected $fillable = [
        'name',
        'basis',
        'flat_rate_pct',
        'tier_rules',
        'applies_to_type',
        'applies_to_sales_team_id',
        'applies_to_user_id',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'flat_rate_pct' => 'decimal:2',
        'tier_rules' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function salesTeam()
    {
        return $this->belongsTo(SalesTeam::class, 'applies_to_sales_team_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'applies_to_user_id');
    }
}
