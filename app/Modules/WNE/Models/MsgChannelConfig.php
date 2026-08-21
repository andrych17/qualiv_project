<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class MsgChannelConfig extends Model
{
    protected $table = 'WNE.msg_channel_configs';

    public $timestamps = false;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_IN_APP = 'in_app';

    /** §3G: outbound webhook_call steps — same channel vocabulary WNE_SPECS.md §4's channel_types always listed. */
    public const CHANNEL_WEBHOOK = 'webhook';

    protected $fillable = ['channel', 'enabled', 'credentials', 'config', 'max_attempts', 'backoff_schedule'];

    protected $casts = [
        'enabled' => 'boolean',
        'credentials' => 'encrypted:array', // APP_KEY-encrypted — see migration note, not a per-tenant key
        'config' => 'array',
        'backoff_schedule' => 'array',
    ];

    public static function forChannel(string $channel): ?self
    {
        return static::query()->where('channel', $channel)->where('enabled', true)->first();
    }
}
