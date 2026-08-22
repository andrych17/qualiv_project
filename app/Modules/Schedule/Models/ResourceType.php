<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceType extends Model
{
    protected $table = 'SCHEDULE.resource_types';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function resources()
    {
        return $this->hasMany(Resource::class, 'resource_type_id');
    }
}
