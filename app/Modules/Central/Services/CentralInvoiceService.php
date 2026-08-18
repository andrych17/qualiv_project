<?php

namespace App\Modules\Central\Services;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralTenantAddon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CentralInvoiceService
{
    public function __construct(
        protected CentralAuditLogger $auditLogger,
    ) {}

    /** Manual "generate an invoice now" admin action — same core as the recurring job below. */
    public function generate(array $data): CentralInvoice
    {
        $plan = CentralPlan::query()->where('code', $data['plan_code'])->firstOrFail();
        $tenant = Tenant::query()->findOrFail($data['tenant_id']);

        $invoice = $this->create($tenant, $plan, $data['billing_period_start'], $data['billing_period_end'], $data['due_date']);

        // Manual generation is an explicit admin action for a period they picked — if one
        // already exists for that period, that's a mistake worth surfacing, not a silent skip
        // (the recurring job below treats the same situation as an expected no-op instead).
        if (! $invoice) {
            abort(422, 'An invoice for this tenant/plan/period already exists.');
        }

        return $invoice;
    }

    /**
     * Shared core for manual generation and the recurring §3E job. Idempotent: returns null
     * (never throws) if an invoice already exists for this (tenant, plan, billing_period_start)
     * — the DB unique constraint on central_invoices is the real guarantee, this check plus the
     * catch below just avoid a noisy exception on the expected "already billed" path.
     */
    public function create(Tenant $tenant, CentralPlan $plan, string $periodStart, string $periodEnd, string $dueDate): ?CentralInvoice
    {
        $alreadyBilled = CentralInvoice::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('plan_code', $plan->code)
            ->where('billing_period_start', $periodStart)
            ->exists();

        if ($alreadyBilled) {
            return null;
        }

        try {
            return DB::transaction(function () use ($tenant, $plan, $periodStart, $periodEnd, $dueDate) {
                $invoice = CentralInvoice::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'plan_code' => $plan->code,
                    'billing_period_start' => $periodStart,
                    'billing_period_end' => $periodEnd,
                    'status' => 'issued',
                    'amount_total' => $plan->price_monthly,
                    'currency' => $plan->currency,
                    'due_date' => $dueDate,
                    'issued_at' => now(),
                ]);

                $invoice->lines()->create([
                    'description' => "{$plan->name} plan fee",
                    'amount' => $plan->price_monthly,
                ]);

                $addonTotal = 0.0;

                foreach ($this->activeAddons($tenant) as $addon) {
                    // ponytail: no add-on price catalog exists yet (CENTRAL_SPECS.md §3C
                    // doesn't define one) — an addon without a negotiated price_override
                    // lists as a $0 line rather than inventing a number. Upgrade path: a
                    // per-module default price table once add-ons are actually sold à la
                    // carte at scale.
                    $amount = (float) ($addon->price_override ?? 0);
                    $addonTotal += $amount;

                    $invoice->lines()->create([
                        'description' => "Add-on: {$addon->module_code}",
                        'amount' => $amount,
                    ]);
                }

                $invoice->update(['amount_total' => $plan->price_monthly + $addonTotal]);

                $this->auditLogger->log(
                    action: 'invoice_issued',
                    entityType: 'invoice',
                    entityId: (string) $invoice->id,
                    after: $invoice->refresh()->toArray(),
                );

                return $invoice->refresh()->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent generation for the same period (e.g. manual + scheduled overlap) —
            // the other caller won the race, this one is a no-op, not an error.
            return null;
        }
    }

    /** An invoice is voided, never deleted (CENTRAL_SPECS.md §3E). */
    public function void(CentralInvoice $invoice): CentralInvoice
    {
        $before = $invoice->toArray();
        $invoice->update(['status' => 'void']);

        $this->auditLogger->log(
            action: 'invoice_voided',
            entityType: 'invoice',
            entityId: (string) $invoice->id,
            before: $before,
            after: $invoice->refresh()->toArray(),
        );

        return $invoice;
    }

    /** @return Collection<int, CentralTenantAddon> */
    private function activeAddons(Tenant $tenant): Collection
    {
        return CentralTenantAddon::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', 'active')
            ->orderBy('module_code')
            ->get();
    }
}
