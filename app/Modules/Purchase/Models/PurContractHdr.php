<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\DMS\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurContractHdr extends Model
{
    protected $table = 'PURCHASE.pur_contract_hdrs';

    public const TYPE_FRAMEWORK = 'framework';

    public const TYPE_BLANKET = 'blanket';

    public const TYPE_PROJECT = 'project';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRING_SOON = 'expiring_soon';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_RENEWED = 'renewed';

    public const STATUS_TERMINATED = 'terminated';

    protected $fillable = [
        'uuid',
        'supplier_id',
        'title',
        'type',
        'value',
        'currency_code',
        'start_date',
        'end_date',
        'auto_renew',
        'notice_period_days',
        'dms_document_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_renew' => 'boolean',
        'notice_period_days' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function supplier()
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'dms_document_id');
    }
}
