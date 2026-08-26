<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Opportunity extends Model
{
    protected $table = 'SALES.opp_hdrs';

    public const STAGE_NEW = 'new';
    public const STAGE_QUALIFYING = 'qualifying';
    public const STAGE_QUOTED = 'quoted';
    public const STAGE_WON = 'won';
    public const STAGE_LOST = 'lost';

    public const STAGES = [
        self::STAGE_NEW,
        self::STAGE_QUALIFYING,
        self::STAGE_QUOTED,
        self::STAGE_WON,
        self::STAGE_LOST,
    ];

    protected $fillable = [
        'uuid',
        'name',
        'customer_id',
        'lead_id',
        'stage',
        'owner_id',
        'sales_team_id',
        'estimated_value',
        'expected_close_date',
        'loss_reason',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'expected_close_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function salesTeam()
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'opportunity_id');
    }
}
