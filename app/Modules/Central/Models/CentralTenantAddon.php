<?php

namespace App\Modules\Central\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralTenantAddon extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'module_code',
        'added_at',
        'price_override',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
            'price_override' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
