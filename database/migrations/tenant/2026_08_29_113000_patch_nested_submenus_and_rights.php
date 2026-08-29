<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $submenus = [
            // SCHEDULE
            'SCHEDULE' => [
                ['code' => 'SCHEDULE_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/schedule/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 31],
                ['code' => 'SCHEDULE_TASKS', 'caption' => 'Tasks', 'link' => '/schedule/tasks', 'icon' => 'CheckSquare', 'seq' => 32],
                ['code' => 'SCHEDULE_EVENTS', 'caption' => 'Events', 'link' => '/schedule/events', 'icon' => 'Calendar', 'seq' => 33],
                ['code' => 'SCHEDULE_RESOURCES', 'caption' => 'Resources', 'link' => '/schedule/resources', 'icon' => 'DoorOpen', 'seq' => 34],
            ],
            // CRM
            'CRM' => [
                ['code' => 'CRM_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/crm/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 21],
                ['code' => 'CRM_LEADS', 'caption' => 'Leads', 'link' => '/crm/leads', 'icon' => 'UserPlus', 'seq' => 22],
                ['code' => 'CRM_OPPORTUNITIES', 'caption' => 'Opportunities', 'link' => '/crm/opportunities', 'icon' => 'Target', 'seq' => 23],
                ['code' => 'CRM_CUSTOMERS', 'caption' => 'Customers', 'link' => '/crm/customers', 'icon' => 'Building', 'seq' => 24],
                ['code' => 'CRM_CONTACTS', 'caption' => 'Contacts', 'link' => '/crm/contacts', 'icon' => 'Contact', 'seq' => 25],
                ['code' => 'CRM_TICKETS', 'caption' => 'Tickets', 'link' => '/crm/tickets', 'icon' => 'LifeBuoy', 'seq' => 26],
                ['code' => 'CRM_CASES', 'caption' => 'Service Cases', 'link' => '/crm/service-cases', 'icon' => 'Briefcase', 'seq' => 27],
            ],
            // LEGAL
            'LEGAL' => [
                ['code' => 'LEGAL_MATTERS', 'caption' => 'Matters', 'link' => '/legal/matters', 'icon' => 'FileText', 'seq' => 61],
                ['code' => 'LEGAL_DEEDS', 'caption' => 'Deeds', 'link' => '/legal/deeds', 'icon' => 'Scroll', 'seq' => 62],
                ['code' => 'LEGAL_FIELD_VISITS', 'caption' => 'Field Visits', 'link' => '/legal/field-visits', 'icon' => 'MapPin', 'seq' => 63],
                ['code' => 'LEGAL_BPN', 'caption' => 'BPN Submissions', 'link' => '/legal/bpn-submissions', 'icon' => 'Landmark', 'seq' => 64],
                ['code' => 'LEGAL_TAXES', 'caption' => 'Deed Taxes', 'link' => '/legal/taxes', 'icon' => 'Receipt', 'seq' => 65],
            ],
            // HCM
            'HCM' => [
                ['code' => 'HCM_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/hcm/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 131],
                ['code' => 'HCM_EMPLOYEES', 'caption' => 'Employees', 'link' => '/hcm/employees', 'icon' => 'Users', 'seq' => 132],
                ['code' => 'HCM_DEPARTMENTS', 'caption' => 'Departments', 'link' => '/hcm/departments', 'icon' => 'Network', 'seq' => 133],
                ['code' => 'HCM_DESIGNATIONS', 'caption' => 'Designations', 'link' => '/hcm/designations', 'icon' => 'Award', 'seq' => 134],
                ['code' => 'HCM_BRANCHES', 'caption' => 'Branches', 'link' => '/hcm/branches', 'icon' => 'Building2', 'seq' => 135],
            ],
            // PAYROLL
            'PAYROLL' => [
                ['code' => 'PAYROLL_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/payroll/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 141],
                ['code' => 'PAYROLL_STRUCTURES', 'caption' => 'Salary Structures', 'link' => '/payroll/salary-structures', 'icon' => 'Sliders', 'seq' => 142],
                ['code' => 'PAYROLL_RUNS', 'caption' => 'Payroll Runs', 'link' => '/payroll/runs', 'icon' => 'PlayCircle', 'seq' => 143],
                ['code' => 'PAYROLL_PAYSLIPS', 'caption' => 'Payslips', 'link' => '/payroll/payslips', 'icon' => 'FileSpreadsheet', 'seq' => 144],
            ],
            // INVENTORY
            'INVENTORY' => [
                ['code' => 'INVENTORY_PRODUCTS', 'caption' => 'Products', 'link' => '/inventory/products', 'icon' => 'Package', 'seq' => 71],
                ['code' => 'INVENTORY_CATEGORIES', 'caption' => 'Categories', 'link' => '/inventory/categories', 'icon' => 'Tags', 'seq' => 72],
                ['code' => 'INVENTORY_UOMS', 'caption' => 'UOMs', 'link' => '/inventory/uoms', 'icon' => 'Ruler', 'seq' => 73],
                ['code' => 'INVENTORY_WAREHOUSES', 'caption' => 'Warehouses', 'link' => '/inventory/warehouses', 'icon' => 'Warehouse', 'seq' => 74],
                ['code' => 'INVENTORY_RECEIPTS', 'caption' => 'Goods Receipts', 'link' => '/inventory/goods-receipts', 'icon' => 'ArrowDownLeft', 'seq' => 75],
                ['code' => 'INVENTORY_ISSUES', 'caption' => 'Goods Issues', 'link' => '/inventory/goods-issues', 'icon' => 'ArrowUpRight', 'seq' => 76],
                ['code' => 'INVENTORY_TRANSFERS', 'caption' => 'Transfers', 'link' => '/inventory/transfers', 'icon' => 'ArrowLeftRight', 'seq' => 77],
                ['code' => 'INVENTORY_ADJUSTMENTS', 'caption' => 'Adjustments', 'link' => '/inventory/adjustments', 'icon' => 'Scale', 'seq' => 78],
                ['code' => 'INVENTORY_STOCK_CARD', 'caption' => 'Stock Card', 'link' => '/inventory/stock-card', 'icon' => 'ClipboardList', 'seq' => 79],
                ['code' => 'INVENTORY_VALUATION', 'caption' => 'Valuation', 'link' => '/inventory/valuation', 'icon' => 'TrendingUp', 'seq' => 80],
                ['code' => 'INVENTORY_BATCHES', 'caption' => 'Batches & Serials', 'link' => '/inventory/batches', 'icon' => 'Layers', 'seq' => 81],
            ],
            // SALES
            'SALES' => [
                ['code' => 'SALES_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/sales/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 81],
                ['code' => 'SALES_QUOTATIONS', 'caption' => 'Quotations', 'link' => '/sales/quotations', 'icon' => 'FileText', 'seq' => 82],
                ['code' => 'SALES_ORDERS', 'caption' => 'Sales Orders', 'link' => '/sales/orders', 'icon' => 'ShoppingBag', 'seq' => 83],
                ['code' => 'SALES_DELIVERIES', 'caption' => 'Deliveries', 'link' => '/sales/deliveries', 'icon' => 'Truck', 'seq' => 84],
                ['code' => 'SALES_INVOICES', 'caption' => 'Invoices', 'link' => '/sales/invoices', 'icon' => 'Receipt', 'seq' => 85],
                ['code' => 'SALES_PROFILES', 'caption' => 'Customer Profiles', 'link' => '/sales/customer-profiles', 'icon' => 'UserCheck', 'seq' => 86],
            ],
            // PURCHASE
            'PURCHASE' => [
                ['code' => 'PURCHASE_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/purchase/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 91],
                ['code' => 'PURCHASE_REQUISITIONS', 'caption' => 'Requisitions', 'link' => '/purchase/requisitions', 'icon' => 'FileSignature', 'seq' => 92],
                ['code' => 'PURCHASE_ORDERS', 'caption' => 'Purchase Orders', 'link' => '/purchase/orders', 'icon' => 'ShoppingCart', 'seq' => 93],
                ['code' => 'PURCHASE_RECEIPTS', 'caption' => 'Receipts', 'link' => '/purchase/receipts', 'icon' => 'PackageCheck', 'seq' => 94],
                ['code' => 'PURCHASE_INVOICES', 'caption' => 'Vendor Bills', 'link' => '/purchase/invoices', 'icon' => 'Receipt', 'seq' => 95],
                ['code' => 'PURCHASE_SPEND', 'caption' => 'Spend Analytics', 'link' => '/purchase/spend-analytics', 'icon' => 'PieChart', 'seq' => 96],
                ['code' => 'PURCHASE_ESG', 'caption' => 'ESG Scorecard', 'link' => '/purchase/esg', 'icon' => 'Leaf', 'seq' => 97],
            ],
            // ACCOUNTING
            'ACCOUNTING' => [
                ['code' => 'ACCOUNTING_ACCOUNTS', 'caption' => 'Chart of Accounts', 'link' => '/accounting/accounts', 'icon' => 'BookOpen', 'seq' => 121],
                ['code' => 'ACCOUNTING_JOURNALS', 'caption' => 'Journal Entries', 'link' => '/accounting/journals', 'icon' => 'BookMarked', 'seq' => 122],
                ['code' => 'ACCOUNTING_GL', 'caption' => 'General Ledger', 'link' => '/accounting/general-ledger', 'icon' => 'FileSpreadsheet', 'seq' => 123],
                ['code' => 'ACCOUNTING_TRIAL_BALANCE', 'caption' => 'Trial Balance', 'link' => '/accounting/trial-balance', 'icon' => 'Scale', 'seq' => 124],
                ['code' => 'ACCOUNTING_BALANCE_SHEET', 'caption' => 'Balance Sheet', 'link' => '/accounting/balance-sheet', 'icon' => 'Table', 'seq' => 125],
                ['code' => 'ACCOUNTING_INCOME_STMT', 'caption' => 'Income Statement', 'link' => '/accounting/income-statement', 'icon' => 'LineChart', 'seq' => 126],
                ['code' => 'ACCOUNTING_CASH_FLOW', 'caption' => 'Cash Flow', 'link' => '/accounting/cash-flow', 'icon' => 'Activity', 'seq' => 127],
                ['code' => 'ACCOUNTING_TAX_PERIODS', 'caption' => 'Tax Periods', 'link' => '/accounting/tax-periods', 'icon' => 'CalendarCheck', 'seq' => 128],
            ],
        ];

        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        $staffGroup = ConfigGroup::query()->where('code', 'STAFF')->first();
        $viewerGroup = ConfigGroup::query()->where('code', 'VIEWER')->first();

        foreach ($submenus as $parentCode => $items) {
            $parent = ConfigMenu::query()->where('code', $parentCode)->first();
            if (! $parent) {
                continue;
            }

            foreach ($items as $item) {
                $menu = ConfigMenu::query()->updateOrCreate(
                    ['app_code' => self::APP, 'code' => $item['code']],
                    [
                        'parent_id' => $parent->id,
                        'menu_header' => $parent->menu_header,
                        'menu_caption' => $item['caption'],
                        'menu_link' => $item['link'],
                        'icon' => $item['icon'],
                        'seq' => $item['seq'],
                        'status_code' => 'A',
                        'module_code' => $parent->module_code,
                    ]
                );

                if ($adminGroup) {
                    ConfigRight::query()->updateOrCreate(
                        ['group_id' => $adminGroup->id, 'menu_id' => $menu->id],
                        ['app_code' => self::APP, 'group_code' => 'ADMIN', 'menu_code' => $menu->code, 'trustee' => 'CRUD']
                    );
                }

                if ($staffGroup) {
                    // Inherit staff right from parent if exists, else CRU
                    $parentTrustee = ConfigRight::query()
                        ->where('group_id', $staffGroup->id)
                        ->where('menu_code', $parent->code)
                        ->value('trustee') ?: 'CRU';

                    ConfigRight::query()->updateOrCreate(
                        ['group_id' => $staffGroup->id, 'menu_id' => $menu->id],
                        ['app_code' => self::APP, 'group_code' => 'STAFF', 'menu_code' => $menu->code, 'trustee' => $parentTrustee]
                    );
                }

                if ($viewerGroup) {
                    ConfigRight::query()->updateOrCreate(
                        ['group_id' => $viewerGroup->id, 'menu_id' => $menu->id],
                        ['app_code' => self::APP, 'group_code' => 'VIEWER', 'menu_code' => $menu->code, 'trustee' => 'R']
                    );
                }
            }
        }
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
