<?php

namespace App\Modules\Central\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralInvoice extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'plan_code',
        'billing_period_start',
        'billing_period_end',
        'status',
        'amount_total',
        'currency',
        'due_date',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'amount_total' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CentralInvoiceLine::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CentralPayment::class, 'invoice_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['tenant_id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
    }
}
