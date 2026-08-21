<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class MsgTemplate extends Model
{
    protected $table = 'WNE.msg_templates';

    public $timestamps = false;

    /** No per-user/tenant locale preference exists yet (§3J) — v1 always resolves against this one default. */
    public const DEFAULT_LOCALE = 'en';

    protected $fillable = ['category_id', 'channel', 'locale', 'subject', 'body', 'variables', 'is_active'];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(MsgCategory::class, 'category_id');
    }

    public static function resolveFor(string $categoryCode, string $channel, string $locale = self::DEFAULT_LOCALE): ?self
    {
        return static::query()
            ->whereHas('category', fn ($q) => $q->where('code', $categoryCode))
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();
    }
}
