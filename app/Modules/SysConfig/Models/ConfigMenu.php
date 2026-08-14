<?php

namespace App\Modules\SysConfig\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfigMenu extends Model
{
    protected $table = 'SYSCONFIG.config_menus';

    protected $fillable = [
        'code',
        'app_code',
        'menu_header',
        'menu_caption',
        'menu_link',
        'icon',
        'parent_id',
        'seq',
        'status_code',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('seq');
    }

    public function rights(): HasMany
    {
        return $this->hasMany(ConfigRight::class, 'menu_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'ilike', '%'.$search.'%')
                    ->orWhere('menu_caption', 'ilike', '%'.$search.'%')
                    ->orWhere('menu_link', 'ilike', '%'.$search.'%');
            });
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status_code', $status);
        })->when($filters['header'] ?? null, function ($query, $header) {
            $query->where('menu_header', $header);
        });
    }
}
