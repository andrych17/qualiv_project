<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Central\Models\CentralAuditLog;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPayment;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralTenantAddon;
use App\Modules\Central\Services\CentralDunningService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Simon's own operational snapshot (CENTRAL_SPECS.md §3A). "Approaching cutoff" is derived
 * per-tenant from their resolved dunning policy rather than a stored column, same as the
 * dunning commands themselves.
 */
class DashboardController extends Controller
{
    private const APPROACHING_CUTOFF_WINDOW_DAYS = 7;

    private const RECENT_TENANTS_LIMIT = 10;

    private const AUDIT_TAIL_LIMIT = 20;

    public function __construct(
        protected CentralDunningService $dunning,
    ) {}

    public function index(): Response
    {
        $tenants = Tenant::query()->get(['id', 'name', 'plan', 'access_status', 'created_at']);
        $activeTenants = $tenants->where('access_status', '!=', 'read_only');
        $readOnlyTenants = $tenants->where('access_status', 'read_only');

        $overdueInvoices = CentralInvoice::query()
            ->where('status', 'issued')
            ->whereDate('due_date', '<', today())
            ->with('tenant:id,name')
            ->orderBy('due_date')
            ->get();

        $pastDueTenantIds = $overdueInvoices->pluck('tenant_id')->unique();

        $pendingPayments = CentralPayment::query()
            ->where('status', 'pending_review')
            ->with(['tenant:id,name', 'invoice:id,tenant_id,amount_total,currency,due_date'])
            ->orderBy('submitted_at')
            ->get();

        $approachingCutoff = CentralInvoice::query()
            ->where('status', 'issued')
            ->with('tenant')
            ->get()
            ->filter(function (CentralInvoice $invoice) {
                if (! $invoice->tenant || $invoice->tenant->access_status === 'read_only') {
                    return false;
                }

                $policy = $this->dunning->resolvePolicyFor($invoice->tenant);
                $cutoffDate = $invoice->due_date->copy()->addDays($policy->cutoff_days_after_due);
                $window = today()->addDays(self::APPROACHING_CUTOFF_WINDOW_DAYS);

                return $cutoffDate->greaterThanOrEqualTo(today()) && $cutoffDate->lessThanOrEqualTo($window);
            })
            ->values();

        $mrr = $tenants->sum(function (Tenant $tenant) {
            $plan = CentralPlan::query()->where('code', $tenant->plan)->first();

            return (float) ($plan?->price_monthly ?? 0);
        }) + (float) CentralTenantAddon::query()
            ->where('status', 'active')
            ->whereNotNull('price_override')
            ->sum('price_override');

        return Inertia::render('Central/Dashboard', [
            'summary' => [
                'active_tenants' => $activeTenants->count(),
                'past_due_tenants' => $pastDueTenantIds->count(),
                'read_only_tenants' => $readOnlyTenants->count(),
                'payments_pending_review' => $pendingPayments->count(),
                'mrr' => $mrr,
                'invoices_issued_this_period' => CentralInvoice::query()
                    ->whereMonth('issued_at', now()->month)
                    ->whereYear('issued_at', now()->year)
                    ->count(),
            ],
            'pendingPayments' => $pendingPayments->values(),
            'overdueInvoices' => $overdueInvoices->values(),
            'approachingCutoff' => $approachingCutoff,
            'recentTenants' => $tenants->sortByDesc('created_at')->take(self::RECENT_TENANTS_LIMIT)->values(),
        ]);
    }

    /**
     * Tenant detail drawer (JSON, fetched client-side on row click) — the first thing in the
     * whole module that actually reads central_audit_logs.
     */
    public function tenantAuditLog(Tenant $tenant)
    {
        return response()->json([
            'tenant' => $tenant,
            'auditLog' => CentralAuditLog::query()
                ->where('entity_type', 'tenant')
                ->where('entity_id', $tenant->getKey())
                ->orderByDesc('created_at')
                ->limit(self::AUDIT_TAIL_LIMIT)
                ->get(),
        ]);
    }
}
