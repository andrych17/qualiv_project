<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurOrderHdr extends Model
{
    protected $table = 'PURCHASE.pur_order_hdrs';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACK_ACCEPTED = 'accepted';
    public const ACK_ACCEPTED_WITH_CHANGES = 'accepted_with_changes';
    public const ACK_REJECTED = 'rejected';

    protected $fillable = [
        'uuid',
        'po_no',
        'supplier_id',
        'pr_id',
        'rfx_id',
        'ship_to',
        'bill_to',
        'currency_code',
        'incoterms',
        'payment_terms_days',
        'status',
        'revision_no',
        'subtotal',
        'tax_amount',
        'total_amount',
        'expected_delivery_date',
        'ack_status',
        'pdf_document_id',
        'created_by',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
        'revision_no' => 'integer',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'expected_delivery_date' => 'date',
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

    public function requisition()
    {
        return $this->belongsTo(PurRequisitionHdr::class, 'pr_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(PurOrderLine::class, 'po_id')->orderBy('line_no');
    }

    public function revisions()
    {
        return $this->hasMany(PurOrderRevision::class, 'po_id')->orderByDesc('revision_no');
    }

    public function receipts()
    {
        return $this->hasMany(PurReceiptHdr::class, 'po_id')->orderByDesc('id');
    }

    public function invoices()
    {
        return $this->hasMany(PurInvoiceHdr::class, 'po_id')->orderByDesc('id');
    }
}
