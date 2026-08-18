<?php

namespace App\Modules\Central\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Append-only + functional: a row existing for (tenant_id, invoice_id, offset_days) is what
 * stops the reminder job from double-sending the same notice (CENTRAL_SPECS.md §3G/§5).
 */
class CentralDunningLog extends Model
{
    use CentralConnection;

    protected $table = 'central_dunning_log';

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'offset_days',
        'channel',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CentralInvoice::class, 'invoice_id');
    }
}
