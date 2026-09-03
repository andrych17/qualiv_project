<?php

namespace App\Modules\POS\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3R / §4 — Store Credits.
 */
class PosStoreCredit extends Model
{
    protected $table = 'POS.pos_store_credits';
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'balance',
        'source_type',
        'source_id',
        'created_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }
}
