<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3I / §4 — POS Payment.
 */
class PosPayment extends Model
{
    protected $table = 'POS.pos_payments';

    public $timestamps = false;

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_QRIS = 'qris';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_E_WALLET = 'e_wallet';

    public const METHOD_VOUCHER = 'voucher';

    public const METHOD_GIFT_CARD = 'gift_card';

    public const METHOD_STORE_CREDIT = 'store_credit';

    public const METHOD_CUSTOMER_CREDIT = 'customer_credit';

    public const METHOD_ON_ACCOUNT = 'on_account';

    protected $fillable = [
        'txn_id',
        'method',
        'amount',
        'reference',
        'change_given',
        'gift_card_id',
        'store_credit_id',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'change_given' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTxnHdr::class, 'txn_id');
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(PosGiftCard::class, 'gift_card_id');
    }

    public function storeCredit(): BelongsTo
    {
        return $this->belongsTo(PosStoreCredit::class, 'store_credit_id');
    }
}
