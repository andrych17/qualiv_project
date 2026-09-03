<?php

namespace App\Modules\POS\Services;

use App\Modules\POS\Models\PosGiftCard;
use App\Modules\POS\Models\PosLoyaltyAccount;
use App\Modules\POS\Models\PosLoyaltyLedger;
use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Models\PosStoreCredit;
use App\Modules\POS\Models\PosTable;
use App\Modules\POS\Models\PosTxnHdr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS_SPECS.md §3I, §3R, §3T — POS Payment Engine, Multi-Tender, Split, Change, and Completion.
 */
class PosPaymentService
{
    public function __construct(
        protected PosPostingService $postingService,
    ) {}

    public function addPayment(
        int $txnId,
        string $method,
        float $amount,
        ?string $reference = null,
        ?int $giftCardId = null,
        ?int $storeCreditId = null
    ): PosPayment {
        return DB::transaction(function () use ($txnId, $method, $amount, $reference, $giftCardId, $storeCreditId) {
            $txn = PosTxnHdr::query()->findOrFail($txnId);

            if (! in_array($txn->status, [PosTxnHdr::STATUS_DRAFT, PosTxnHdr::STATUS_PARKED])) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot add payment to a non-draft transaction.'],
                ]);
            }

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount must be greater than zero.'],
                ]);
            }

            // Gift card handling (§3R)
            if ($method === PosPayment::METHOD_GIFT_CARD) {
                $giftCard = PosGiftCard::query()->findOrFail($giftCardId);
                if ($giftCard->status !== PosGiftCard::STATUS_ACTIVE) {
                    throw ValidationException::withMessages(['gift_card' => ['Gift card is not active.']]);
                }
                if ($giftCard->expiry_date && $giftCard->expiry_date->isPast()) {
                    throw ValidationException::withMessages(['gift_card' => ['Gift card has expired.']]);
                }
                if ((float) $giftCard->balance < $amount) {
                    throw ValidationException::withMessages(['gift_card' => ['Insufficient gift card balance.']]);
                }
                $giftCard->decrement('balance', $amount);
                if ((float) $giftCard->refresh()->balance <= 0) {
                    $giftCard->update(['status' => PosGiftCard::STATUS_REDEEMED]);
                }
            }

            // Store credit handling (§3R)
            if ($method === PosPayment::METHOD_STORE_CREDIT) {
                $storeCredit = PosStoreCredit::query()->findOrFail($storeCreditId);
                if ((float) $storeCredit->balance < $amount) {
                    throw ValidationException::withMessages(['store_credit' => ['Insufficient store credit balance.']]);
                }
                $storeCredit->decrement('balance', $amount);
            }

            // On account / Customer credit (§3J)
            if (in_array($method, [PosPayment::METHOD_ON_ACCOUNT, PosPayment::METHOD_CUSTOMER_CREDIT])) {
                if (! $txn->customer_id) {
                    throw ValidationException::withMessages(['customer' => ['Customer identification required for on-account tender.']]);
                }
                $txn->update(['is_on_account' => true]);
            }

            return PosPayment::query()->create([
                'txn_id' => $txn->id,
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
                'gift_card_id' => $giftCardId,
                'store_credit_id' => $storeCreditId,
                'occurred_at' => now(),
            ]);
        });
    }

    public function completeTransaction(int $txnId): PosTxnHdr
    {
        return DB::transaction(function () use ($txnId) {
            $txn = PosTxnHdr::query()->with(['terminal.profile', 'lines'])->findOrFail($txnId);

            if (! in_array($txn->status, [PosTxnHdr::STATUS_DRAFT, PosTxnHdr::STATUS_PARKED])) {
                throw ValidationException::withMessages([
                    'status' => ['Transaction is already completed or voided.'],
                ]);
            }

            $totalPaid = (float) PosPayment::query()->where('txn_id', $txn->id)->sum('amount');
            $grandTotal = (float) $txn->grand_total;

            if (! $txn->is_on_account && $totalPaid < $grandTotal) {
                throw ValidationException::withMessages([
                    'payment' => ["Underpaid: Total paid {$totalPaid} is less than grand total {$grandTotal}."],
                ]);
            }

            // Calculate change if overpaid with cash
            $overpaid = max(0, $totalPaid - $grandTotal);
            if ($overpaid > 0) {
                $cashPayment = PosPayment::query()
                    ->where('txn_id', $txn->id)
                    ->where('method', PosPayment::METHOD_CASH)
                    ->latest('id')
                    ->first();

                if ($cashPayment) {
                    $cashPayment->update(['change_given' => $overpaid]);
                }
            }

            $txn->update(['status' => PosTxnHdr::STATUS_COMPLETED]);

            // Accrue loyalty points (§3R)
            if ($txn->customer_id && ($txn->terminal->profile->loyalty_enabled ?? true)) {
                $loyaltyAccount = PosLoyaltyAccount::query()->where('customer_id', $txn->customer_id)->first();
                if ($loyaltyAccount) {
                    $multiplier = (float) ($loyaltyAccount->tier?->points_per_currency_unit ?? 1.0);
                    $pointsEarned = round(($grandTotal / 1000) * $multiplier, 2);

                    if ($pointsEarned > 0) {
                        $loyaltyAccount->increment('points_balance', $pointsEarned);
                        PosLoyaltyLedger::query()->create([
                            'account_id' => $loyaltyAccount->id,
                            'txn_id' => $txn->id,
                            'type' => PosLoyaltyLedger::TYPE_EARN,
                            'points_delta' => $pointsEarned,
                            'occurred_at' => now(),
                        ]);
                    }
                }
            }

            // Post stock out to inventory (§3K)
            $this->postingService->postToInventory($txn);

            // Post to accounting / AR boundary (§3J)
            $this->postingService->postToAccounting($txn);

            // Free table if dine-in (§3M)
            if ($txn->table_id) {
                PosTable::query()->where('id', $txn->table_id)->update(['status' => PosTable::STATUS_CLEANING]);
            }

            return $txn->refresh();
        });
    }
}
