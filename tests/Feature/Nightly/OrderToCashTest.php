<?php

namespace Tests\Feature\Nightly;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArCreditNote;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\ArPayment;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Services\AccountService;
use App\Modules\Accounting\Services\FiscalYearService;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Sales\Models\CustomerCreditProfile;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * End-to-end Order-to-Cash: Product + beginning balance -> Sales Order -> Delivery
 * (outbound) -> AR Invoice -> AR Payment. Exercises Inventory, Sales, and Accounting
 * together the way a nightly build should — none of the per-module feature tests
 * cross those three modules in one flow.
 */
#[Group('nightly')]
class OrderToCashTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_full_order_to_cash_lifecycle(): void
    {
        $tenant = $this->provisionTenant('o2c_01');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => 'o2c_01',
        ])->assertRedirect('/dashboard');

        $companyId = null;
        $customerId = null;
        $productId = null;
        $warehouseId = null;
        $locationId = null;
        $bankAccountId = null;
        $revenueAccountId = null;

        $tenant->run(function () use (&$companyId, &$customerId, &$productId, &$warehouseId, &$locationId, &$bankAccountId, &$revenueAccountId) {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();

            // --- Accounting: company + starter COA + open fiscal year ---
            Currency::query()->create(['code' => 'IDR', 'name' => 'Indonesian Rupiah']);

            $company = Company::query()->create([
                'legal_name' => 'PT Nusa Demo',
                'base_currency' => 'IDR',
            ]);
            $companyId = $company->id;

            app(AccountService::class)->seedStarterCoa($company);
            app(FiscalYearService::class)->create($company->id, now()->year, now()->startOfYear()->toDateString());

            $bankAccountId = Account::query()
                ->where('company_id', $company->id)
                ->where('account_code', '10200') // Bank, per starter COA
                ->value('id');
            $revenueAccountId = Account::query()
                ->where('company_id', $company->id)
                ->where('account_code', '41000') // Pendapatan Usaha, per starter COA
                ->value('id');

            // --- Customer ---
            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Pelanggan O2C', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);
            $customerId = $partner->id;

            CustomerCreditProfile::create([
                'partner_id' => $customerId,
                'credit_limit' => 500000000,
                'payment_terms_days' => 30,
                'on_hold' => false,
            ]);

            // --- Inventory master data ---
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);

            $warehouse = Warehouse::query()->create(['name' => 'Main Warehouse']);
            $warehouseId = $warehouse->id;
            $location = Location::query()->create([
                'warehouse_id' => $warehouse->id,
                'code' => 'MAIN-A1',
                'type' => Location::TYPE_BIN,
            ]);
            $locationId = $location->id;

            $reason = AdjustmentReason::query()->create(['code' => 'OPEN_BAL', 'name' => 'Beginning Balance']);

            $product = Product::query()->create([
                'sku' => 'O2C-WIDGET-01',
                'name' => 'O2C Test Widget',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $productId = $product->id;

            // --- Beginning balance: valued via a Goods Receipt (Adjustment posts at
            // current cost, which is 0 for a brand-new product with no valuation layer) ---
            $receipt = app(GoodsReceiptService::class)->create([
                'warehouse_id' => $warehouse->id,
                'receipt_date' => now()->toDateString(),
                'reference_number' => 'OPEN-BAL-O2C-WIDGET-01',
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'qty' => 100,
                        'uom_id' => $uom->id,
                        'unit_cost' => 50000,
                        'destination_location_id' => $location->id,
                    ],
                ],
            ]);
            app(GoodsReceiptService::class)->post($receipt);

            $onHand = StockBalance::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->sum('qty_on_hand');
            $this->assertEquals(100.0, (float) $onHand, 'Beginning balance did not post.');

            // Silence unused-variable lint for the reason id — kept for readability of intent.
            $this->assertNotNull($reason->id);
            $this->assertNotNull($admin->id);
        });

        // --- Sales Order: 10 units of the product ---
        $this->post('/sales/orders', [
            'customer_id' => $customerId,
            'lines' => [
                [
                    'item_type' => 'product',
                    'product_id' => $productId,
                    'description' => 'O2C Test Widget',
                    'qty_ordered' => 10,
                    'unit_price' => 150000,
                ],
            ],
        ])->assertRedirect();

        $order = null;
        $tenant->run(function () use (&$order, $customerId) {
            $order = SalesOrder::with('lines')->where('customer_id', $customerId)->latest('id')->first();
        });
        $this->assertNotNull($order);
        $this->assertEquals(SalesOrder::STATUS_DRAFT, $order->status);

        $this->post("/sales/orders/{$order->id}/confirm")->assertRedirect();
        $tenant->run(function () use ($order) {
            $this->assertEquals(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);
        });

        // --- Delivery Outbound: ship full quantity, deduct stock ---
        $this->post('/sales/deliveries', [
            'so_hdr_id' => $order->id,
            'source_location_id' => $locationId,
            'lines' => [
                ['so_line_id' => $order->lines[0]->id, 'qty_shipped' => 10],
            ],
        ])->assertRedirect();

        $delivery = null;
        $tenant->run(function () use (&$delivery, $order) {
            $delivery = Delivery::where('so_hdr_id', $order->id)->first();
        });
        $this->assertNotNull($delivery);

        $this->patch("/sales/deliveries/{$delivery->id}/status", [
            'status' => Delivery::STATUS_SHIPPED,
        ])->assertRedirect();

        $tenant->run(function () use ($delivery, $order, $productId, $warehouseId) {
            $this->assertEquals(Delivery::STATUS_SHIPPED, $delivery->fresh()->status);
            $this->assertEquals(SalesOrder::STATUS_FULFILLED, $order->fresh()->status);

            $onHand = StockBalance::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->sum('qty_on_hand');
            $this->assertEquals(90.0, (float) $onHand, 'Shipped quantity was not deducted from stock.');
        });

        // --- Billing: request AR invoice from the Sales Order, then post it ---
        $this->post("/sales/orders/{$order->id}/invoice")->assertRedirect();

        $invoice = null;
        $tenant->run(function () use (&$invoice, $order) {
            $invoice = ArInvoice::where('subject_type', 'sales.so_hdrs')->where('subject_id', $order->id)->first();
        });
        $this->assertNotNull($invoice);
        $this->assertEquals(ArInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertEquals(1500000.0, (float) $invoice->lines()->sum('line_amount') + (float) $invoice->lines()->sum('tax_amount'));

        $this->post("/accounting/ar-invoices/{$invoice->id}/post")->assertRedirect();
        $tenant->run(function () use ($invoice) {
            $posted = $invoice->fresh();
            $this->assertEquals(ArInvoice::STATUS_POSTED, $posted->status);

            // --- Accounting posting: invoice journal must balance ---
            $journal = $posted->journal()->with('lines')->first();
            $this->assertNotNull($journal, 'AR invoice was posted without a GL journal.');
            $this->assertEquals(GlJournal::STATUS_POSTED, $journal->status);
            $this->assertEquals((float) $journal->lines->sum('debit'), (float) $journal->lines->sum('credit'), 'Invoice journal does not balance.');
            $this->assertEquals(1500000.0, (float) $journal->lines->sum('debit'));
        });

        // --- Return: customer returns 3 of the 10 shipped units ---
        $this->post('/sales/returns', [
            'customer_id' => $customerId,
            'so_hdr_id' => $order->id,
            'accounting_invoice_id' => $invoice->id,
            'reason_code' => 'DAMAGED',
            'lines' => [
                ['so_line_id' => $order->lines[0]->id, 'qty_returned' => 3],
            ],
        ])->assertRedirect();

        $return = null;
        $tenant->run(function () use (&$return, $customerId) {
            $return = SalesReturn::where('customer_id', $customerId)->latest('id')->first();
        });
        $this->assertNotNull($return);
        $this->assertEquals(SalesReturn::STATUS_REQUESTED, $return->status);

        $this->post("/sales/returns/{$return->id}/approve")->assertRedirect();
        $tenant->run(function () use ($return) {
            $this->assertEquals(SalesReturn::STATUS_APPROVED, $return->fresh()->status);
        });

        $this->post("/sales/returns/{$return->id}/receive")->assertRedirect();
        $tenant->run(function () use ($return) {
            $this->assertEquals(SalesReturn::STATUS_RECEIVED, $return->fresh()->status);
        });

        // --- Delivery inbound: returned units go back into stock via a Goods Receipt ---
        $tenant->run(function () use ($return, $productId, $warehouseId, $locationId) {
            $receipt = app(GoodsReceiptService::class)->create([
                'warehouse_id' => $warehouseId,
                'receipt_date' => now()->toDateString(),
                'reference_number' => "RETURN-{$return->uuid}",
                'lines' => [
                    [
                        'product_id' => $productId,
                        'qty' => 3,
                        'uom_id' => Product::query()->findOrFail($productId)->base_uom_id,
                        'unit_cost' => 50000,
                        'destination_location_id' => $locationId,
                    ],
                ],
            ]);
            app(GoodsReceiptService::class)->post($receipt);

            $onHand = StockBalance::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->sum('qty_on_hand');
            $this->assertEquals(93.0, (float) $onHand, 'Returned quantity was not added back to stock.');
        });

        // --- Credit note: issue and post against the invoice for the returned value ---
        $this->post('/accounting/ar-credit-notes', [
            'ar_invoice_id' => $invoice->id,
            'credit_date' => now()->toDateString(),
            'amount' => 450000,
            'reason' => "Customer return {$return->uuid}",
            'revenue_account_id' => $revenueAccountId,
        ])->assertRedirect();

        $tenant->run(function () use ($invoice) {
            $creditNote = ArCreditNote::where('ar_invoice_id', $invoice->id)->latest('id')->first();
            $this->assertNotNull($creditNote);
            $this->assertEquals(ArCreditNote::STATUS_POSTED, $creditNote->status);
            $this->assertEquals(450000.0, (float) $creditNote->amount);

            $reconciled = $invoice->fresh();
            $this->assertEquals(450000.0, (float) $reconciled->credited_amount);
            $this->assertEquals(1050000.0, $reconciled->openBalance(), 'Credit note did not reconcile against the invoice open balance.');
            $this->assertEquals(ArInvoice::STATUS_PARTIALLY_PAID, $reconciled->status);

            // --- Accounting posting: credit note journal must balance ---
            $journal = $creditNote->journal()->with('lines')->first();
            $this->assertNotNull($journal, 'Credit note was posted without a GL journal.');
            $this->assertEquals(GlJournal::STATUS_POSTED, $journal->status);
            $this->assertEquals((float) $journal->lines->sum('debit'), (float) $journal->lines->sum('credit'), 'Credit note journal does not balance.');
            $this->assertEquals(450000.0, (float) $journal->lines->sum('debit'));
        });

        // --- Payment: settle the remaining balance after the return credit, auto-applied ---
        $remainingBalance = null;
        $tenant->run(function () use (&$remainingBalance, $invoice) {
            $remainingBalance = $invoice->fresh()->openBalance();
        });
        $this->assertEquals(1050000.0, $remainingBalance);

        $this->post('/accounting/ar-payments', [
            'company_id' => $companyId,
            'partner_id' => $customerId,
            'cash_gl_account_id' => $bankAccountId,
            'currency_code' => 'IDR',
            'payment_date' => now()->toDateString(),
            'amount' => $remainingBalance,
        ])->assertRedirect();

        $tenant->run(function () use ($invoice, $companyId, $customerId, $remainingBalance) {
            $this->assertEquals(ArInvoice::STATUS_PAID, $invoice->fresh()->status);

            $payment = ArPayment::where('company_id', $companyId)->where('partner_id', $customerId)->latest('id')->first();
            $this->assertNotNull($payment);
            $this->assertEquals(ArPayment::STATUS_POSTED, $payment->status);

            // --- Accounting posting: payment journal must balance ---
            $journal = $payment->journal()->with('lines')->first();
            $this->assertNotNull($journal, 'AR payment was posted without a GL journal.');
            $this->assertEquals(GlJournal::STATUS_POSTED, $journal->status);
            $this->assertEquals((float) $journal->lines->sum('debit'), (float) $journal->lines->sum('credit'), 'Payment journal does not balance.');
            $this->assertEquals($remainingBalance, (float) $journal->lines->sum('debit'));
        });
    }
}
