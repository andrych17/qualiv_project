<?php

namespace App\Modules\Central\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralPayment extends Model
{
    use CentralConnection;

    protected $fillable = [
        'invoice_id',
        'tenant_id',
        'amount',
        'method',
        'paid_at',
        'notes',
        'receipt_object_key',
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CentralInvoice::class, 'invoice_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(CentralAdminUser::class, 'reviewed_by');
    }
}
