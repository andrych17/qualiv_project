<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class MsgCategory extends Model
{
    protected $table = 'WNE.msg_categories';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_mandatory', 'is_urgent', 'digestible', 'default_channels'];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'is_urgent' => 'boolean',
        'digestible' => 'boolean',
        'default_channels' => 'array',
    ];

    public function templates()
    {
        return $this->hasMany(MsgTemplate::class, 'category_id');
    }

    public function preferences()
    {
        return $this->hasMany(MsgUserPreference::class, 'category_id');
    }
}
