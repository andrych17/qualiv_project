<?php

namespace App\Modules\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralInvoiceLine extends Model
{
    use CentralConnection;

    protected $fillable = [
        'invoice_id',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CentralInvoice::class, 'invoice_id');
    }
}
