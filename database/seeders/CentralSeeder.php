<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralDunningPolicy;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPayment;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralPlanModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;

class CentralSeeder extends Seeder
{
    /** Dummy data for local dev only — never runs in production (guarded by DatabaseSeeder). */
    public function run(): void
    {
        CentralAdminUser::query()->updateOrCreate(
            ['email' => env('CENTRAL_ADMIN_EMAIL', 'admin@nusaevo.com')],
            [
                'name' => 'Admin Central',
                'password' => env('CENTRAL_ADMIN_PASSWORD', 'password'),
            ],
        );
        CentralAdminUser::query()->whereNotIn('email', ['admin@nusaevo.com'])->delete();

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

        // Seed central_plan_modules from config/tenant_modules.php — that config was the
        // sole source of truth pre-CENTRAL §3C; this migrates its existing bundles into data.
        foreach (Config::get('tenant_modules.plans', []) as $planCode => $moduleCodes) {
            if (! CentralPlan::query()->where('code', $planCode)->exists()) {
                continue;
            }

            foreach ($moduleCodes as $moduleCode) {
                CentralPlanModule::query()->updateOrCreate([
                    'plan_code' => $planCode,
                    'module_code' => $moduleCode,
                ]);
            }
        }

        // Every dunning resolution falls back to this if no plan/tenant-scoped policy exists
        // (CENTRAL_SPECS.md §3G) — resolvePolicyFor() throws rather than guessing if this is
        // ever missing, so it must always exist.
        CentralDunningPolicy::query()->updateOrCreate(
            ['scope_type' => 'platform_default', 'scope_id' => null],
            [
                'reminder_offsets_days' => [-7, -3, -1, 3, 7],
                'cutoff_days_after_due' => 14,
                'cutoff_action' => 'read_only',
            ],
        );

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
                'status' => 'confirmed',
                'submitted_at' => now(),
                'reviewed_at' => now(),
            ]);
        }
    }
}
