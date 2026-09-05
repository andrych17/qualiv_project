<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Database\Seeder;

/**
 * Tenant SysConfig seed — groups, menus, CRUD rights, user↔group assign, consts.
 * Must run inside $tenant->run(...).
 *
 * Migration lives at database/migrations/tenant/2026_07_13_100000_create_config_tables.php
 * (SYSCONFIG.config_groups|grpusers|menus|rights|consts) — no extra menu migration needed.
 */
class SysConfigSeeder extends Seeder
{
    private const APP = 'NUSAEVO';

    /** @var list<array{code: string, descr: string}> */
    private const GROUPS = [
        ['code' => 'ADMIN', 'descr' => 'Full access administrators'],
        ['code' => 'STAFF', 'descr' => 'Operational staff — create/update day-to-day data'],
        ['code' => 'VIEWER', 'descr' => 'Read-only access'],
    ];

    /**
     * Email → group code(s). Users must already exist in the tenant DB.
     *
     * @var array<string, list<string>>
     */
    private const USER_GROUPS = [
        'admin@nusaevo.com' => ['ADMIN'],
        'staff@nusaevo.com' => ['STAFF'],
        'viewer@nusaevo.com' => ['VIEWER'],
        'andry@nusaevo.com' => ['ADMIN'],
        'tirta@nusaevo.com' => ['ADMIN'],
        'simon@nusaevo.com' => ['ADMIN'],
        'andry@qualiv.id' => ['ADMIN'],
        'henry@qualiv.id' => ['ADMIN'],
    ];

    public function run(): void
    {
        $groups = $this->seedGroups();
        $menus = $this->seedMenus();
        $this->seedRights($groups, $menus);
        $this->seedGroupUsers($groups);
        $this->seedConsts();
        $this->seedSnums();
    }

    /** @return array<string, ConfigGroup> */
    private function seedGroups(): array
    {
        $map = [];
        foreach (self::GROUPS as $row) {
            $map[$row['code']] = ConfigGroup::query()->updateOrCreate(
                ['app_code' => self::APP, 'code' => $row['code']],
                [
                    'descr' => $row['descr'],
                    'status_code' => 'A',
                ],
            );
        }

        return $map;
    }

    /**
     * Flat sidebar menus. status I = placeholder (not shipped yet) — hidden from nav.
         /**
     * @return array<string, ConfigMenu>
     */
    private function seedMenus(): array
    {
        $rows = [
            // Live
            ['code' => 'DASHBOARD', 'menu_header' => 'Main', 'menu_caption' => 'Dashboard', 'menu_link' => '/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 10, 'status_code' => 'A'],
            ['code' => 'INVENTORY', 'menu_header' => 'Operations', 'menu_caption' => 'Inventory', 'menu_link' => '/inventory/products', 'icon' => 'Boxes', 'seq' => 70, 'status_code' => 'A'],
            ['code' => 'CONFIG_MENUS', 'menu_header' => 'System', 'menu_caption' => 'Menus', 'menu_link' => '/config/menus', 'icon' => 'Menu', 'seq' => 200, 'status_code' => 'A'],
            ['code' => 'CONFIG_GROUPS', 'menu_header' => 'System', 'menu_caption' => 'Groups', 'menu_link' => '/config/groups', 'icon' => 'Shield', 'seq' => 210, 'status_code' => 'A'],
            ['code' => 'CONFIG_USERS', 'menu_header' => 'System', 'menu_caption' => 'Users', 'menu_link' => '/config/users', 'icon' => 'UserRoundCog', 'seq' => 215, 'status_code' => 'A'],
            ['code' => 'CONFIG_MODULES', 'menu_header' => 'System', 'menu_caption' => 'Modules', 'menu_link' => '/config/modules', 'icon' => 'AppWindow', 'seq' => 218, 'status_code' => 'A'],
            ['code' => 'CONFIG_CONSTS', 'menu_header' => 'System', 'menu_caption' => 'Constants', 'menu_link' => '/config/consts', 'icon' => 'SlidersHorizontal', 'seq' => 220, 'status_code' => 'A'],
            ['code' => 'CONFIG_FIELDS', 'menu_header' => 'System', 'menu_caption' => 'Custom Fields', 'menu_link' => '/config/fields', 'icon' => 'ListPlus', 'seq' => 222, 'status_code' => 'A'],
            ['code' => 'CONFIG_SERIALS', 'menu_header' => 'System', 'menu_caption' => 'Serials', 'menu_link' => '/config/serials', 'icon' => 'Hash', 'seq' => 225, 'status_code' => 'A'],
            ['code' => 'CONFIG_THEME', 'menu_header' => 'System', 'menu_caption' => 'Theme', 'menu_link' => '/config/theme', 'icon' => 'Palette', 'seq' => 228, 'status_code' => 'A'],
            ['code' => 'DESIGN_SYSTEM', 'menu_header' => 'System', 'menu_caption' => 'Komponen UI', 'menu_link' => '/design-system', 'icon' => 'Layers', 'seq' => 230, 'status_code' => 'A'],

            // Core & Vertical Modules
            ['code' => 'CRM', 'menu_header' => 'Core', 'menu_caption' => 'CRM', 'menu_link' => '/crm/dashboard', 'icon' => 'Users', 'seq' => 20, 'status_code' => 'A'],
            ['code' => 'CRM_MERGE', 'menu_header' => 'Core', 'menu_caption' => 'CRM — Merge', 'menu_link' => '/crm/merge', 'icon' => 'Merge', 'seq' => 21, 'status_code' => 'I'],
            ['code' => 'SCHEDULE', 'menu_header' => 'Core', 'menu_caption' => 'Schedule', 'menu_link' => '/schedule/dashboard', 'icon' => 'CalendarDays', 'seq' => 30, 'status_code' => 'A'],
            ['code' => 'WNE', 'menu_header' => 'Core', 'menu_caption' => 'Workflow & Notifications', 'menu_link' => '/wne/dashboard', 'icon' => 'Workflow', 'seq' => 40, 'status_code' => 'A'],
            ['code' => 'DMS', 'menu_header' => 'Core', 'menu_caption' => 'Documents', 'menu_link' => '/dms/dashboard', 'icon' => 'FolderOpen', 'seq' => 45, 'status_code' => 'A'],
            ['code' => 'LEGAL', 'menu_header' => 'Vertical', 'menu_caption' => 'Legal', 'menu_link' => '/legal/dashboard', 'icon' => 'Scale', 'seq' => 60, 'status_code' => 'A'],
            ['code' => 'PROJECTS', 'menu_header' => 'Internal', 'menu_caption' => 'Projects', 'menu_link' => '/projects', 'icon' => 'Kanban', 'seq' => 65, 'status_code' => 'A'],
            ['code' => 'SALES', 'menu_header' => 'Operations', 'menu_caption' => 'Sales', 'menu_link' => '/sales/dashboard', 'icon' => 'ShoppingCart', 'seq' => 80, 'status_code' => 'A'],
            ['code' => 'PURCHASE', 'menu_header' => 'Operations', 'menu_caption' => 'Purchase', 'menu_link' => '/purchase/dashboard', 'icon' => 'Truck', 'seq' => 90, 'status_code' => 'A'],
            ['code' => 'ACCOUNTING', 'menu_header' => 'Operations', 'menu_caption' => 'Accounting', 'menu_link' => '/accounting/accounts', 'icon' => 'Calculator', 'seq' => 120, 'status_code' => 'A'],
            ['code' => 'HCM', 'menu_header' => 'People', 'menu_caption' => 'HCM', 'menu_link' => '/hcm/dashboard', 'icon' => 'UserCog', 'seq' => 130, 'status_code' => 'A'],
            ['code' => 'PAYROLL', 'menu_header' => 'People', 'menu_caption' => 'Payroll', 'menu_link' => '/payroll/dashboard', 'icon' => 'Wallet', 'seq' => 140, 'status_code' => 'A'],
            // §3C ships (Targets & KPI Setup) — KPI Definitions is the landing page for now
            // (no §3A dashboard yet), same "point straight at the built page" convention
            // WNE/DMS/Accounting used before their own dashboards existed.
            ['code' => 'PERFORMANCE', 'menu_header' => 'People', 'menu_caption' => 'Performance', 'menu_link' => '/performance/kpi-definitions', 'icon' => 'Target', 'seq' => 150, 'status_code' => 'A'],
            // PP_SPECS.md §3O ships (Dashboard) — full plan only (config/tenant_modules.php).
            ['code' => 'PP', 'menu_header' => 'Operations', 'menu_caption' => 'Production Planning', 'menu_link' => '/pp/dashboard', 'icon' => 'CalendarRange', 'seq' => 160, 'status_code' => 'A'],
            // MES_SPECS.md — full plan only (config/tenant_modules.php).
            ['code' => 'MES', 'menu_header' => 'Operations', 'menu_caption' => 'Manufacturing Execution', 'menu_link' => '/mes/work-centers', 'icon' => 'Factory', 'seq' => 230, 'status_code' => 'A'],
        ];

        $submenus = [
            'SCHEDULE' => [
                ['code' => 'SCHEDULE_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/schedule/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 31],
                ['code' => 'SCHEDULE_TASKS', 'caption' => 'Tasks', 'link' => '/schedule/tasks', 'icon' => 'CheckSquare', 'seq' => 32],
                ['code' => 'SCHEDULE_EVENTS', 'caption' => 'Events', 'link' => '/schedule/events', 'icon' => 'Calendar', 'seq' => 33],
                ['code' => 'SCHEDULE_RESOURCES', 'caption' => 'Resources', 'link' => '/schedule/resources', 'icon' => 'DoorOpen', 'seq' => 34],
            ],
            'CRM' => [
                ['code' => 'CRM_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/crm/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 21],
                ['code' => 'CRM_LEADS', 'caption' => 'Leads', 'link' => '/crm/leads', 'icon' => 'UserPlus', 'seq' => 22],
                ['code' => 'CRM_COMPANIES', 'caption' => 'Companies', 'link' => '/crm/companies', 'icon' => 'Building', 'seq' => 24],
                ['code' => 'CRM_CONTACTS', 'caption' => 'Contacts', 'link' => '/crm/contacts', 'icon' => 'Contact', 'seq' => 25],
                ['code' => 'CRM_TICKETS', 'caption' => 'Tickets', 'link' => '/crm/tickets', 'icon' => 'LifeBuoy', 'seq' => 26],
                ['code' => 'CRM_CASES', 'caption' => 'Service Cases', 'link' => '/crm/service-cases', 'icon' => 'Briefcase', 'seq' => 27],
            ],
            'LEGAL' => [
                ['code' => 'LEGAL_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/legal/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 60],
                ['code' => 'LEGAL_MATTERS', 'caption' => 'Matters', 'link' => '/legal/matters', 'icon' => 'FileText', 'seq' => 61],
                ['code' => 'LEGAL_DEEDS', 'caption' => 'Deeds', 'link' => '/legal/deeds', 'icon' => 'Scroll', 'seq' => 62],
                ['code' => 'LEGAL_FIELD_VISITS', 'caption' => 'Field Visits', 'link' => '/legal/field-visits', 'icon' => 'MapPin', 'seq' => 63],
                ['code' => 'LEGAL_BPN', 'caption' => 'BPN Submissions', 'link' => '/legal/bpn-submissions', 'icon' => 'Landmark', 'seq' => 64],
                ['code' => 'LEGAL_TAXES', 'caption' => 'Deed Taxes', 'link' => '/legal/taxes', 'icon' => 'Receipt', 'seq' => 65],
            ],
            'HCM' => [
                ['code' => 'HCM_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/hcm/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 131],
                ['code' => 'HCM_EMPLOYEES', 'caption' => 'Employees', 'link' => '/hcm/employees', 'icon' => 'Users', 'seq' => 132],
                ['code' => 'HCM_DEPARTMENTS', 'caption' => 'Departments', 'link' => '/hcm/org-units?unit_type=department', 'icon' => 'Network', 'seq' => 133],
                ['code' => 'HCM_DESIGNATIONS', 'caption' => 'Designations', 'link' => '/hcm/jobs', 'icon' => 'Award', 'seq' => 134],
                ['code' => 'HCM_BRANCHES', 'caption' => 'Branches', 'link' => '/hcm/org-units?unit_type=branch', 'icon' => 'Building2', 'seq' => 135],
            ],
            'PAYROLL' => [
                ['code' => 'PAYROLL_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/payroll/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 141],
                ['code' => 'PAYROLL_STRUCTURES', 'caption' => 'Salary Structures', 'link' => '/payroll/structures', 'icon' => 'Sliders', 'seq' => 142],
                ['code' => 'PAYROLL_RUNS', 'caption' => 'Payroll Runs', 'link' => '/payroll/runs', 'icon' => 'PlayCircle', 'seq' => 143],
                ['code' => 'PAYROLL_PAYSLIPS', 'caption' => 'Payslips', 'link' => '/payroll/payslips', 'icon' => 'FileSpreadsheet', 'seq' => 144],
            ],
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
            'SALES' => [
                ['code' => 'SALES_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/sales/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 81],
                ['code' => 'SALES_OPPORTUNITIES', 'caption' => 'Opportunity Management', 'link' => '/sales/opportunities', 'icon' => 'Target', 'seq' => 82],
                ['code' => 'SALES_QUOTATIONS', 'caption' => 'Quotations', 'link' => '/sales/quotations', 'icon' => 'FileText', 'seq' => 83],
                ['code' => 'SALES_ORDERS', 'caption' => 'Sales Orders', 'link' => '/sales/orders', 'icon' => 'ShoppingBag', 'seq' => 84],
                ['code' => 'SALES_INVOICES', 'caption' => 'Invoices', 'link' => '/sales/invoices', 'icon' => 'Receipt', 'seq' => 86],
                ['code' => 'SALES_PROFILES', 'caption' => 'Customer Profiles', 'link' => '/sales/master/customers', 'icon' => 'UserCheck', 'seq' => 87],
            ],
            'PURCHASE' => [
                ['code' => 'PURCHASE_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/purchase/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 91],
                ['code' => 'PURCHASE_REQUISITIONS', 'caption' => 'Requisitions', 'link' => '/purchase/requisitions', 'icon' => 'FileSignature', 'seq' => 92],
                ['code' => 'PURCHASE_ORDERS', 'caption' => 'Purchase Orders', 'link' => '/purchase/orders', 'icon' => 'ShoppingCart', 'seq' => 93],
                ['code' => 'PURCHASE_RECEIPTS', 'caption' => 'Receipts', 'link' => '/purchase/receipts', 'icon' => 'PackageCheck', 'seq' => 94],
                ['code' => 'PURCHASE_INVOICES', 'caption' => 'Vendor Bills', 'link' => '/purchase/invoices', 'icon' => 'Receipt', 'seq' => 95],
                ['code' => 'PURCHASE_SPEND', 'caption' => 'Spend Analytics', 'link' => '/purchase/analytics/spend', 'icon' => 'PieChart', 'seq' => 96],
                ['code' => 'PURCHASE_ESG', 'caption' => 'ESG Scorecard', 'link' => '/purchase/analytics/esg', 'icon' => 'Leaf', 'seq' => 97],
            ],
            'ACCOUNTING' => [
                ['code' => 'ACCOUNTING_ACCOUNTS', 'caption' => 'Chart of Accounts', 'link' => '/accounting/accounts', 'icon' => 'BookOpen', 'seq' => 121],
                ['code' => 'ACCOUNTING_JOURNALS', 'caption' => 'Journal Entries', 'link' => '/accounting/journals', 'icon' => 'BookMarked', 'seq' => 122],
                ['code' => 'ACCOUNTING_GL', 'caption' => 'General Ledger', 'link' => '/accounting/general-ledger', 'icon' => 'FileSpreadsheet', 'seq' => 123],
                ['code' => 'ACCOUNTING_TRIAL_BALANCE', 'caption' => 'Trial Balance', 'link' => '/accounting/reports/trial-balance', 'icon' => 'Scale', 'seq' => 124],
                ['code' => 'ACCOUNTING_BALANCE_SHEET', 'caption' => 'Balance Sheet', 'link' => '/accounting/reports/balance-sheet', 'icon' => 'Table', 'seq' => 125],
                ['code' => 'ACCOUNTING_INCOME_STMT', 'caption' => 'Income Statement', 'link' => '/accounting/reports/profit-loss', 'icon' => 'LineChart', 'seq' => 126],
                ['code' => 'ACCOUNTING_CASH_FLOW', 'caption' => 'Cash Flow', 'link' => '/accounting/reports/cash-flow', 'icon' => 'Activity', 'seq' => 127],
                ['code' => 'ACCOUNTING_TAX_PERIODS', 'caption' => 'Tax Periods', 'link' => '/accounting/tax-periods', 'icon' => 'CalendarCheck', 'seq' => 128],
            ],
            'WNE' => [
                ['code' => 'WNE_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/wne/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 41],
                ['code' => 'WNE_WORKFLOWS', 'caption' => 'Workflows', 'link' => '/wne/workflows', 'icon' => 'Workflow', 'seq' => 42],
                ['code' => 'WNE_APPROVALS', 'caption' => 'My Approvals', 'link' => '/wne/my-tasks', 'icon' => 'CheckCircle2', 'seq' => 43],
                ['code' => 'WNE_TEMPLATES', 'caption' => 'Templates', 'link' => '/wne/templates', 'icon' => 'FileCode', 'seq' => 44],
                ['code' => 'WNE_PREFERENCES', 'caption' => 'Preferences', 'link' => '/wne/preferences', 'icon' => 'Sliders', 'seq' => 45],
                ['code' => 'WNE_DEAD_LETTERS', 'caption' => 'Dead Letters', 'link' => '/wne/dead-letters', 'icon' => 'AlertOctagon', 'seq' => 46],
            ],
            'DMS' => [
                ['code' => 'DMS_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/dms/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 47],
                ['code' => 'DMS_DOCUMENTS', 'caption' => 'Documents', 'link' => '/dms/documents', 'icon' => 'FileText', 'seq' => 48],
                ['code' => 'DMS_CATEGORIES', 'caption' => 'Categories', 'link' => '/dms/folders', 'icon' => 'FolderOpen', 'seq' => 49],
            ],
            'PERFORMANCE' => [
                ['code' => 'PERFORMANCE_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/performance/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 150],
                ['code' => 'PERFORMANCE_KPIS', 'caption' => 'KPIs', 'link' => '/performance/kpi-definitions', 'icon' => 'Target', 'seq' => 151],
                ['code' => 'PERFORMANCE_OKRS', 'caption' => 'OKRs', 'link' => '/performance/okr-objectives', 'icon' => 'Compass', 'seq' => 152],
                ['code' => 'PERFORMANCE_BUDGETS', 'caption' => 'Budgets', 'link' => '/performance/budgets', 'icon' => 'PieChart', 'seq' => 153],
                ['code' => 'PERFORMANCE_SCORECARDS', 'caption' => 'Scorecards', 'link' => '/performance/scorecards', 'icon' => 'Award', 'seq' => 154],
            ],
            'PP' => [
                ['code' => 'PP_DASHBOARD', 'caption' => 'Dashboard', 'link' => '/pp/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 160],
                ['code' => 'PP_ITEM_PARAMS', 'caption' => 'Item Planning Parameters', 'link' => '/pp/item-planning-params', 'icon' => 'SlidersHorizontal', 'seq' => 161],
                ['code' => 'PP_DEMAND', 'caption' => 'Demand Aggregation', 'link' => '/pp/demand', 'icon' => 'TrendingUp', 'seq' => 162],
                ['code' => 'PP_DEMAND_FORECASTS', 'caption' => 'Demand Forecasts', 'link' => '/pp/demand-forecasts', 'icon' => 'LineChart', 'seq' => 163],
                ['code' => 'PP_BOMS', 'caption' => 'Bills of Material', 'link' => '/pp/boms', 'icon' => 'Layers3', 'seq' => 164],
                ['code' => 'PP_RECIPES', 'caption' => 'Recipes', 'link' => '/pp/recipes', 'icon' => 'FlaskConical', 'seq' => 165],
                ['code' => 'PP_PLANNED_ORDERS', 'caption' => 'Planned Orders', 'link' => '/pp/planned-orders', 'icon' => 'ClipboardList', 'seq' => 166],
                ['code' => 'PP_MPS', 'caption' => 'Master Production Schedule', 'link' => '/pp/mps', 'icon' => 'Grid3x3', 'seq' => 167],
                ['code' => 'PP_RESOURCES', 'caption' => 'Resources', 'link' => '/pp/resources', 'icon' => 'Wrench', 'seq' => 168],
                ['code' => 'PP_RESOURCE_GROUPS', 'caption' => 'Resource Groups', 'link' => '/pp/resource-groups', 'icon' => 'Group', 'seq' => 169],
                ['code' => 'PP_CAPACITY', 'caption' => 'Capacity Planning (RCCP)', 'link' => '/pp/capacity-plans', 'icon' => 'Gauge', 'seq' => 170],
                ['code' => 'PP_EXCEPTIONS', 'caption' => 'Planning Exceptions', 'link' => '/pp/exceptions', 'icon' => 'AlertTriangle', 'seq' => 171],
                ['code' => 'PP_SCHEDULE_OPS', 'caption' => 'Detailed Scheduling', 'link' => '/pp/schedule-ops', 'icon' => 'GanttChart', 'seq' => 172],
                ['code' => 'PP_CHANGEOVER_MATRIX', 'caption' => 'Changeover Matrix', 'link' => '/pp/changeover-matrix', 'icon' => 'Shuffle', 'seq' => 173],
            ],
            'MES' => [
                ['code' => 'MES_WORK_CENTERS', 'caption' => 'Work Centers', 'link' => '/mes/work-centers', 'icon' => 'Boxes', 'seq' => 231],
                ['code' => 'MES_MACHINES', 'caption' => 'Machines', 'link' => '/mes/machines', 'icon' => 'Cog', 'seq' => 232],
                ['code' => 'MES_STATIONS', 'caption' => 'Stations', 'link' => '/mes/stations', 'icon' => 'MapPin', 'seq' => 233],
                ['code' => 'MES_ROUTINGS', 'caption' => 'Routings', 'link' => '/mes/routings', 'icon' => 'Route', 'seq' => 234],
                ['code' => 'MES_PROCESS_PHASES', 'caption' => 'Process Phases', 'link' => '/mes/process-phases', 'icon' => 'Thermometer', 'seq' => 235],
                ['code' => 'MES_PROD_ORDERS', 'caption' => 'Production Orders', 'link' => '/mes/prod-orders', 'icon' => 'ClipboardList', 'seq' => 236],
                ['code' => 'MES_PROD_EVENTS', 'caption' => 'Production Events', 'link' => '/mes/prod-events', 'icon' => 'History', 'seq' => 237],
                ['code' => 'MES_QC_PLANS', 'caption' => 'QC Inspection Plans', 'link' => '/mes/qc-plans', 'icon' => 'ClipboardCheck', 'seq' => 238],
                ['code' => 'MES_TRACEABILITY', 'caption' => 'Traceability', 'link' => '/mes/traceability', 'icon' => 'GitBranch', 'seq' => 239],
                ['code' => 'MES_AUDIT_LOGS', 'caption' => 'Audit Trail', 'link' => '/mes/audit-logs', 'icon' => 'FileClock', 'seq' => 240],
                ['code' => 'MES_DOWNTIME', 'caption' => 'Equipment Downtime', 'link' => '/mes/downtime-events', 'icon' => 'AlertOctagon', 'seq' => 241],
                ['code' => 'MES_OEE', 'caption' => 'OEE & Process KPIs', 'link' => '/mes/oee', 'icon' => 'Gauge', 'seq' => 242],
                ['code' => 'MES_SHIFT_HANDOVERS', 'caption' => 'Shift Handover', 'link' => '/mes/shift-handovers', 'icon' => 'ArrowLeftRight', 'seq' => 243],
                ['code' => 'MES_DISPATCH_QUEUE', 'caption' => 'Dispatch Queue', 'link' => '/mes/dispatch-queue', 'icon' => 'ListOrdered', 'seq' => 244],
                ['code' => 'MES_ANDON', 'caption' => 'Andon Board', 'link' => '/mes/andon', 'icon' => 'AlarmClockCheck', 'seq' => 245],
                ['code' => 'MES_DASHBOARD_PLANT', 'caption' => 'Plant Dashboard', 'link' => '/mes/dashboards/plant', 'icon' => 'LayoutDashboard', 'seq' => 246],
                ['code' => 'MES_DASHBOARD_LINE', 'caption' => 'Line Dashboard', 'link' => '/mes/dashboards/line', 'icon' => 'BarChart3', 'seq' => 247],
                ['code' => 'MES_DASHBOARD_PROCESS', 'caption' => 'Process Area Dashboard', 'link' => '/mes/dashboards/process-area', 'icon' => 'FlaskConical', 'seq' => 248],
            ],
        ];

        $map = [];
        foreach ($rows as $row) {
            $map[$row['code']] = ConfigMenu::query()->updateOrCreate(
                ['app_code' => self::APP, 'code' => $row['code']],
                [
                    'parent_id' => null,
                    'menu_header' => $row['menu_header'],
                    'menu_caption' => $row['menu_caption'],
                    'menu_link' => $row['menu_link'],
                    'icon' => $row['icon'],
                    'seq' => $row['seq'],
                    'status_code' => $row['status_code'],
                    'module_code' => (str_starts_with($row['code'], 'CONFIG_') || $row['code'] === 'DASHBOARD')
                        ? null
                        : $row['code'],
                ],
            );
        }

        foreach ($submenus as $parentCode => $items) {
            $parent = $map[$parentCode] ?? null;
            if (! $parent) {
                continue;
            }

            foreach ($items as $item) {
                $map[$item['code']] = ConfigMenu::query()->updateOrCreate(
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
            }
        }

        return $map;
    }

    /**
     * @param  array<string, ConfigGroup>  $groups
     * @param  array<string, ConfigMenu>  $menus
     */
    private function seedRights(array $groups, array $menus): void
    {
        // rights still seeded for placeholders so flipping status A later is enough
        $matrix = [
            'ADMIN' => array_fill_keys(array_keys($menus), 'CRUD'),
            'STAFF' => [
                'DASHBOARD' => 'R',
                'INVENTORY' => 'CRUD',
                'LEGAL' => 'CRUD',
                'CRM' => 'CRUD',
                'SCHEDULE' => 'CRUD',
                'WNE' => 'CRUD',
                'DMS' => 'CRUD',
                'PROJECTS' => 'CRUD',
                'ACCOUNTING' => 'CRUD',
                'HCM' => 'CRUD',
                'PAYROLL' => 'CRUD',
                'PERFORMANCE' => 'CRUD',
                'PURCHASE' => 'CRUD',
                'SALES' => 'CRUD',
                'PP' => 'CRUD',
                'MES' => 'CRUD',
                'DESIGN_SYSTEM' => 'R',
            ],
            'VIEWER' => [
                'DASHBOARD' => 'R',
                'INVENTORY' => 'R',
                'LEGAL' => 'R',
                'CRM' => 'R',
                'SCHEDULE' => 'R',
                'WNE' => 'R',
                'DMS' => 'R',
                'PROJECTS' => 'R',
                'ACCOUNTING' => 'R',
                'HCM' => 'R',
                'PAYROLL' => 'R',
                'PERFORMANCE' => 'R',
                'PURCHASE' => 'R',
                'SALES' => 'R',
                'PP' => 'R',
                'MES' => 'R',
                'DESIGN_SYSTEM' => 'R',
            ],
        ];

        // Also propagate parent permissions to child menus for STAFF and VIEWER
        foreach ($menus as $code => $menu) {
            if ($menu->parent_id) {
                $parent = ConfigMenu::query()->find($menu->parent_id);
                if ($parent) {
                    if (isset($matrix['STAFF'][$parent->code])) {
                        $matrix['STAFF'][$code] = $matrix['STAFF'][$parent->code];
                    }
                    if (isset($matrix['VIEWER'][$parent->code])) {
                        $matrix['VIEWER'][$code] = $matrix['VIEWER'][$parent->code];
                    }
                }
            }
        }

        foreach ($matrix as $groupCode => $menuTrustees) {
            $group = $groups[$groupCode];
            foreach ($menuTrustees as $menuCode => $trustee) {
                $menu = $menus[$menuCode] ?? null;
                if (! $menu) {
                    continue;
                }
                ConfigRight::query()->updateOrCreate(
                    ['group_id' => $group->id, 'menu_id' => $menu->id],
                    [
                        'group_code' => $group->code,
                        'menu_code' => $menu->code,
                        'menu_seq' => $menu->seq,
                        'trustee' => $trustee,
                        'app_code' => self::APP,
                    ],
                );
            }
        }
    }

    /** @param  array<string, ConfigGroup>  $groups */
    private function seedGroupUsers(array $groups): void
    {
        foreach (self::USER_GROUPS as $email => $groupCodes) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                continue;
            }

            foreach ($groupCodes as $groupCode) {
                $group = $groups[$groupCode];
                ConfigGroupUser::query()->updateOrCreate(
                    ['group_id' => $group->id, 'user_id' => $user->id],
                    ['group_code' => $group->code],
                );
            }
        }
    }

    private function seedConsts(): void
    {
        $consts = [
            ['const_group' => 'APP', 'group_code' => 'NAME', 'seq' => 1, 'str1' => 'NusaEvo ERP', 'note1' => 'Product display name'],
            ['const_group' => 'APP', 'group_code' => 'TZ', 'seq' => 2, 'str1' => 'Asia/Jakarta', 'note1' => 'Default timezone'],
            ['const_group' => 'INVENTORY', 'group_code' => 'LOW_STOCK', 'seq' => 1, 'num1' => 10, 'note1' => 'Default low-stock threshold'],
            // §3B: tenant default costing method, overridable per product — customization
            // ladder rung 1, never hardcoded (CLAUDE.md §2).
            ['const_group' => 'INVENTORY', 'group_code' => 'DEFAULT_COSTING_METHOD', 'seq' => 2, 'str1' => 'fifo', 'note1' => 'fifo | average — default for new products'],
            // §3L: a batch is "expiring soon" inside this many days — tenant-editable since a
            // pharma-adjacent tenant wants a longer lead time than a food-adjacent one.
            ['const_group' => 'INVENTORY', 'group_code' => 'BATCH_EXPIRY_WARNING_DAYS', 'seq' => 3, 'num1' => 30, 'note1' => 'Days before expiry a batch surfaces as "expiring soon"'],
            // §3N: how long an unfulfilled reservation holds stock before the auto-release
            // sweep frees it — a caller can override per-reservation via `expires_at`.
            ['const_group' => 'INVENTORY', 'group_code' => 'RESERVATION_EXPIRY_HOURS', 'seq' => 4, 'num1' => 24, 'note1' => 'Hours an unfulfilled reservation holds stock before auto-release'],
            // §3G: variance thresholds VarianceService::classify() applies to every KPI (and,
            // once §3B/§3H ship, Budget/Forecast) evaluation — customization ladder rung 1,
            // never hardcoded, since a tenant may want tighter or looser bands than the default.
            ['const_group' => 'PERFORMANCE', 'group_code' => 'VARIANCE_WARNING_THRESHOLD_PCT', 'seq' => 1, 'num1' => 5, 'note1' => 'Shortfall % beyond which a KPI flips from on-track to warning'],
            ['const_group' => 'PERFORMANCE', 'group_code' => 'VARIANCE_BREACH_THRESHOLD_PCT', 'seq' => 2, 'num1' => 15, 'note1' => 'Shortfall % beyond which a KPI flips from warning to breach'],
            // ACCOUNTING §3M due-date rules — tenant-editable per CLAUDE.md §2 customization
            // ladder rung 1 (constants), never hardcoded, since regulation can change.
            ['const_group' => 'ACCOUNTING_TAX', 'group_code' => 'PPN_DUE_DAY_OF_MONTH', 'seq' => 1, 'num1' => 0, 'note1' => 'SPT Masa PPN due date: day of the following month (0 = last day)'],
            ['const_group' => 'ACCOUNTING_TAX', 'group_code' => 'PPH_DUE_DAY_OF_MONTH', 'seq' => 2, 'num1' => 10, 'note1' => 'PPh withholding remittance due date: day of the following month'],
            ['const_group' => 'THEME', 'group_code' => 'ACTIVE_THEME', 'seq' => 1, 'str1' => 'classic-navy', 'note1' => 'Active UI theme key for tenant'],
            ['const_group' => 'STATUS', 'group_code' => 'ACTIVE', 'seq' => 1, 'str1' => 'A', 'str2' => 'Active'],
            ['const_group' => 'STATUS', 'group_code' => 'INACTIVE', 'seq' => 2, 'str1' => 'I', 'str2' => 'Inactive'],
            ['const_group' => 'TRUSTEE', 'group_code' => 'CRUD', 'seq' => 1, 'str1' => 'CRUD', 'note1' => 'Full menu trustee'],
            ['const_group' => 'TRUSTEE', 'group_code' => 'R', 'seq' => 2, 'str1' => 'R', 'note1' => 'Read-only trustee'],
            // PP §3C — MPS grid period bucket size and horizon, customization ladder rung 1
            // (CLAUDE.md §2), never hardcoded since tenants plan on different cadences.
            ['const_group' => 'PP', 'group_code' => 'MPS_PERIOD_TYPE', 'seq' => 1, 'str1' => 'week', 'note1' => 'week | month — MPS grid period bucket size'],
            ['const_group' => 'PP', 'group_code' => 'MPS_HORIZON_PERIODS', 'seq' => 2, 'num1' => 8, 'note1' => 'Number of periods the MPS grid shows ahead'],
            // §3F — RCCP overload threshold, tenant-editable since what counts as "overloaded"
            // varies (e.g. a tenant running lean shifts may flag at 90%, not 100%).
            ['const_group' => 'PP', 'group_code' => 'CAPACITY_OVERLOAD_THRESHOLD_PCT', 'seq' => 3, 'num1' => 100, 'note1' => 'Load % beyond which an RCCP row is flagged overloaded'],
        ];

        foreach ($consts as $c) {
            ConfigConst::query()->updateOrCreate(
                [
                    'const_group' => $c['const_group'],
                    'group_code' => $c['group_code'],
                ],
                [
                    'seq' => $c['seq'],
                    'str1' => $c['str1'] ?? null,
                    'str2' => $c['str2'] ?? null,
                    'num1' => $c['num1'] ?? null,
                    'note1' => $c['note1'] ?? null,
                ],
            );
        }
    }

    /** PP_SPECS.md §3D/§5 — auto-seeded (unlike Legal's LEGAL_MATTER_LASTID) so a fresh 'full'-plan tenant can create a planned order without an extra manual Config > Serials setup step. */
    private function seedSnums(): void
    {
        ConfigSnum::query()->updateOrCreate(
            ['code' => 'PP_PLAN_LASTID'],
            [
                'last_cnt' => 0,
                'wrap_low' => 1,
                'wrap_high' => 999999,
                'step_cnt' => 1,
                'descr' => 'PP planned order running number',
                'status_code' => 'A',
            ],
        );
    }
}
