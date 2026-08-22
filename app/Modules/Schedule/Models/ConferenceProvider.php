<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

class ConferenceProvider extends Model
{
    protected $table = 'SCHEDULE.conference_providers';

    public $timestamps = false;

    public const CODE_MANUAL = 'manual';

    public const CODE_ZOOM = 'zoom';

    protected $fillable = ['code', 'name', 'is_active', 'credentials', 'config'];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:array', // APP_KEY-encrypted, same convention as WNE.msg_channel_configs
        'config' => 'array',
    ];
}
