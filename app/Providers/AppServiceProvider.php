<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Models\User;
use App\Modules\Accounting\Contracts\CoretaxExportDriverInterface;
use App\Modules\Accounting\Events\ApBillRequested;
use App\Modules\Accounting\Events\ApPaymentRequested;
use App\Modules\Accounting\Events\InventoryGoodsIssued;
use App\Modules\Accounting\Events\InventoryGoodsReceived;
use App\Modules\Accounting\Events\InventoryStockAdjusted;
use App\Modules\Accounting\Events\InvoicePosted;
use App\Modules\Accounting\Events\InvoiceRequested;
use App\Modules\Accounting\Events\JournalPostingRequested;
use App\Modules\Accounting\Events\PaymentRecorded;
use App\Modules\Accounting\Events\PaymentRequested;
use App\Modules\Accounting\Events\PayrollRunPaid;
use App\Modules\Accounting\Listeners\CreateBillFromRequest;
use App\Modules\Accounting\Listeners\CreateInvoiceFromRequest;
use App\Modules\Accounting\Listeners\PostGoodsIssuedToGl;
use App\Modules\Accounting\Listeners\PostGoodsReceivedToGl;
use App\Modules\Accounting\Listeners\PostPayrollRunToGl;
use App\Modules\Accounting\Listeners\PostRequestedJournal;
use App\Modules\Accounting\Listeners\PostStockAdjustmentToGl;
use App\Modules\Accounting\Listeners\RecordApPaymentFromRequest;
use App\Modules\Accounting\Listeners\RecordPaymentFromRequest;
use App\Modules\Accounting\Services\XmlCoretaxExportDriver;
use App\Modules\CRM\Models\Partner;
use App\Modules\DMS\Models\Document;
use App\Modules\HCM\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Legal\Contracts\MatterCodeGenerator;
use App\Modules\Legal\Models\Matter;
use App\Modules\Legal\Services\PrefixedMatterCodeGenerator;
use App\Modules\Performance\Events\KpiValueRecorded;
use App\Modules\Performance\Events\OkrObjectiveCompleted;
use App\Modules\Performance\Listeners\AwardKpiAchievements;
use App\Modules\Performance\Listeners\AwardOkrCompletionAchievements;
use App\Modules\Performance\Listeners\EvaluateKpiValueVariance;
use App\Modules\Sales\Events\SalesOrderRequested;
use App\Modules\Sales\Listeners\CreateSalesOrderFromRequested;
use App\Modules\Sales\Listeners\ProcessCommissionOnPaymentRecorded;
use App\Modules\Sales\Listeners\UpdateSalesOrderOnInvoicePosted;
use App\Modules\WNE\Events\NotificationRequested;
use App\Modules\WNE\Listeners\DeliverRequestedNotification;
use App\Services\AsyncSearchRegistry;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MatterCodeGenerator::class, PrefixedMatterCodeGenerator::class);
        $this->app->bind(CoretaxExportDriverInterface::class, XmlCoretaxExportDriver::class);
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        // WNE_SPECS.md §3I — no EventServiceProvider/auto-discovery exists in this app yet;
        // explicit registration is the first (and only, for now) listener wiring in the codebase.
        Event::listen(NotificationRequested::class, DeliverRequestedNotification::class);

        // §3D/§5 — no real caller exists yet (Sales isn't built), but the seam is
        // cheap and is literally what §3R Automation from ERP asks for. Both
        // listeners draft-create only, never auto-post (see each listener's docblock).
        Event::listen(InvoiceRequested::class, CreateInvoiceFromRequest::class);
        Event::listen(PaymentRequested::class, RecordPaymentFromRequest::class);
        Event::listen(ApBillRequested::class, CreateBillFromRequest::class);
        Event::listen(ApPaymentRequested::class, RecordApPaymentFromRequest::class);

        // §3H — same seam-before-caller precedent (Inventory's Goods Receipt/Issue/
        // Adjustment engine isn't built yet), but these DO auto-post (see
        // InventoryGlPostingService's docblock for why that's correct here unlike above).
        Event::listen(InventoryGoodsReceived::class, PostGoodsReceivedToGl::class);
        Event::listen(InventoryGoodsIssued::class, PostGoodsIssuedToGl::class);
        Event::listen(InventoryStockAdjusted::class, PostStockAdjustmentToGl::class);

        // §3R — the event-bus door into AccountingService::postJournal() (see that method's
        // docblock: this is the one request event that posts immediately, not a draft).
        Event::listen(JournalPostingRequested::class, PostRequestedJournal::class);

        // §3S — same seam-before-caller precedent as §3H (Payroll has zero real code yet),
        // also auto-posts (see PostPayrollRunToGl's docblock).
        Event::listen(PayrollRunPaid::class, PostPayrollRunToGl::class);

        // Sales module listeners (§3I/§3M/§5)
        Event::listen(InvoicePosted::class, UpdateSalesOrderOnInvoicePosted::class);
        Event::listen(PaymentRecorded::class, ProcessCommissionOnPaymentRecorded::class);
        Event::listen(SalesOrderRequested::class, CreateSalesOrderFromRequested::class);

        // §3D/§3G — closes the gap KpiValueService's docblock flagged: the Variance Engine
        // now re-evaluates status on every recorded actual and routes a WNE notification when
        // it lands in warning/breach (see EvaluateKpiValueVariance's own docblock for the
        // "fires on every save, not just the first crossing" MVP simplification).
        Event::listen(KpiValueRecorded::class, EvaluateKpiValueVariance::class);

        // §3I — auto-award checks (target_hit/streak_on_track off the same KPI event above;
        // okr_completed off the OKR status-transition event dispatched from OkrObjectiveService).
        Event::listen(KpiValueRecorded::class, AwardKpiAchievements::class);
        Event::listen(OkrObjectiveCompleted::class, AwardOkrCompletionAchievements::class);

        Auth::provider('eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

        // CENTRAL admin users are the one auth concept that's deliberately NOT
        // tenant-scoped (CENTRAL_SPECS.md §4) — TenantAwareUserProvider above would
        // otherwise refuse to retrieve them since tenancy is never initialized on
        // /central/* routes. Plain EloquentUserProvider, no tenancy gate.
        Auth::provider('central_eloquent', function ($app, array $config) {
            return new EloquentUserProvider($app['hash'], $config['model']);
        });

        // Default Authenticate middleware always redirects to the tenant `login` route
        // regardless of guard — send /central/* guests to the CENTRAL admin login instead.
        Authenticate::redirectUsing(
            fn ($request) => $request->is('central/*') ? route('central.login') : route('login'),
        );

        // Register default searchable entities with 50 limit cap
        AsyncSearchRegistry::register(
            'user',
            User::class,
            ['name', 'email'],
            'name',
            'email',
            fn () => 'User',
            queryCallback: null,
            filterable: [],
            menuCode: 'CONFIG_USERS',
        );

        AsyncSearchRegistry::register(
            'legal_matter',
            Matter::class,
            ['code', 'title'],
            fn ($m) => "{$m->code} — {$m->title}",
            fn ($m) => "Status: {$m->status}",
            'status',
            queryCallback: null,
            filterable: [],
            menuCode: 'LEGAL',
        );

        // Companies only — used by the Contacts/Companies "parent" picker (CRM_SPECS.md
        // §3B/§3C) to find an employer or a parent company.
        AsyncSearchRegistry::register(
            'crm_company',
            Partner::class,
            ['name', 'trade_name'],
            'name',
            'trade_name',
            queryCallback: fn ($query, $search, $extraFilters) => $query
                ->companies()
                ->where('is_active', true)
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('trade_name', 'ilike', '%'.$search.'%');
                })),
            filterable: [],
            menuCode: 'CRM',
        );

        // All active partners, both individual and organization — used by the After
        // Sales Service case's partner picker (CRM_SPECS.md §3E), which unlike the
        // "works at" picker above must be able to find a lone contact too.
        AsyncSearchRegistry::register(
            'crm_partner',
            Partner::class,
            ['name', 'trade_name'],
            'name',
            fn ($p) => $p->type === Partner::TYPE_ORGANIZATION ? 'Company' : 'Contact',
            queryCallback: fn ($query, $search, $extraFilters) => $query
                ->where('is_active', true)
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('trade_name', 'ilike', '%'.$search.'%');
                })),
            filterable: [],
            menuCode: 'CRM',
        );

        // DMS_SPECS.md §3H Object Relation Engine — target-document picker for linking two
        // documents. `exclude_id` (a document can't relate to itself) is read straight out of
        // extraFilters since a queryCallback bypasses the plain filterable-column allowlist.
        AsyncSearchRegistry::register(
            'dms_document',
            Document::class,
            ['title'],
            'title',
            fn (Document $d) => $d->docType?->name ?? $d->folder?->name,
            'status',
            queryCallback: fn ($query, $search, $extraFilters) => $query
                ->with(['docType:id,name', 'folder:id,name'])
                ->when($extraFilters['exclude_id'] ?? null, fn ($q, $excludeId) => $q->where('id', '!=', $excludeId))
                ->when($search !== '', fn ($q) => $q->where('title', 'ilike', '%'.$search.'%')),
            filterable: [],
            menuCode: 'DMS',
        );

        // §3D/§3E Goods Receipt/Issue line product picker — SKU can run into the hundreds,
        // so lines search rather than choosing from a full <select>.
        AsyncSearchRegistry::register(
            'inventory_product',
            Product::class,
            ['sku', 'name'],
            fn (Product $p) => "{$p->sku} — {$p->name}",
            'name',
            queryCallback: fn ($query, $search, $extraFilters) => $query
                ->where('is_active', true)
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                    $q->where('sku', 'ilike', '%'.$search.'%')
                        ->orWhere('name', 'ilike', '%'.$search.'%');
                })),
            filterable: [],
            menuCode: 'INVENTORY',
        );

        // §3L Batch/Lot line picker — always scoped to one product (`extraFilters['product_id']`,
        // required, same `exclude_id`-style bypass-of-`filterable` pattern as DMS's document
        // relation picker), since a lot number is only meaningful within its product.
        AsyncSearchRegistry::register(
            'inventory_batch',
            StockBatch::class,
            ['batch_number'],
            fn (StockBatch $b) => $b->batch_number,
            fn (StockBatch $b) => $b->expiry_date ? "Expires {$b->expiry_date->format('d M Y')}" : null,
            null,
            queryCallback: fn ($query, $search, $extraFilters) => $query
                ->where('product_id', $extraFilters['product_id'] ?? 0)
                ->when($search !== '', fn ($q) => $q->where('batch_number', 'ilike', '%'.$search.'%')),
            filterable: [],
            menuCode: 'INVENTORY',
        );

        // §3B Employee picker
        AsyncSearchRegistry::register(
            'hcm_employee',
            Employee::class,
            ['employee_no', 'full_name', 'nik'],
            fn (Employee $e) => "{$e->employee_no} — {$e->full_name}",
            fn (Employee $e) => $e->position?->job?->title ?? 'Employee',
            'employment_status',
            queryCallback: fn ($query, $search, $extraFilters) => $query
                ->with(['position.job'])
                ->where('employment_status', Employee::STATUS_ACTIVE)
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                    $q->where('full_name', 'ilike', '%'.$search.'%')
                        ->orWhere('employee_no', 'ilike', '%'.$search.'%');
                })),
            filterable: [],
            menuCode: 'HCM',
        );
    }
}
