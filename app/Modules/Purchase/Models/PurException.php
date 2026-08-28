<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PurException extends Model
{
    protected $table = 'PURCHASE.pur_exceptions';

    public const TYPE_OVERDUE_APPROVAL = 'overdue_approval';

    public const TYPE_LATE_DELIVERY = 'late_delivery';

    public const TYPE_PRICE_VARIANCE = 'price_variance';

    public const TYPE_BUDGET_FLAG = 'budget_flag';

    public const TYPE_UNMATCHED_INVOICE = 'unmatched_invoice';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'exception_type',
        'subject_type',
        'subject_id',
        'summary',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
