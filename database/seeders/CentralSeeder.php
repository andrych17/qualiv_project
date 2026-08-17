<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPayment;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Database\Seeder;

class CentralSeeder extends Seeder
{
    /** Dummy data for local dev only — never runs in production (guarded by DatabaseSeeder). */
    public function run(): void
    {
        CentralAdminUser::query()->updateOrCreate(
            ['email' => env('CENTRAL_ADMIN_EMAIL', 'simon@nusaevo.com')],
            [
                'name' => 'Simon',
                'password' => env('CENTRAL_ADMIN_PASSWORD', 'password'),
            ],
        );

        $plans = [
            ['code' => 'internal', 'name' => 'Internal', 'price_monthly' => 0, 'description' => "Nusaevo's own tenant — not sold."],
            ['code' => 'starter', 'name' => 'Starter', 'price_monthly' => 500000],
            ['code' => 'legal', 'name' => 'Legal', 'price_monthly' => 1500000],
            ['code' => 'full', 'name' => 'Full', 'price_monthly' => 3000000],
        ];

        foreach ($plans as $plan) {
            CentralPlan::query()->updateOrCreate(['code' => $plan['code']], [
                'name' => $plan['name'],
                'description' => $plan['description'] ?? null,
                'price_monthly' => $plan['price_monthly'],
                'currency' => 'IDR',
                'is_active' => true,
            ]);
        }

        // Dummy invoice history for the two demo tenants DatabaseSeeder already creates
        // (001 Nusaevo/internal, 002 Demo Legal/legal) — one paid, one still issued.
        $this->seedInvoiceHistory('001', 'internal', paid: true);
        $this->seedInvoiceHistory('002', 'legal', paid: false);
    }

    private function seedInvoiceHistory(string $tenantId, string $planCode, bool $paid): void
    {
        if (! Tenant::query()->find($tenantId)) {
            return;
        }

        $plan = CentralPlan::query()->where('code', $planCode)->first();

        if (! $plan) {
            return;
        }

        $invoice = CentralInvoice::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'plan_code' => $planCode, 'billing_period_start' => now()->startOfMonth()->toDateString()],
            [
                'billing_period_end' => now()->endOfMonth()->toDateString(),
                'status' => $paid ? 'paid' : 'issued',
                'amount_total' => $plan->price_monthly,
                'currency' => $plan->currency,
                'due_date' => now()->addDays(14)->toDateString(),
                'issued_at' => now(),
            ],
        );

        $invoice->lines()->updateOrCreate(['description' => "{$plan->name} plan fee"], ['amount' => $plan->price_monthly]);

        if ($paid && $invoice->payments()->count() === 0) {
            CentralPayment::query()->create([
                'invoice_id' => $invoice->id,
                'tenant_id' => $tenantId,
                'amount' => $plan->price_monthly,
                'method' => 'bank_transfer',
                'paid_at' => now(),
                'notes' => 'Seeded demo payment.',
            ]);
        }
    }
}
