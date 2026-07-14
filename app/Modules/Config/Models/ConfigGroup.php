<?php

namespace App\Modules\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfigGroup extends Model
{
    protected $table = 'SYSCONFIG.config_groups';

    protected $fillable = [
        'code',
        'app_code',
        'descr',
        'status_code',
    ];

    public function groupUsers(): HasMany
    {
        return $this->hasMany(ConfigGroupUser::class, 'group_id');
    }

    public function rights(): HasMany
    {
        return $this->hasMany(ConfigRight::class, 'group_id');
    }
}
