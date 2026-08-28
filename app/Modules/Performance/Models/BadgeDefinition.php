<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/** §3I — tenant-editable Achievement badge/rule library. */
class BadgeDefinition extends Model
{
    protected $table = 'PERF.badge_definitions';

    public const TRIGGER_TARGET_HIT = 'target_hit';

    public const TRIGGER_OKR_COMPLETED = 'okr_completed';

    public const TRIGGER_STREAK_ON_TRACK = 'streak_on_track';

    public const TRIGGER_TYPES = [self::TRIGGER_TARGET_HIT, self::TRIGGER_OKR_COMPLETED, self::TRIGGER_STREAK_ON_TRACK];

    protected $fillable = ['name', 'trigger_type', 'trigger_params', 'icon', 'is_active'];

    protected $casts = [
        'trigger_params' => 'array',
        'is_active' => 'boolean',
    ];

    public function achievements()
    {
        return $this->hasMany(Achievement::class, 'badge_id');
    }
}
