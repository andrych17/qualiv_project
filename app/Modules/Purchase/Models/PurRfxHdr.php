<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurRfxHdr extends Model
{
    protected $table = 'PURCHASE.pur_rfx_hdrs';

    public const TYPE_RFQ = 'rfq';
    public const TYPE_RFI = 'rfi';
    public const TYPE_RFP = 'rfp';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_RESPONSES_OPEN = 'responses_open';
    public const STATUS_AWARDED = 'awarded';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'rfx_no',
        'type',
        'pr_id',
        'due_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function requisition()
    {
        return $this->belongsTo(PurRequisitionHdr::class, 'pr_id');
    }

    public function lines()
    {
        return $this->hasMany(PurRfxLine::class, 'rfx_id')->orderBy('line_no');
    }

    public function invitations()
    {
        return $this->hasMany(PurRfxInvitation::class, 'rfx_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
