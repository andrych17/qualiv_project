<?php

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Support\Facades\DB;

class CentralInvoiceService
{
    /**
     * Simple manual "generate an invoice now" action — no scheduled recurring job yet
     * (CENTRAL_SPECS.md §3E's dunning-driven `overdue` derivation is deferred too).
     * ponytail: one line for the plan fee only; add-on line items are additive later
     * once central_tenant_addons (§3C) actually exists.
     */
    public function generate(array $data): CentralInvoice
    {
        $plan = CentralPlan::query()->where('code', $data['plan_code'])->firstOrFail();

        return DB::transaction(function () use ($data, $plan) {
            $invoice = CentralInvoice::query()->create([
                'tenant_id' => $data['tenant_id'],
                'plan_code' => $plan->code,
                'billing_period_start' => $data['billing_period_start'],
                'billing_period_end' => $data['billing_period_end'],
                'status' => 'issued',
                'amount_total' => $plan->price_monthly,
                'currency' => $plan->currency,
                'due_date' => $data['due_date'],
                'issued_at' => now(),
            ]);

            $invoice->lines()->create([
                'description' => "{$plan->name} plan fee",
                'amount' => $plan->price_monthly,
            ]);

            return $invoice->refresh()->load('lines');
        });
    }

    /** An invoice is voided, never deleted (CENTRAL_SPECS.md §3E). */
    public function void(CentralInvoice $invoice): CentralInvoice
    {
        $invoice->update(['status' => 'void']);

        return $invoice->refresh();
    }
}
