<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurInvoiceHdr extends Model
{
    protected $table = 'PURCHASE.pur_invoice_hdrs';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT_TO_ACCOUNTING = 'sent_to_accounting';

    public const STATUS_POSTED = 'posted';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    public const MATCH_PENDING = 'pending';

    public const MATCH_MATCHED = 'matched';

    public const MATCH_MISMATCH = 'mismatch';

    protected $fillable = [
        'uuid',
        'po_id',
        'supplier_id',
        'supplier_invoice_no',
        'supplier_invoice_date',
        'currency_code',
        'amount',
        'dms_document_id',
        'submission_channel',
        'match_status',
        'status',
        'ap_bill_id',
        'created_by',
    ];

    protected $casts = [
        'supplier_invoice_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(PurOrderHdr::class, 'po_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(PurInvoiceLine::class, 'invoice_id');
    }

    public function matches()
    {
        return $this->hasMany(PurInvoiceMatch::class, 'invoice_id');
    }

    public function apBill()
    {
        return $this->belongsTo(ApBill::class, 'ap_bill_id');
    }
}
