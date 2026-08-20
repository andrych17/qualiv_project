<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerRoleType extends Model
{
    protected $table = 'CRM.partner_role_types';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
