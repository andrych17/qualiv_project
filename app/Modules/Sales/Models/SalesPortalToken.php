<?php

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SalesPortalToken extends Model
{
    protected $table = 'SALES.sales_portal_tokens';

    public $timestamps = false;

    protected $fillable = [
        'token',
        'partner_id',
        'expires_at',
        'revoked_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->token)) {
                $model->token = (string) Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && now()->gt($this->expires_at)) {
            return false;
        }

        return true;
    }
}
