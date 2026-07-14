<?php

namespace App\Modules\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigGroupUser extends Model
{
    protected $table = 'SYSCONFIG.config_grpusers';

    protected $fillable = [
        'group_id',
        'group_code',
        'user_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConfigGroup::class, 'group_id');
    }
}
