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

        $firm = $this->config->constValue('APP', 'NAME')?->str1
            ?? (tenant()?->name ?? 'Tenant');

        $cards = array_values(array_filter([
            $crmEnabled ? [
                'title' => 'Klien / Customers',
                'value' => (string) $customersCount,
                'description' => 'Bank Sampoerna, Knitto MERR',
                'icon' => 'Building2',
                'href' => '/crm/companies?role=customer',
            ] : null,
            ($purchaseEnabled || $crmEnabled) ? [
                'title' => 'Pemasok / Tech Vendors',
                'value' => (string) $vendorsCount,
                'description' => 'Hostinger, OpenAI, Claude, DO, dll',
                'icon' => 'Truck',
                'href' => '/crm/companies?role=vendor',
            ] : null,
            $accountingEnabled ? [
                'title' => 'Tagihan Operasional (Bills)',
                'value' => (string) $billsCount,
                'description' => 'Server VPS, AI APIs, Ads, Cetak',
                'icon' => 'Receipt',
                'href' => '/accounting/ap-bills',
            ] : null,
            $projectsEnabled ? [
                'title' => 'Sprint & UAT Tasks (QLV)',
                'value' => (string) $openIssues,
                'description' => 'Tugas aktif & perbaikan bug',
                'icon' => 'Kanban',
                'href' => '/projects/1',
            ] : null,
            $inventoryEnabled ? [
                'title' => 'Total Inventory Items',
                'value' => number_format($itemCount),
                'description' => 'SKUs in this firm',
                'icon' => 'Boxes',
                'href' => '/inventory/items',
            ] : null,
            $legalEnabled ? [
                'title' => 'Open Legal Matters',
                'value' => number_format($openMatters),
                'description' => 'Open or in progress',
                'icon' => 'Scale',
                'href' => '/legal/matters',
            ] : null,
        ]));

        $appSections = [
            [
                'title' => 'Sales & Hubungan Klien (CRM)',
                'description' => 'Manajemen prospek, database klien korporat, dan paket harga penawaran',
                'apps' => array_values(array_filter([
                    $crmEnabled ? [
                        'code' => 'CUSTOMERS',
                        'title' => 'Data Klien (Customers)',
                        'description' => 'Bank of Sampoerna, Knitto MERR & direktori partner klien B2B',
                        'icon' => 'Building2',
                        'href' => '/crm/companies?role=customer',
                        'badge' => $customersCount.' Klien',
                        'badgeColor' => 'indigo',
                        'links' => [
                            ['label' => 'Semua Klien', 'href' => '/crm/companies?role=customer'],
                            ['label' => 'Profil Penjualan', 'href' => '/sales/master/customers'],
                        ],
                    ] : null,
                    $crmEnabled ? [
                        'code' => 'CRM',
                        'title' => 'CRM & Pipeline',
                        'description' => 'Pipeline leads, kontak person, tiket customer service & prospek',
                        'icon' => 'Users',
                        'href' => '/crm/dashboard',
                        'badge' => 'Core',
                        'badgeColor' => 'blue',
                        'links' => [
                            ['label' => 'Leads', 'href' => '/crm/leads'],
                            ['label' => 'Kontak', 'href' => '/crm/contacts'],
                            ['label' => 'Tiket', 'href' => '/crm/tickets'],
                        ],
                    ] : null,
                    $salesEnabled ? [
                        'code' => 'SALES',
                        'title' => 'Penjualan & Paket',
                        'description' => 'Price List Paket Qualiv (Starter/Pro/Enterprise), Quotations & Sales Orders',
                        'icon' => 'ShoppingCart',
                        'href' => '/sales/dashboard',
                        'badge' => 'Sales',
                        'badgeColor' => 'sky',
                        'links' => [
                            ['label' => 'Sales Orders', 'href' => '/sales/orders'],
                            ['label' => 'Quotations', 'href' => '/sales/quotations'],
                            ['label' => 'Invoices (AR)', 'href' => '/sales/invoices'],
                        ],
                    ] : null,
                ])),
            ],
            [
                'title' => 'Pengadaan & Tech Vendors (Purchase)',
                'description' => 'Manajemen vendor server, AI LLM API, cloud provider & pengeluaran operasional',
                'apps' => array_values(array_filter([
                    ($purchaseEnabled || $crmEnabled) ? [
                        'code' => 'VENDORS',
                        'title' => 'Pemasok & Vendor Tech',
                        'description' => 'Hostinger VPS, OpenAI, Anthropic, DigitalOcean, GitHub, Meta Ads, Supabase',
                        'icon' => 'Truck',
                        'href' => '/crm/companies?role=vendor',
                        'badge' => $vendorsCount.' Vendors',
                        'badgeColor' => 'purple',
                        'links' => [
                            ['label' => 'Daftar Vendor', 'href' => '/crm/companies?role=vendor'],
                            ['label' => 'Profil Pengadaan', 'href' => '/purchase/vendors'],
                        ],
                    ] : null,
                    $purchaseEnabled ? [
                        'code' => 'PURCHASE',
                        'title' => 'Purchase Management',
                        'description' => 'Purchase Requisitions (PR), Purchase Orders (PO), approval & goods receipts',
                        'icon' => 'FileSignature',
                        'href' => '/purchase/dashboard',
                        'badge' => 'Procurement',
                        'badgeColor' => 'emerald',
                        'links' => [
                            ['label' => 'Purchase Orders', 'href' => '/purchase/orders'],
                            ['label' => 'Requisitions', 'href' => '/purchase/requisitions'],
                            ['label' => 'Penerimaan', 'href' => '/purchase/receipts'],
                        ],
                    ] : null,
                    $accountingEnabled ? [
                        'code' => 'AP_BILLS',
                        'title' => 'Tagihan Vendor (Bills)',
                        'description' => 'Pencatatan tagihan server VPS, API tokens, marketing ads & rekonsiliasi',
                        'icon' => 'Receipt',
                        'href' => '/accounting/ap-bills',
                        'badge' => $billsCount.' Bills',
                        'badgeColor' => 'amber',
                        'links' => [
                            ['label' => 'Daftar Bills', 'href' => '/accounting/ap-bills'],
                            ['label' => 'Pembayaran (AP)', 'href' => '/accounting/ap-payments'],
                        ],
                    ] : null,
                ])),
            ],
            [
                'title' => 'Keuangan & Akuntansi (Finance)',
                'description' => 'Pencatatan kas, bank operasional, jurnal berulang otomatis & laporan laba rugi',
                'apps' => array_values(array_filter([
                    $accountingEnabled ? [
                        'code' => 'ACCOUNTING',
                        'title' => 'Bagan Akun (COA)',
                        'description' => 'Struktur akun kas, bank BCA/Mandiri, beban server 61300, beban AI 61100',
                        'icon' => 'Calculator',
                        'href' => '/accounting/accounts',
                        'badge' => 'COA',
                        'badgeColor' => 'teal',
                        'links' => [
                            ['label' => 'Chart of Accounts', 'href' => '/accounting/accounts'],
                            ['label' => 'Buku Besar', 'href' => '/accounting/general-ledger'],
                            ['label' => 'Neraca Saldo', 'href' => '/accounting/trial-balance'],
                        ],
                    ] : null,
                    $accountingEnabled ? [
                        'code' => 'RECURRING',
                        'title' => 'Jurnal Otomatis Berulang',
                        'description' => 'Template transaksi otomatis bulanan VPS Hostinger KVM4 & Meta Verified',
                        'icon' => 'RotateCw',
                        'href' => '/accounting/recurring-journal-templates',
                        'badge' => 'Auto-Sweep',
                        'badgeColor' => 'cyan',
                        'links' => [
                            ['label' => 'Recurring Templates', 'href' => '/accounting/recurring-journal-templates'],
                            ['label' => 'Laporan Finansial', 'href' => '/accounting/financial-statements'],
                        ],
                    ] : null,
                ])),
            ],
            [
                'title' => 'Engineering, Projects & Operasional',
                'description' => 'Kanban sprint tracking, perbaikan UAT bug, kalender kerja & brankas dokumen',
                'apps' => array_values(array_filter([
                    $projectsEnabled ? [
                        'code' => 'PROJECTS',
                        'title' => 'Project Qualiv Platform (QLV)',
                        'description' => 'Sprint board Jira-style, UAT bug tracking, prioritas fitur & roadmap',
                        'icon' => 'Kanban',
                        'href' => '/projects/1',
                        'badge' => $openIssues.' Open Tasks',
                        'badgeColor' => 'rose',
                        'links' => [
                            ['label' => 'Kanban Board', 'href' => '/projects/1'],
                            ['label' => 'Daftar Issue', 'href' => '/projects/1'],
                        ],
                    ] : null,
                    $scheduleEnabled ? [
                        'code' => 'SCHEDULE',
                        'title' => 'Jadwal & Agenda',
                        'description' => 'Manajemen task harian, jadwal posting media sosial, dan timeline event',
                        'icon' => 'CalendarDays',
                        'href' => '/schedule/dashboard',
                        'badge' => 'Calendar',
                        'badgeColor' => 'violet',
                        'links' => [
                            ['label' => 'Tasks', 'href' => '/schedule/tasks'],
                            ['label' => 'Events', 'href' => '/schedule/events'],
                        ],
                    ] : null,
                    $dmsEnabled ? [
                        'code' => 'DMS',
                        'title' => 'Document Vault (DMS)',
                        'description' => 'Penyimpanan dokumen kontrak kerjasama, NDA klien & file master',
                        'icon' => 'FolderOpen',
                        'href' => '/dms/dashboard',
                        'badge' => 'Docs',
                        'badgeColor' => 'slate',
                        'links' => [
                            ['label' => 'File Manager', 'href' => '/dms/dashboard'],
                        ],
                    ] : null,
                ])),
            ],
            [
                'title' => 'SDM & Pengaturan Sistem',
                'description' => 'Penggajian tim, struktur organisasi, hak akses pengguna & kustomisasi',
                'apps' => array_values(array_filter([
                    $hcmEnabled ? [
                        'code' => 'HCM',
                        'title' => 'HCM & Payroll',
                        'description' => 'Direktori tim Andry & Henry, struktur penggajian & slip gaji bulanan',
                        'icon' => 'UserCog',
                        'href' => '/hcm/dashboard',
                        'badge' => 'People',
                        'badgeColor' => 'orange',
                        'links' => [
                            ['label' => 'Karyawan', 'href' => '/hcm/employees'],
                            ['label' => 'Payroll Runs', 'href' => '/payroll/runs'],
                        ],
                    ] : null,
                    [
                        'code' => 'SYSCONFIG',
                        'title' => 'Pengaturan & Akses',
                        'description' => 'Akun admin, hak akses grup/user, audit log & tema tampilan',
                        'icon' => 'Settings',
                        'href' => '/config/users',
                        'badge' => 'Admin',
                        'badgeColor' => 'gray',
                        'links' => [
                            ['label' => 'User & Password', 'href' => '/config/users'],
                            ['label' => 'Hak Akses', 'href' => '/config/rights'],
                            ['label' => 'Tema UI', 'href' => '/config/theme'],
                        ],
                    ],
                ])),
            ],
        ];

        return [
            'firm' => $firm,
            'plan' => tenant()?->plan ?? 'enterprise',
            'cards' => $cards,
            'appSections' => $appSections,
            'activities' => $this->recentActivities($accountingEnabled, $projectsEnabled, $crmEnabled, $legalEnabled),
            'shortcuts' => array_values(array_filter([
                $crmEnabled ? ['label' => 'Daftar Klien (Customers)', 'href' => '/crm/companies?role=customer', 'icon' => 'Building2'] : null,
                ($purchaseEnabled || $crmEnabled) ? ['label' => 'Daftar Vendor (Tech)', 'href' => '/crm/companies?role=vendor', 'icon' => 'Truck'] : null,
                $accountingEnabled ? ['label' => 'Tagihan Operasional (Bills)', 'href' => '/accounting/ap-bills', 'icon' => 'Receipt'] : null,
                $projectsEnabled ? ['label' => 'Project Kanban (QLV)', 'href' => '/projects/1', 'icon' => 'Kanban'] : null,
                $accountingEnabled ? ['label' => 'Jurnal Berulang (Recurring)', 'href' => '/accounting/recurring-journal-templates', 'icon' => 'RotateCw'] : null,
                ['label' => 'Pengaturan User', 'href' => '/config/users', 'icon' => 'UserRoundCog'],
            ])),
        ];
    }

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
