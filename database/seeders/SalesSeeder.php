<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Sales\Models\CommissionPlan;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Models\Contract;
use App\Modules\Sales\Models\CustomerCreditProfile;
use App\Modules\Sales\Models\CustomerSalesProfile;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PromoCode;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesPortalToken;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesTeam;
use App\Modules\Sales\Models\Territory;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SalesSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $admin = $users->first() ?? User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@nusaevo.com',
            'password' => bcrypt('password'),
        ]);

        $rep = $users->skip(1)->first() ?? $admin;

        // 1. Territories
        $territoriesData = [
            ['code' => 'ID-JKT', 'name' => 'DKI Jakarta & Jabodetabek'],
            ['code' => 'ID-JBD', 'name' => 'Jawa Barat & Bandung'],
            ['code' => 'ID-JTM', 'name' => 'Jawa Timur & Surabaya'],
            ['code' => 'ID-BALI', 'name' => 'Bali & Nusa Tenggara'],
            ['code' => 'ID-SUM', 'name' => 'Sumatera Region'],
        ];

        $territories = [];
        foreach ($territoriesData as $tData) {
            $territories[$tData['code']] = Territory::updateOrCreate(
                ['code' => $tData['code']],
                ['name' => $tData['name'], 'is_active' => true]
            );
        }

        // 2. Sales Teams
        $teamEnterprise = SalesTeam::updateOrCreate(
            ['name' => 'Enterprise & Corporate Sales'],
            ['territory_id' => $territories['ID-JKT']->id, 'is_active' => true]
        );

        $teamSMB = SalesTeam::updateOrCreate(
            ['name' => 'Commercial & SMB Solutions'],
            ['territory_id' => $territories['ID-JTM']->id, 'is_active' => true]
        );

        if ($teamEnterprise->members()->count() === 0) {
            $teamEnterprise->members()->create(['user_id' => $admin->id, 'role' => 'lead']);
            if ($rep->id !== $admin->id) {
                $teamEnterprise->members()->create(['user_id' => $rep->id, 'role' => 'member']);
            }
        }

        // 3. Price Lists
        $priceListDefault = PriceList::updateOrCreate(
            ['name' => 'Standard Commercial Rates 2026'],
            [
                'currency' => 'IDR',
                'customer_segment' => 'standard',
                'is_tenant_default' => true,
                'is_active' => true,
            ]
        );

        if ($priceListDefault->lines()->count() === 0) {
            $priceListDefault->lines()->createMany([
                ['item_type' => 'service', 'description' => 'Legal Retainer & Advisory (Monthly)', 'unit_price' => 15000000],
                ['item_type' => 'service', 'description' => 'Corporate Deed Drafting & Review', 'unit_price' => 7500000],
                ['item_type' => 'service', 'description' => 'ERP Implementation & Training Consultation', 'unit_price' => 25000000],
                ['item_type' => 'product', 'description' => 'Enterprise Hardware Security Token', 'unit_price' => 850000],
                ['item_type' => 'product', 'description' => 'Biometric Attendance Device Unit', 'unit_price' => 3200000],
            ]);
        }

        $priceListEnterprise = PriceList::updateOrCreate(
            ['name' => 'Strategic Enterprise Tier (Volume Discount)'],
            [
                'currency' => 'IDR',
                'customer_segment' => 'enterprise',
                'is_tenant_default' => false,
                'is_active' => true,
            ]
        );

        if ($priceListEnterprise->lines()->count() === 0) {
            $priceListEnterprise->lines()->createMany([
                ['item_type' => 'service', 'description' => 'Legal Retainer & Advisory (Monthly)', 'unit_price' => 12500000],
                ['item_type' => 'service', 'description' => 'Corporate Deed Drafting & Review', 'unit_price' => 6000000],
                ['item_type' => 'service', 'description' => 'ERP Implementation & Training Consultation', 'unit_price' => 20000000],
            ]);
        }

        // 4. Promo Codes
        PromoCode::updateOrCreate(
            ['code' => 'NUSAEVO2026'],
            [
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'usage_limit' => 500,
                'times_used' => 12,
                'is_active' => true,
            ]
        );

        PromoCode::updateOrCreate(
            ['code' => 'DIRECT5M'],
            [
                'discount_type' => 'fixed',
                'discount_value' => 5000000,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'usage_limit' => 50,
                'times_used' => 3,
                'is_active' => true,
            ]
        );

        // 5. Commission Plans
        $planFlat = CommissionPlan::updateOrCreate(
            ['name' => 'Standard Sales Rep 5% Incentive'],
            [
                'sales_team_id' => $teamEnterprise->id,
                'calc_type' => 'flat',
                'flat_rate' => 5,
                'is_active' => true,
            ]
        );

        $planTiered = CommissionPlan::updateOrCreate(
            ['name' => 'Senior Executive Tiered Plan (3% Base / 7% Over 100M)'],
            [
                'calc_type' => 'tiered',
                'tier_threshold' => 100000000,
                'tier_base_rate' => 3,
                'tier_excess_rate' => 7,
                'is_active' => true,
            ]
        );

        // 6. Customers (CRM Partners)
        $roleCustomer = PartnerRoleType::firstOrCreate(['code' => 'CUSTOMER'], ['name' => 'Customer']);

        $customerA = Partner::firstOrCreate(
            ['name' => 'PT Astra Graha Pratama'],
            ['type' => 'company', 'is_active' => true, 'tax_number' => '01.234.567.8-012.000']
        );
        $customerA->roles()->firstOrCreate(['role_type_id' => $roleCustomer->id]);

        $customerB = Partner::firstOrCreate(
            ['name' => 'PT Mandiri Sejahtera Abadi'],
            ['type' => 'company', 'is_active' => true, 'tax_number' => '02.345.678.9-034.000']
        );
        $customerB->roles()->firstOrCreate(['role_type_id' => $roleCustomer->id]);

        $customerC = Partner::firstOrCreate(
            ['name' => 'CV Karya Gemilang Mandiri'],
            ['type' => 'company', 'is_active' => true]
        );
        $customerC->roles()->firstOrCreate(['role_type_id' => $roleCustomer->id]);

        // Customer Profiles & Credit Limits
        CustomerSalesProfile::updateOrCreate(
            ['partner_id' => $customerA->id],
            [
                'sales_team_id' => $teamEnterprise->id,
                'assigned_rep_id' => $rep->id,
                'price_list_id' => $priceListEnterprise->id,
            ]
        );

        CustomerCreditProfile::updateOrCreate(
            ['partner_id' => $customerA->id],
            [
                'credit_limit' => 500000000,
                'payment_terms_days' => 30,
                'on_hold' => false,
            ]
        );

        CustomerSalesProfile::updateOrCreate(
            ['partner_id' => $customerB->id],
            [
                'sales_team_id' => $teamSMB->id,
                'assigned_rep_id' => $admin->id,
                'price_list_id' => $priceListDefault->id,
            ]
        );

        CustomerCreditProfile::updateOrCreate(
            ['partner_id' => $customerB->id],
            [
                'credit_limit' => 150000000,
                'payment_terms_days' => 14,
                'on_hold' => false,
            ]
        );

        CustomerCreditProfile::updateOrCreate(
            ['partner_id' => $customerC->id],
            [
                'credit_limit' => 50000000,
                'payment_terms_days' => 7,
                'on_hold' => false,
            ]
        );

        // Sales Portal Tokens
        SalesPortalToken::updateOrCreate(
            ['customer_id' => $customerA->id],
            ['token' => 'cust-tok-astra-graha-pratama-'.Str::random(16), 'expires_at' => now()->addYear()]
        );

        SalesPortalToken::updateOrCreate(
            ['customer_id' => $customerB->id],
            ['token' => 'cust-tok-mandiri-sejahtera-'.Str::random(16), 'expires_at' => now()->addYear()]
        );

        // 7. Opportunities
        $opp1 = Opportunity::updateOrCreate(
            ['name' => 'Corporate Restructuring Retainer 2026'],
            [
                'customer_id' => $customerA->id,
                'stage' => 'negotiation',
                'owner_id' => $rep->id,
                'sales_team_id' => $teamEnterprise->id,
                'estimated_value' => 150000000,
                'expected_close_date' => now()->addMonth()->format('Y-m-d'),
                'created_by' => $admin->id,
            ]
        );

        $opp2 = Opportunity::updateOrCreate(
            ['name' => 'ERP & HR Payroll Rollout'],
            [
                'customer_id' => $customerB->id,
                'stage' => 'proposal',
                'owner_id' => $admin->id,
                'sales_team_id' => $teamSMB->id,
                'estimated_value' => 85000000,
                'expected_close_date' => now()->addWeeks(2)->format('Y-m-d'),
                'created_by' => $admin->id,
            ]
        );

        Opportunity::updateOrCreate(
            ['name' => 'Branch Notary Deed Expansion'],
            [
                'customer_id' => $customerC->id,
                'stage' => 'won',
                'owner_id' => $rep->id,
                'sales_team_id' => $teamSMB->id,
                'estimated_value' => 45000000,
                'expected_close_date' => now()->subDays(5)->format('Y-m-d'),
                'created_by' => $admin->id,
            ]
        );

        // 8. Quotations (Versioned)
        $quoteService = app(QuotationService::class);
        $soService = app(SalesOrderService::class);

        $quoteA = Quotation::where('customer_id', $customerA->id)->first();
        if (! $quoteA) {
            $quoteA = $quoteService->create([
                'customer_id' => $customerA->id,
                'opportunity_id' => $opp1->id,
                'price_list_id' => $priceListEnterprise->id,
                'validity_date' => now()->addDays(30)->format('Y-m-d'),
                'lines' => [
                    [
                        'item_type' => 'service',
                        'description' => 'ERP Implementation & Training Consultation',
                        'quantity' => 1,
                        'unit_price' => 20000000,
                        'discount_amount' => 1000000,
                        'tax_amount' => 2090000,
                    ],
                    [
                        'item_type' => 'service',
                        'description' => 'Legal Retainer & Advisory (Monthly)',
                        'quantity' => 6,
                        'unit_price' => 12500000,
                        'discount_amount' => 0,
                        'tax_amount' => 8250000,
                    ],
                ],
            ], $admin->id);

            $quoteService->send($quoteA);
        }

        // 9. Sales Orders & Fulfillment
        $orderA = SalesOrder::where('customer_id', $customerA->id)->first();
        if (! $orderA) {
            $orderA = $soService->create([
                'customer_id' => $customerA->id,
                'quot_hdr_id' => $quoteA->id,
                'price_list_id' => $priceListEnterprise->id,
                'lines' => [
                    [
                        'item_type' => 'service',
                        'description' => 'ERP Implementation & Training Consultation',
                        'qty_ordered' => 1,
                        'unit_price' => 20000000,
                        'discount_amount' => 1000000,
                        'tax_amount' => 2090000,
                    ],
                    [
                        'item_type' => 'product',
                        'description' => 'Biometric Attendance Device Unit',
                        'qty_ordered' => 5,
                        'unit_price' => 3200000,
                        'discount_amount' => 0,
                        'tax_amount' => 1760000,
                    ],
                ],
            ], $admin->id);

            $soService->confirm($orderA);

            // 10. Deliveries
            $delivery = Delivery::create([
                'so_hdr_id' => $orderA->id,
                'status' => Delivery::STATUS_SHIPPED,
                'carrier' => 'JNE Express Cargo',
                'tracking_number' => 'JNE-CGK-2026-98124',
                'shipped_at' => now()->subDay(),
            ]);

            $orderProductLine = $orderA->lines->where('item_type', 'product')->first();
            if ($orderProductLine) {
                $delivery->lines()->create([
                    'so_line_id' => $orderProductLine->id,
                    'qty_shipped' => 5,
                ]);

                $orderProductLine->update(['qty_delivered' => 5]);
            }
        }

        // 11. Contracts & Subscriptions
        $contractA = Contract::where('customer_id', $customerA->id)->first();
        if (! $contractA) {
            $contract = Contract::create([
                'customer_id' => $customerA->id,
                'name' => 'Corporate Legal Retainer Agreement 2026',
                'term_start' => '2026-01-01',
                'term_end' => '2026-12-31',
                'auto_renew' => true,
                'status' => Contract::STATUS_ACTIVE,
                'price_list_id' => $priceListEnterprise->id,
                'created_by' => $admin->id,
            ]);

            $sub = $contract->subscriptions()->create([
                'description' => 'Monthly Retainer Retainer Hours & Compliance',
                'billing_interval' => 'monthly',
                'recurring_amount' => 12500000,
                'next_billing_date' => now()->addMonth()->startOfMonth()->format('Y-m-d'),
            ]);

            // Seed recurring schedules
            for ($month = 1; $month <= 12; $month++) {
                $sub->recurringSchedules()->create([
                    'scheduled_date' => sprintf('2026-%02d-01', $month),
                    'status' => $month <= now()->month ? 'invoiced' : 'pending',
                ]);
            }
        }

        // 12. Returns (RMA)
        $returnA = SalesReturn::where('customer_id', $customerA->id)->first();
        if (! $returnA && $orderA) {
            $ret = SalesReturn::create([
                'customer_id' => $customerA->id,
                'so_hdr_id' => $orderA->id,
                'reason_code' => 'TRANSIT_DAMAGE',
                'status' => SalesReturn::STATUS_RECEIVED,
                'created_by' => $admin->id,
            ]);

            $orderProductLine = $orderA->lines->where('item_type', 'product')->first();
            if ($orderProductLine) {
                $ret->lines()->create([
                    'so_line_id' => $orderProductLine->id,
                    'qty_returned' => 1,
                    'condition_notes' => 'Damaged screen upon arrival, replacement pending',
                ]);
            }
        }

        // 13. Commission Settlement
        $settlementA = CommissionSettlement::where('rep_id', $rep->id)->first();
        if (! $settlementA) {
            $settlement = CommissionSettlement::create([
                'rep_id' => $rep->id,
                'period_start' => now()->startOfMonth()->format('Y-m-d'),
                'period_end' => now()->endOfMonth()->format('Y-m-d'),
                'total_commission' => 2500000,
                'status' => CommissionSettlement::STATUS_APPROVED,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            if ($orderA && $orderA->lines->isNotEmpty()) {
                $settlement->lines()->create([
                    'so_line_id' => $orderA->lines->first()->id,
                    'commission_plan_id' => $planFlat->id,
                    'commission_rate' => 5,
                    'commission_amount' => 1000000,
                ]);
            }
        }
    }
}
