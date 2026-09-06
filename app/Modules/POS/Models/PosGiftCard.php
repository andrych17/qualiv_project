<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * POS_SPECS.md §3R / §4 — Gift Cards.
 */
class PosGiftCard extends Model
{
    protected $table = 'POS.pos_gift_cards';

    public $timestamps = false;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'code',
        'balance',
        'currency',
        'expiry_date',
        'status',
        'issued_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'expiry_date' => 'date',
        'issued_at' => 'datetime',
    ];
}
