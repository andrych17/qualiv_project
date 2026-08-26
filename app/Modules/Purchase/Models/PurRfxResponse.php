<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurRfxResponse extends Model
{
    protected $table = 'PURCHASE.pur_rfx_responses';

    protected $fillable = [
        'invitation_id',
        'notes',
    ];

    public function invitation()
    {
        return $this->belongsTo(PurRfxInvitation::class, 'invitation_id');
    }

    public function lines()
    {
        return $this->hasMany(PurRfxResponseLine::class, 'response_id');
    }
}
