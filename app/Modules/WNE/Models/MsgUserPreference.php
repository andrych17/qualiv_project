<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MsgUserPreference extends Model
{
    protected $table = 'WNE.msg_user_preferences';

    public $timestamps = false;

    protected $fillable = ['user_id', 'category_id', 'channels', 'opted_out'];

    protected $casts = [
        'channels' => 'array',
        'opted_out' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(MsgCategory::class, 'category_id');
    }
}
