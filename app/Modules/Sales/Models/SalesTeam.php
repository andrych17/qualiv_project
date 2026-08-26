<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SalesTeam extends Model
{
    protected $table = 'SALES.sales_teams';

    protected $fillable = [
        'name',
        'territory_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function territory()
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function members()
    {
        return $this->hasMany(SalesTeamMember::class, 'sales_team_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'SALES.sales_team_members', 'sales_team_id', 'user_id')
            ->withPivot(['id', 'role', 'joined_at']);
    }
}
