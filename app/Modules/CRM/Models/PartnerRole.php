<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerRole extends Model
{
    protected $table = 'CRM.partner_roles';

    public $timestamps = false;

    protected $fillable = ['partner_id', 'role_type_id', 'assigned_at', 'assigned_by', 'is_active'];

    protected $casts = [
        'assigned_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function roleType()
    {
        return $this->belongsTo(PartnerRoleType::class, 'role_type_id');
    }
}
