<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SalesTeamMember extends Model
{
    protected $table = 'SALES.sales_team_members';

    public $timestamps = false;

    protected $fillable = [
        'sales_team_id',
        'user_id',
        'role',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function team()
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
