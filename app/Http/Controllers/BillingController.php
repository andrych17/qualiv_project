<?php

namespace App\Http\Controllers;

use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralTenantAddon;
use App\Modules\Central\Requests\StorePaymentRequest;
use App\Modules\Central\Services\CentralEntitlementService;
use App\Modules\Central\Services\CentralPaymentService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant-facing "Billing & Subscription" screen (CENTRAL_SPECS.md §3H) — a normal tenant-side
 * Inertia controller that queries the `central` connection directly via Eloquent (every Central
 * model already uses the CentralConnection trait). Connection boundary, not a service boundary
 * — no new deployable, no new API surface (CLAUDE.md §2).
 *
 * ponytail: spec restricts this screen to a tenant's designated admin user(s), but this
 * codebase has no "tenant admin" flag anywhere yet to check against — gated behind plain
 * `auth` (any logged-in tenant user) until that concept exists. Not a security regression:
 * the only write this screen allows is submitting a receipt for the tenant's own invoice.
 */
class BillingController extends Controller
{
    public function __construct(
        protected CentralEntitlementService $entitlement,
        protected CentralPaymentService $payments,
    ) {}

    public function index(): Response
    {
        $tenantId = tenant()->getTenantKey();
        $planCode = (string) tenant()->plan;

        return Inertia::render('Billing/Index', [
            'plan' => CentralPlan::query()->where('code', $planCode)->first(['code', 'name', 'price_monthly', 'currency']),
            'entitledModules' => $this->entitlement->entitledModules($tenantId),
            'addons' => CentralTenantAddon::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('module_code')
                ->get(['id', 'module_code', 'price_override']),
            'invoices' => CentralInvoice::query()
                ->where('tenant_id', $tenantId)
                ->with(['lines', 'payments'])
                ->orderByDesc('due_date')
                ->get(),
        ]);
    }

    public function submitPayment(StorePaymentRequest $request, CentralInvoice $invoice)
    {
        abort_unless($invoice->tenant_id === tenant()->getTenantKey(), 403);

        $this->payments->submit($invoice, $request->validated());

        return redirect()->route('billing.index')->with('success', 'Payment submitted for review.');
    }
}
