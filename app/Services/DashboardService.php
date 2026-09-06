<?php

namespace App\Services;

use App\Modules\Accounting\Models\ApBill;
use App\Modules\CRM\Models\Partner;
use App\Modules\Inventory\Models\InventoryItem;
use App\Modules\Legal\Models\Matter;
use App\Modules\Projects\Models\Issue;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped dashboard stats & executive card launcher.
 */
class DashboardService
{
    public function __construct(
        protected TenantFeatureService $features,
        protected ConfigService $config,
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $crmEnabled = $this->features->enabled('CRM');
        $salesEnabled = $this->features->enabled('SALES');
        $purchaseEnabled = $this->features->enabled('PURCHASE');
        $accountingEnabled = $this->features->enabled('ACCOUNTING');
        $projectsEnabled = $this->features->enabled('PROJECTS');
        $inventoryEnabled = $this->features->enabled('INVENTORY');
        $legalEnabled = $this->features->enabled('LEGAL');
        $scheduleEnabled = $this->features->enabled('SCHEDULE');
        $hcmEnabled = $this->features->enabled('HCM');
        $dmsEnabled = $this->features->enabled('DMS');

        $customersCount = $crmEnabled ? Partner::query()->whereHas('roles.roleType', fn ($q) => $q->where('code', 'customer'))->count() : 0;
        $vendorsCount = ($purchaseEnabled || $crmEnabled) ? Partner::query()->whereHas('roles.roleType', fn ($q) => $q->where('code', 'vendor'))->count() : 0;
        $billsCount = $accountingEnabled ? ApBill::query()->count() : 0;
        $openIssues = $projectsEnabled ? Issue::query()->where('status', '!=', 'done')->count() : 0;
        $itemCount = $inventoryEnabled ? InventoryItem::query()->count() : 0;
        $openMatters = $legalEnabled ? Matter::query()->whereIn('status', ['open', 'in_progress', 'on_hold'])->count() : 0;
        $modules = count($this->features->enabledModules());

        // A bare count says nothing about direction, so each card also carries how many rows
        // appeared in the last 30 days. INVENTORY.items has no created_at column, so that one
        // card stays delta-less rather than getting a fabricated number.
        $since = Carbon::now()->subDays(30);
        $newCustomers = $crmEnabled ? Partner::query()->whereHas('roles.roleType', fn ($q) => $q->where('code', 'customer'))->where('created_at', '>=', $since)->count() : 0;
        $newVendors = ($purchaseEnabled || $crmEnabled) ? Partner::query()->whereHas('roles.roleType', fn ($q) => $q->where('code', 'vendor'))->where('created_at', '>=', $since)->count() : 0;
        $newBills = $accountingEnabled ? ApBill::query()->where('created_at', '>=', $since)->count() : 0;
        $newIssues = $projectsEnabled ? Issue::query()->where('created_at', '>=', $since)->count() : 0;
        $newMatters = $legalEnabled ? Matter::query()->where('created_at', '>=', $since)->count() : 0;

        $firm = $this->config->constValue('APP', 'NAME')?->str1
            ?? (tenant()?->name ?? 'Tenant');

        $cards = array_values(array_filter([
            $crmEnabled ? [
                'title' => 'Klien / Customers',
                'value' => (string) $customersCount,
                'description' => 'Partner berperan sebagai customer',
                'icon' => 'Building2',
                'href' => '/crm/companies?role=customer',
                'delta' => $newCustomers,
            ] : null,
            ($purchaseEnabled || $crmEnabled) ? [
                'title' => 'Pemasok / Tech Vendors',
                'value' => (string) $vendorsCount,
                'description' => 'Partner berperan sebagai vendor',
                'icon' => 'Truck',
                'href' => '/crm/companies?role=vendor',
                'delta' => $newVendors,
            ] : null,
            $accountingEnabled ? [
                'title' => 'Tagihan Operasional (Bills)',
                'value' => (string) $billsCount,
                'description' => 'Tagihan hutang usaha (AP)',
                'icon' => 'Receipt',
                'href' => '/accounting/ap-bills',
                'delta' => $newBills,
            ] : null,
            $projectsEnabled ? [
                'title' => 'Project Tasks',
                'value' => (string) $openIssues,
                'description' => 'Tugas aktif & perbaikan bug',
                'icon' => 'Kanban',
                'href' => '/projects',
                'delta' => $newIssues,
            ] : null,
            $inventoryEnabled ? [
                'title' => 'Total Inventory Items',
                'value' => number_format($itemCount),
                'description' => 'SKU terdaftar di firm ini',
                'icon' => 'Boxes',
                'href' => '/inventory/items',
            ] : null,
            $legalEnabled ? [
                'title' => 'Open Legal Matters',
                'value' => number_format($openMatters),
                'description' => 'Berstatus open atau in progress',
                'icon' => 'Scale',
                'href' => '/legal/matters',
                'delta' => $newMatters,
            ] : null,
        ]));

        return [
            'firm' => $firm,
            'plan' => tenant()?->plan ?? 'enterprise',
            'cards' => $cards,
            'totalModules' => $modules,
            'activities' => $this->recentActivities($accountingEnabled, $projectsEnabled, $crmEnabled, $legalEnabled),
            'shortcuts' => array_values(array_filter([
                $crmEnabled ? ['label' => 'Daftar Klien (Customers)', 'href' => '/crm/companies?role=customer', 'icon' => 'Building2'] : null,
                ($purchaseEnabled || $crmEnabled) ? ['label' => 'Daftar Vendor (Tech)', 'href' => '/crm/companies?role=vendor', 'icon' => 'Truck'] : null,
                $accountingEnabled ? ['label' => 'Tagihan Operasional (Bills)', 'href' => '/accounting/ap-bills', 'icon' => 'Receipt'] : null,
                $projectsEnabled ? ['label' => 'Projects', 'href' => '/projects', 'icon' => 'Kanban'] : null,
                $accountingEnabled ? ['label' => 'Jurnal Berulang (Recurring)', 'href' => '/accounting/recurring-journal-templates', 'icon' => 'RotateCw'] : null,
                ['label' => 'Pengaturan User', 'href' => '/config/users', 'icon' => 'UserRoundCog'],
            ])),
        ];
    }

    /**
    /**
     * @return list<array{id: string, module: string, action: string, user: string, time: string}>
     */
    private function recentActivities(bool $accountingEnabled, bool $projectsEnabled, bool $crmEnabled, bool $legalEnabled): array
    {
        $rows = collect();

        if ($accountingEnabled) {
            ApBill::query()
                ->with('partner:id,name,trade_name')
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get()
                ->each(function (ApBill $b) use ($rows) {
                    $partnerName = $b->partner?->trade_name ?? $b->partner?->name ?? 'Vendor';
                    $rows->push([
                        'id' => 'bill-'.$b->id,
                        'module' => 'Accounting',
                        'action' => 'Tagihan '.$b->bill_no.' ('.$partnerName.') — Rp '.number_format($b->total_amount, 0, ',', '.'),
                        'user' => 'System',
                        'at' => $b->updated_at,
                    ]);
                });
        }

        if ($crmEnabled) {
            Partner::query()
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get()
                ->each(function (Partner $p) use ($rows) {
                    $rows->push([
                        'id' => 'partner-'.$p->id,
                        'module' => 'CRM',
                        'action' => 'Partner '.$p->name.' terdaftar',
                        'user' => 'Admin',
                        'at' => $p->updated_at,
                    ]);
                });
        }

        if ($projectsEnabled) {
            Issue::query()
                ->with('project:id,code')
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get()
                ->each(function (Issue $issue) use ($rows) {
                    $rows->push([
                        'id' => 'issue-'.$issue->id,
                        'module' => 'Projects',
                        'action' => $issue->code.': '.$issue->title.' ('.ucfirst(str_replace('_', ' ', $issue->status)).')',
                        'user' => 'Team',
                        'at' => $issue->updated_at,
                    ]);
                });
        }

        return $rows
            ->sortByDesc(fn ($r) => $r['at']?->timestamp ?? 0)
            ->take(10)
            ->values()
            ->map(fn ($r) => [
                'id' => $r['id'],
                'module' => $r['module'],
                'action' => $r['action'],
                'user' => $r['user'],
                'time' => $r['at'] instanceof Carbon
                    ? $r['at']->diffForHumans()
                    : '',
            ])
            ->all();
    }
}
