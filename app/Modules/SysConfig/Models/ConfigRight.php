<?php

namespace App\Modules\SysConfig\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigRight extends Model
{
    protected $table = 'SYSCONFIG.config_rights';

    protected $fillable = [
        'group_id',
        'menu_id',
        'group_code',
        'menu_code',
        'menu_seq',
        'trustee',
        'app_code',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConfigGroup::class, 'group_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(ConfigMenu::class, 'menu_id');
    }

    /** @return array{create: bool, read: bool, update: bool, delete: bool} */
    public static function parseTrustee(?string $trustee): array
    {
        $trustee ??= '';

        return [
            'create' => str_contains($trustee, 'C'),
            'read' => str_contains($trustee, 'R'),
            'update' => str_contains($trustee, 'U'),
            'delete' => str_contains($trustee, 'D'),
        ];
    }

    /** @param array{create?: bool, read?: bool, update?: bool, delete?: bool} $permissions */
    public static function prepareTrusteeString(array $permissions): string
    {
        return ($permissions['create'] ?? false ? 'C' : '')
            .($permissions['read'] ?? false ? 'R' : '')
            .($permissions['update'] ?? false ? 'U' : '')
            .($permissions['delete'] ?? false ? 'D' : '');
    }
}
