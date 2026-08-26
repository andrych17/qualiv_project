<?php

namespace App\Modules\Purchase\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurRfxInvitation extends Model
{
    protected $table = 'PURCHASE.pur_rfx_invitations';
    public $timestamps = false;

    protected $fillable = [
        'rfx_id',
        'supplier_id',
        'response_token',
        'invited_at',
        'responded_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->response_token)) {
                $model->response_token = (string) Str::uuid();
            }
        });
    }

    public function rfx()
    {
        return $this->belongsTo(PurRfxHdr::class, 'rfx_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function response()
    {
        return $this->hasOne(PurRfxResponse::class, 'invitation_id');
    }
}
