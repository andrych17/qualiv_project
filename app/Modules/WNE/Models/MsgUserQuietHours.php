<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MsgUserQuietHours extends Model
{
    protected $table = 'WNE.msg_user_quiet_hours';

    public $timestamps = false;

    protected $fillable = ['user_id', 'channel', 'start_time', 'end_time'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
