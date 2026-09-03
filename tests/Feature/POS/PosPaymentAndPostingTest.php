<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\POS\Models\PosFloor;
use App\Modules\POS\Models\PosGiftCard;
use App\Modules\POS\Models\PosLoyaltyAccount;
use App\Modules\POS\Models\PosLoyaltyLedger;
use App\Modules\POS\Models\PosLoyaltyTier;
use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Models\PosProfile;
use App\Modules\POS\Models\PosStoreCredit;
use App\Modules\POS\Models\PosTable;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Services\PosCartService;
use App\Modules\POS\Services\PosPaymentService;
use App\Modules\POS\Services\PosSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * POS_SPECS.md §3I, §3J, §3K, §3R — Payments, Split Tenders, Change, and Inventory/AR Posting.
 */
class PosPaymentAndPostingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_multi_tender_split_payment_with_cash_change_and_loyalty_accrual(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Payment Warehouse', 'address' => 'Mall']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'T-PAY',
                'name' => 'Pay Terminal',
                'receipt_prefix' => 'TPAY',
            ]);

            $session = app(PosSessionService::class)->openSession($terminal->id, $user->id, 500000.0);
            $cartService = app(PosCartService::class);
            $paymentService = app(PosPaymentService::class);

            // Customer & Loyalty Account
            $customer = Partner::query()->create(['type' => 'individual', 'name' => 'Budi Santoso']);
            $tier = PosLoyaltyTier::query()->create(['name' => 'Silver', 'points_per_currency_unit' => 1.0, 'tier_threshold' => 0]);
            $loyaltyAccount = PosLoyaltyAccount::query()->create([
                'customer_id' => $customer->id,
                'tier_id' => $tier->id,
                'points_balance' => 10.0,
            ]);

            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $item = Product::query()->create([
                'sku' => 'ITEM-01',
                'name' => 'Product 1',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
            ]);

            // Create draft transaction
            $txn = $cartService->createDraftTransaction($session->id, [
                'customer_id' => $customer->id,
            ]);

            // Add line: 2 items @ 50,000 = 100,000 grand total
            $line = $cartService->addLine($txn, [
                'product_id' => $item->id,
                'unit_price' => 50000,
                'qty' => 2,
            ]);

            $txn->refresh();
            $this->assertEquals(100000.0, (float) $txn->grand_total);

            // Split payment 1: QRIS 40,000
            $pmt1 = $paymentService->addPayment($txn->id, PosPayment::METHOD_QRIS, 40000.0, 'QRIS-REF-1234');
            $this->assertEquals(40000.0, (float) $pmt1->amount);

            // Complete transaction prematurely should fail (underpaid: 40k < 100k)
            try {
                $paymentService->completeTransaction($txn->id);
                $this->fail('Expected ValidationException when completing underpaid transaction');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('payment', $e->errors());
            }

            // Split payment 2: Cash 70,000 (Remaining was 60k, so 10k change expected)
            $pmt2 = $paymentService->addPayment($txn->id, PosPayment::METHOD_CASH, 70000.0);

            // Complete transaction
            $completed = $paymentService->completeTransaction($txn->id);
            $this->assertEquals(PosTxnHdr::STATUS_COMPLETED, $completed->status);

            // Check cash change given
            $pmt2->refresh();
            $this->assertEquals(10000.0, (float) $pmt2->change_given);

            // Verify inventory posting flag was set (§3K)
            $line->refresh();
            $this->assertTrue((bool) $line->inventory_posted);

            // Verify loyalty points accrued: 100,000 / 1000 * 1.0 = 100 points
            $loyaltyAccount->refresh();
            $this->assertEquals(110.0, (float) $loyaltyAccount->points_balance);

            $ledger = PosLoyaltyLedger::query()->where('txn_id', $txn->id)->first();
            $this->assertNotNull($ledger);
            $this->assertEquals(100.0, (float) $ledger->points_delta);
            $this->assertEquals(PosLoyaltyLedger::TYPE_EARN, $ledger->type);
        });
    }

    public function test_gift_card_and_store_credit_tender_redemption(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Card Warehouse', 'address' => 'Mall']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'T-CARD',
                'name' => 'Card Terminal',
                'receipt_prefix' => 'TCARD',
            ]);

            $session = app(PosSessionService::class)->openSession($terminal->id, $user->id, 100000.0);
            $cartService = app(PosCartService::class);
            $paymentService = app(PosPaymentService::class);

            $customer = Partner::query()->create(['type' => 'individual', 'name' => 'Siti Rahayu']);

            // Gift card with 50,000 balance
            $giftCard = PosGiftCard::query()->create([
                'code' => 'GC-50K',
                'balance' => 50000.0,
                'currency' => 'IDR',
                'status' => PosGiftCard::STATUS_ACTIVE,
            ]);

            // Store credit with 30,000 balance
            $storeCredit = PosStoreCredit::query()->create([
                'customer_id' => $customer->id,
                'balance' => 30000.0,
            ]);

            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $item = Product::query()->create([
                'sku' => 'ITEM-GC',
                'name' => 'GC Product',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
            ]);

            $txn = $cartService->createDraftTransaction($session->id, ['customer_id' => $customer->id]);
            $cartService->addLine($txn, [
                'product_id' => $item->id,
                'unit_price' => 70000,
                'qty' => 1,
            ]);

            // Pay with gift card: 50,000
            $paymentService->addPayment($txn->id, PosPayment::METHOD_GIFT_CARD, 50000.0, null, $giftCard->id);
            $giftCard->refresh();
            $this->assertEquals(0.0, (float) $giftCard->balance);
            $this->assertEquals(PosGiftCard::STATUS_REDEEMED, $giftCard->status);

            // Pay remaining with store credit: 20,000
            $paymentService->addPayment($txn->id, PosPayment::METHOD_STORE_CREDIT, 20000.0, null, null, $storeCredit->id);
            $storeCredit->refresh();
            $this->assertEquals(10000.0, (float) $storeCredit->balance);

            $completed = $paymentService->completeTransaction($txn->id);
            $this->assertEquals(PosTxnHdr::STATUS_COMPLETED, $completed->status);
        });
    }
}
