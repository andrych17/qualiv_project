<?php

namespace App\Modules\Legal\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class DeedParty extends Model
{
    protected $table = 'LEGAL.deed_parties';

    protected $fillable = ['deed_id', 'partner_id', 'role_type_id', 'identity_snapshot'];

    protected $casts = [
        'identity_snapshot' => 'array',
    ];

    public function deed()
    {
        return $this->belongsTo(Deed::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function roleType()
    {
        return $this->belongsTo(PartyRoleType::class, 'role_type_id');
    }
}
