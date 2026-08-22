<?php

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Model;

class PartyRoleType extends Model
{
    protected $table = 'LEGAL.party_role_types';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
