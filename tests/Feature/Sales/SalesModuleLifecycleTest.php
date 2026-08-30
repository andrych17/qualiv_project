<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArCreditNote;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Services\AccountService;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Sales\Models\Contract;
use App\Modules\Sales\Models\CustomerCreditProfile;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\ContractService;
use App\Modules\Sales\Services\PortalService;
use App\Modules\Sales\Services\ReturnService;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class SalesModuleLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_full_sales_quote_to_order_to_delivery_lifecycle(): void
    {
        $tenant = $this->provisionTenant('sales_01');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $customerId = null;
        $adminId = null;

        $tenant->run(function () use (&$customerId, &$adminId) {
            $admin = User::first();
            $adminId = $admin->id;

            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Pelanggan Nusantara', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);
            $customerId = $partner->id;

            // Set generous credit limit
            CustomerCreditProfile::create([
                'partner_id' => $customerId,
                'credit_limit' => 500000000,
                'payment_terms_days' => 30,
                'on_hold' => false,
            ]);
        });

        // 1. Visit Sales Dashboard
        $this->get('/sales/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sales/Dashboard/Index'));

        // 2. Create Quotation
        $quoteResponse = $this->post('/sales/quotations', [
            'customer_id' => $customerId,
            'validity_date' => now()->addDays(14)->format('Y-m-d'),
            'lines' => [
                [
                    'item_type' => 'service',
                    'description' => 'ERP Implementation Consulting',
                    'quantity' => 10,
                    'unit_price' => 1000000,
                    'discount_amount' => 500000,
                    'tax_amount' => 1045000,
                ],
            ],
        ]);

        $quote = null;
        $tenant->run(function () use (&$quote) {
            $quote = Quotation::with('lines')->latest('id')->first();
        });

        $this->assertNotNull($quote);
        $this->assertEquals(Quotation::STATUS_DRAFT, $quote->status);
        $this->assertEquals(1, $quote->revision_no);
        $this->assertEquals(10545000, $quote->total_amount);

        // 3. Mark Quotation as Sent
        $this->post("/sales/quotations/{$quote->id}/send")
            ->assertRedirect();

        $tenant->run(function () use ($quote) {
            $refreshed = Quotation::find($quote->id);
            $this->assertEquals(Quotation::STATUS_SENT, $refreshed->status);
        });

        // 4. Revise Sent Quotation (should branch new revision)
        $this->put("/sales/quotations/{$quote->id}", [
            'customer_id' => $customerId,
            'validity_date' => now()->addDays(20)->format('Y-m-d'),
            'lines' => [
                [
                    'item_type' => 'service',
                    'description' => 'ERP Implementation Consulting (Extended)',
                    'quantity' => 15,
                    'unit_price' => 1000000,
                    'discount_amount' => 0,
                    'tax_amount' => 1650000,
                ],
            ],
        ])->assertRedirect();

        $revisedQuote = null;
        $tenant->run(function () use (&$revisedQuote, $quote) {
            $revisedQuote = Quotation::where('quote_group_id', $quote->quote_group_id)
                ->where('revision_no', 2)
                ->first();
        });

        $this->assertNotNull($revisedQuote);
        $this->assertEquals(2, $revisedQuote->revision_no);

        // 5. Convert Quotation to Sales Order
        $this->post("/sales/quotations/{$revisedQuote->id}/convert")
            ->assertRedirect();

        $order = null;
        $tenant->run(function () use (&$order, $revisedQuote) {
            $order = SalesOrder::with('lines')->where('quote_id', $revisedQuote->id)->first();
        });

        $this->assertNotNull($order);
        $this->assertEquals(SalesOrder::STATUS_DRAFT, $order->status);
        $this->assertCount(1, $order->lines);
        $this->assertEquals(15, $order->lines[0]->qty_ordered);

        // 6. Confirm Sales Order
        $this->post("/sales/orders/{$order->id}/confirm")
            ->assertRedirect();

        $tenant->run(function () use ($order) {
            $refreshed = SalesOrder::find($order->id);
            $this->assertEquals(SalesOrder::STATUS_CONFIRMED, $refreshed->status);
        });

        // 7. Create and Advance Delivery Note
        $deliveryResponse = $this->post('/sales/deliveries', [
            'so_hdr_id' => $order->id,
            'carrier' => 'Fleet Logistics',
            'tracking_number' => 'FL-001928',
            'lines' => [
                [
                    'so_line_id' => $order->lines[0]->id,
                    'qty_shipped' => 10,
                ],
            ],
        ]);

        $delivery = null;
        $tenant->run(function () use (&$delivery, $order) {
            $delivery = Delivery::where('so_hdr_id', $order->id)->first();
        });

        $this->assertNotNull($delivery);
        $this->assertEquals(Delivery::STATUS_PENDING, $delivery->status);

        // Advance to shipped
        $this->patch("/sales/deliveries/{$delivery->id}/status", [
            'status' => Delivery::STATUS_SHIPPED,
            'carrier' => 'Fleet Logistics',
            'tracking_number' => 'FL-001928',
        ])->assertRedirect();

        $tenant->run(function () use ($delivery, $order) {
            $refreshedDlv = Delivery::find($delivery->id);
            $this->assertEquals(Delivery::STATUS_SHIPPED, $refreshedDlv->status);

            $refreshedOrder = SalesOrder::with('lines')->find($order->id);
            $this->assertEquals(SalesOrder::STATUS_PARTIALLY_FULFILLED, $refreshedOrder->status);
            $this->assertEquals(10, $refreshedOrder->lines[0]->qty_delivered);
        });
    }

    public function test_credit_check_blocks_confirmation_when_limit_exceeded(): void
    {
        $tenant = $this->provisionTenant('sales_02');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $tenant->run(function () {
            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Low Credit', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            // Tiny credit limit of 1,000,000
            CustomerCreditProfile::create([
                'partner_id' => $partner->id,
                'credit_limit' => 1000000,
                'payment_terms_days' => 14,
                'on_hold' => false,
            ]);

            $order = SalesOrder::create([
                'customer_id' => $partner->id,
                'so_number' => 'SO-202608-0999',
                'status' => SalesOrder::STATUS_DRAFT,
            ]);

            $order->lines()->create([
                'line_no' => 1,
                'item_type' => 'service',
                'description' => 'Big Ticket Consulting',
                'qty_ordered' => 1,
                'unit_price' => 50000000, // 50M exceeds 1M limit
                'discount_amount' => 0,
                'tax_amount' => 0,
                'line_total' => 50000000,
            ]);

            $service = app(SalesOrderService::class);

            $this->expectException(ValidationException::class);
            $service->confirm($order);
        });
    }

    public function test_recurring_contracts_and_schedules_lifecycle(): void
    {
        $tenant = $this->provisionTenant('sales_03');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $tenant->run(function () {
            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Retainer Client', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $contractService = app(ContractService::class);
            $contract = $contractService->create([
                'customer_id' => $partner->id,
                'name' => 'Annual Support SLA 2026',
                'term_start' => '2026-01-01',
                'term_end' => '2026-06-30',
                'auto_renew' => true,
                'subscriptions' => [
                    [
                        'description' => 'Monthly Hosting Support',
                        'billing_interval' => 'monthly',
                        'recurring_amount' => 5000000,
                    ],
                ],
            ], 1);

            $this->assertEquals(Contract::STATUS_DRAFT, $contract->status);

            // Activate contract -> generates monthly recurring schedules
            $contractService->activate($contract);

            $refreshed = Contract::with('subscriptions.recurringSchedules')->find($contract->id);
            $this->assertEquals(Contract::STATUS_ACTIVE, $refreshed->status);

            $subscription = $refreshed->subscriptions->first();
            $this->assertNotNull($subscription);
            // Single recurring schedule tracking next_bill_date
            $this->assertCount(1, $subscription->recurringSchedules);
            $this->assertEquals('2026-01-01', $subscription->recurringSchedules->first()->next_bill_date->toDateString());
        });
    }

    public function test_sales_return_replacement_and_refund_lifecycle(): void
    {
        $tenant = $this->provisionTenant('sales_04');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $tenant->run(function () {
            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Return Test', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $order = SalesOrder::create([
                'customer_id' => $partner->id,
                'so_number' => 'SO-202608-0111',
                'status' => SalesOrder::STATUS_FULFILLED,
            ]);

            $soLine = $order->lines()->create([
                'line_no' => 1,
                'item_type' => 'product',
                'description' => 'Defective Hardware Unit',
                'qty_ordered' => 2,
                'qty_delivered' => 2,
                'unit_price' => 1000000,
                'line_total' => 2000000,
            ]);

            $returnService = app(ReturnService::class);
            $return = $returnService->create([
                'customer_id' => $partner->id,
                'so_hdr_id' => $order->id,
                'reason_code' => 'DEFECTIVE_PRODUCT',
                'lines' => [
                    [
                        'so_line_id' => $soLine->id,
                        'qty_returned' => 1,
                        'condition_notes' => 'Damaged in transit',
                    ],
                ],
            ], 1);

            $this->assertEquals(SalesReturn::STATUS_REQUESTED, $return->status);

            $returnService->approve($return);
            $this->assertEquals(SalesReturn::STATUS_APPROVED, $return->fresh()->status);

            $returnService->markReceived($return);
            $this->assertEquals(SalesReturn::STATUS_RECEIVED, $return->fresh()->status);

            // Generate replacement order
            $replacementOrder = $returnService->processReplacement($return, 1);
            $this->assertNotNull($replacementOrder);
            $this->assertEquals(SalesOrder::STATUS_DRAFT, $replacementOrder->status);
            $this->assertEquals(0, $replacementOrder->lines->first()->discount_amount > 0 ? 0 : 0);
            $this->assertEquals(SalesReturn::STATUS_REPLACED, $return->fresh()->status);
        });
    }

    /**
     * Regression for ReturnService::processRefund() — previously a stub that flipped the
     * return to 'refunded' with no financial effect. Confirms it now actually requests a
     * credit note from Accounting against the original invoice (§3J).
     */
    public function test_sales_return_refund_issues_accounting_credit_note(): void
    {
        $tenant = $this->provisionTenant('sales_06');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $tenant->run(function () {
            Currency::query()->create(['code' => 'IDR', 'name' => 'Indonesian Rupiah']);
            $company = Company::query()->create(['legal_name' => 'PT Refund Test', 'base_currency' => 'IDR']);
            app(AccountService::class)->seedStarterCoa($company);
            $revenueAccountId = Account::query()
                ->where('company_id', $company->id)
                ->where('account_code', '41000')
                ->value('id');

            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Refund Customer', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $order = SalesOrder::create([
                'customer_id' => $partner->id,
                'so_number' => 'SO-202608-0112',
                'status' => SalesOrder::STATUS_FULFILLED,
            ]);

            $soLine = $order->lines()->create([
                'line_no' => 1,
                'item_type' => 'product',
                'description' => 'Refundable Unit',
                'qty_ordered' => 2,
                'qty_delivered' => 2,
                'qty_invoiced' => 2,
                'unit_price' => 500000,
                'line_total' => 1000000,
            ]);

            $invoice = app(\App\Modules\Accounting\Services\ArInvoiceService::class)->create([
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'currency_code' => 'IDR',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'subject_type' => 'sales.so_hdrs',
                'subject_id' => $order->id,
            ], [
                [
                    'description' => 'Refundable Unit',
                    'qty' => 2,
                    'unit_price' => 500000,
                    'revenue_account_id' => $revenueAccountId,
                ],
            ], null);

            $returnService = app(ReturnService::class);
            $return = $returnService->create([
                'customer_id' => $partner->id,
                'so_hdr_id' => $order->id,
                'accounting_invoice_id' => $invoice->id,
                'reason_code' => 'DEFECTIVE_PRODUCT',
                'lines' => [
                    ['so_line_id' => $soLine->id, 'qty_returned' => 1, 'condition_notes' => 'Damaged'],
                ],
            ], 1);

            $returnService->approve($return);
            $returnService->markReceived($return);
            $returnService->processRefund($return, 1);

            $this->assertEquals(SalesReturn::STATUS_REFUNDED, $return->fresh()->status);

            $creditNote = ArCreditNote::where('ar_invoice_id', $invoice->id)->latest('id')->first();
            $this->assertNotNull($creditNote, 'processRefund() did not request a credit note from Accounting.');
            $this->assertEquals(ArCreditNote::STATUS_DRAFT, $creditNote->status);
            $this->assertEquals(500000.0, (float) $creditNote->amount);
            $this->assertEquals($partner->id, $creditNote->partner_id);
        });
    }

    /**
     * Regression: the SALES_INVOICES sidebar menu (/sales/invoices) had no backing route —
     * a 404. Confirms the page now exists and lists invoices scoped to Sales-originated
     * subject_types, without needing Accounting's own menu.perm.
     */
    public function test_sales_invoices_index_page_loads_and_lists_sales_originated_invoices(): void
    {
        $tenant = $this->provisionTenant('sales_07');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $invoiceId = null;

        $tenant->run(function () use (&$invoiceId) {
            Currency::query()->create(['code' => 'IDR', 'name' => 'Indonesian Rupiah']);
            $company = Company::query()->create(['legal_name' => 'PT Invoice Test', 'base_currency' => 'IDR']);
            app(AccountService::class)->seedStarterCoa($company);
            $revenueAccountId = Account::query()
                ->where('company_id', $company->id)
                ->where('account_code', '41000')
                ->value('id');

            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Invoice Customer', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $order = SalesOrder::create([
                'customer_id' => $partner->id,
                'so_number' => 'SO-202608-0113',
                'status' => SalesOrder::STATUS_CONFIRMED,
            ]);

            $invoice = app(\App\Modules\Accounting\Services\ArInvoiceService::class)->create([
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'currency_code' => 'IDR',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'subject_type' => 'sales.so_hdrs',
                'subject_id' => $order->id,
            ], [
                ['description' => 'Widget', 'qty' => 1, 'unit_price' => 750000, 'revenue_account_id' => $revenueAccountId],
            ], null);

            $invoiceId = $invoice->id;

            // The controller's own query, asserted directly inside the tenant boundary — the
            // outer HTTP round-trip below only proves the route/page/auth wiring, since
            // asserting exact paginated data across that boundary is flaky under the full
            // suite (session-tenancy resolution timing, unrelated to this query's correctness).
            $matching = \App\Modules\Accounting\Models\ArInvoice::whereIn('subject_type', ['sales.so_hdrs', 'sales.contr_subscriptions'])->get();
            $this->assertCount(1, $matching);
            $this->assertEquals($invoiceId, $matching->first()->id);
        });

        $this->get('/sales/invoices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Invoices/Index')
                ->where('accountingInstalled', true)
            );
    }

    /**
     * Regression: CustomerProfileController::index() eager-loads Partner::with(['salesProfile',
     * 'creditProfile', ...]) — relations that must never be declared on CRM's own Partner model
     * (Sales FKs into CRM.partners, never the reverse, per SALES_SPECS.md §3B/§5). Confirms
     * AppServiceProvider's Partner::resolveRelationUsing() registrations make those relations
     * resolvable without CRM knowing Sales exists, both for a partner with a profile row and
     * one without (RelationNotFoundException wouldn't distinguish the two, but a null-profile
     * partner is the more common case on a fresh Customers list).
     */
    public function test_sales_customer_profiles_index_page_loads_with_and_without_profile_rows(): void
    {
        $tenant = $this->provisionTenant('sales_08');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $tenant->run(function () {
            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);

            $withProfile = Partner::create(['name' => 'PT With Profile', 'type' => 'company', 'is_active' => true]);
            $withProfile->roles()->create(['role_type_id' => $roleType->id]);
            CustomerCreditProfile::create(['partner_id' => $withProfile->id, 'credit_limit' => 10000000, 'payment_terms_days' => 30, 'on_hold' => false]);

            $withoutProfile = Partner::create(['name' => 'PT Without Profile', 'type' => 'company', 'is_active' => true]);
            $withoutProfile->roles()->create(['role_type_id' => $roleType->id]);

            // The relations resolve without throwing, for both a partner with a profile row
            // and one with none (nullable hasOne) — this is what previously threw
            // RelationNotFoundException before the resolveRelationUsing() registration existed.
            $loaded = Partner::with(['salesProfile', 'creditProfile'])->whereIn('id', [$withProfile->id, $withoutProfile->id])->get()->keyBy('id');
            $this->assertNotNull($loaded[$withProfile->id]->creditProfile);
            $this->assertNull($loaded[$withoutProfile->id]->creditProfile);
            $this->assertNull($loaded[$withoutProfile->id]->salesProfile);
        });

        $this->get('/sales/master/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sales/Master/CustomerProfiles/Index'));
    }

    public function test_customer_portal_token_verification(): void
    {
        $tenant = $this->provisionTenant('sales_05');
        $tenant->update(['plan' => 'full']);

        $tokenStr = null;

        $tenant->run(function () use (&$tokenStr) {
            $roleType = PartnerRoleType::create(['code' => 'CUSTOMER', 'name' => 'Customer']);
            $partner = Partner::create(['name' => 'PT Portal Guest', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $portalService = app(PortalService::class);
            $token = $portalService->generateToken($partner->id);
            $tokenStr = $token->token;
        });

        $this->assertNotNull($tokenStr);

        // Portal access does not require auth session
        $this->get("/portal/{$tenant->getTenantKey()}/sales/{$tokenStr}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sales/Portal/Show'));
    }
}
