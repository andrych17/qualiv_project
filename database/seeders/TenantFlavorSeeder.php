<?php

namespace Database\Seeders;

use App\Modules\CRM\Models\Address;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Industry;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\CustomFields\Models\FieldDef;
use App\Modules\CustomFields\Models\FieldValue;
use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryItem;
use App\Modules\Legal\Models\LegalCase;
use App\Modules\Projects\Models\Project;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Database\Seeder;

/**
 * Per-tenant mockup so Firm A vs B look different after switch.
 * Expects config('demo.tenant') = ['id','name','plan',...flavor fields].
 */
class TenantFlavorSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = config('demo.tenant');
        if (! is_array($tenant) || empty($tenant['id'])) {
            $tenant = ['id' => '001', 'name' => 'Nusaevo'];
        }

        $flavor = $this->flavors()[$tenant['id']] ?? $this->flavors()['001'];

        $this->seedConsts($flavor);
        $this->seedGroupLabels($flavor);
        $this->seedDashboardBlurb($flavor);
        $this->seedInventory($flavor);
        $this->seedCustomFields($flavor);
        $this->seedLegalCases($flavor);
        $this->seedSnums($flavor);
        $this->seedProjects($flavor);
        $this->seedCrmLookups();
        $this->seedCrmPartners($flavor);
        $this->seedCrmLeads($flavor);
    }

    /** @return array<string, array<string, mixed>> */
    private function flavors(): array
    {
        return [
            '001' => [
                'app_name' => 'Nusaevo (Jakarta)',
                'tz' => 'Asia/Jakarta',
                'city' => 'Jakarta',
                'low_stock' => 10,
                'admin_descr' => 'Nusaevo administrators',
                'staff_descr' => 'Nusaevo operations staff',
                'inventory_header' => 'Warehouse A',
                'categories' => [
                    ['code' => 'RAW', 'name' => 'Raw Material'],
                    ['code' => 'FG', 'name' => 'Finished Goods'],
                    ['code' => 'SP', 'name' => 'Sparepart'],
                ],
                'items' => [
                    ['code' => 'A-RAW-001', 'name' => 'Steel Plate 2mm', 'category_code' => 'RAW', 'stock' => 120, 'minimum_stock' => 30, 'unit' => 'pcs'],
                    ['code' => 'A-RAW-002', 'name' => 'Aluminium Sheet', 'category_code' => 'RAW', 'stock' => 75, 'minimum_stock' => 20, 'unit' => 'pcs'],
                    ['code' => 'A-FG-001', 'name' => 'Product Alpha', 'category_code' => 'FG', 'stock' => 45, 'minimum_stock' => 10, 'unit' => 'box'],
                    ['code' => 'A-SP-001', 'name' => 'Bearing 6202', 'category_code' => 'SP', 'stock' => 8, 'minimum_stock' => 15, 'unit' => 'pcs'],
                ],
                'extra_factory' => 20,
                'case_prefix' => 'A',
                'urgent_sets_pending' => 1,
                'snums' => [
                    ['code' => 'LEGAL_CASE_LASTID', 'last_cnt' => 3, 'wrap_low' => 1, 'wrap_high' => 999999, 'step_cnt' => 1, 'descr' => 'Legal case running number'],
                    ['code' => 'INVENTORY_ITEM_LASTID', 'last_cnt' => 4, 'wrap_low' => 1, 'wrap_high' => 999999, 'step_cnt' => 1, 'descr' => 'Inventory item running number'],
                ],
                'field_defs' => [
                    ['code' => 'court_register', 'label' => 'Court register No.', 'field_type' => 'text', 'is_required' => true, 'seq' => 1],
                    ['code' => 'hearing_date', 'label' => 'Next hearing', 'field_type' => 'date', 'is_required' => false, 'seq' => 2],
                    [
                        'code' => 'priority',
                        'label' => 'Priority',
                        'field_type' => 'select',
                        'is_required' => true,
                        'seq' => 3,
                        'options' => [
                            ['label' => 'Normal', 'value' => 'normal'],
                            ['label' => 'Urgent', 'value' => 'urgent'],
                        ],
                    ],
                ],
                'cases' => [
                    [
                        'code' => 'A-CASE-001',
                        'title' => 'PT Maju v. Supplier Dispute',
                        'status' => 'open',
                        'notes' => 'Jakarta commercial claim',
                        'custom' => ['court_register' => 'PN.JKT.001/2026', 'hearing_date' => '2026-08-12', 'priority' => 'normal'],
                    ],
                    [
                        'code' => 'A-CASE-002',
                        'title' => 'Employment — Budi',
                        'status' => 'pending',
                        'notes' => 'Severance negotiation',
                        'custom' => ['court_register' => 'PHI.JKT.88/2026', 'hearing_date' => null, 'priority' => 'urgent'],
                    ],
                    [
                        'code' => 'A-CASE-003',
                        'title' => 'IP Filing — Brand Alpha',
                        'status' => 'open',
                        'notes' => 'Trademark class 9',
                        'custom' => ['court_register' => 'DJKI-2026-441', 'hearing_date' => '2026-09-01', 'priority' => 'normal'],
                    ],
                ],
                // Internal client/project tracker — Nusaevo's own work, not tenant demo data.
                'projects' => [
                    ['code' => 'KNC', 'name' => 'Knitandcro', 'status' => 'active'],
                    ['code' => 'CT', 'name' => 'Cahaya Terang', 'status' => 'active'],
                    ['code' => 'WM', 'name' => 'Wijaya Mas', 'status' => 'active'],
                ],
            ],
            '002' => [
                'app_name' => 'NusaEvo — Firm B (Surabaya)',
                'tz' => 'Asia/Jakarta',
                'city' => 'Surabaya',
                'low_stock' => 25,
                'admin_descr' => 'Firm B full admins',
                'staff_descr' => 'Firm B warehouse crew',
                'inventory_header' => 'Warehouse B',
                'categories' => [
                    ['code' => 'RTL', 'name' => 'Retail SKU'],
                    ['code' => 'PKG', 'name' => 'Packaging'],
                    ['code' => 'OFF', 'name' => 'Office'],
                ],
                'items' => [
                    ['code' => 'B-RTL-001', 'name' => 'Kopi Bubuk 250g', 'category_code' => 'RTL', 'stock' => 500, 'minimum_stock' => 100, 'unit' => 'pcs'],
                    ['code' => 'B-RTL-002', 'name' => 'Teh Celup Box', 'category_code' => 'RTL', 'stock' => 320, 'minimum_stock' => 80, 'unit' => 'box'],
                    ['code' => 'B-PKG-001', 'name' => 'Karton Small', 'category_code' => 'PKG', 'stock' => 900, 'minimum_stock' => 200, 'unit' => 'pcs'],
                    ['code' => 'B-OFF-001', 'name' => 'Kertas A4 Rim', 'category_code' => 'OFF', 'stock' => 40, 'minimum_stock' => 15, 'unit' => 'rim'],
                    ['code' => 'B-OFF-002', 'name' => 'Tinta Printer Hitam', 'category_code' => 'OFF', 'stock' => 6, 'minimum_stock' => 10, 'unit' => 'unit'],
                ],
                'extra_factory' => 12,
                'case_prefix' => 'B',
                'urgent_sets_pending' => 0,
                'snums' => [
                    ['code' => 'LEGAL_CASE_LASTID', 'last_cnt' => 2, 'wrap_low' => 1, 'wrap_high' => 999999, 'step_cnt' => 1, 'descr' => 'Legal case running number'],
                    ['code' => 'INVENTORY_ITEM_LASTID', 'last_cnt' => 5, 'wrap_low' => 1, 'wrap_high' => 999999, 'step_cnt' => 1, 'descr' => 'Inventory item running number'],
                ],
                'field_defs' => [
                    ['code' => 'lease_object', 'label' => 'Lease object', 'field_type' => 'text', 'is_required' => true, 'seq' => 1],
                    ['code' => 'monthly_rent', 'label' => 'Monthly rent (IDR)', 'field_type' => 'number', 'is_required' => false, 'seq' => 2],
                    [
                        'code' => 'priority',
                        'label' => 'Priority',
                        'field_type' => 'select',
                        'is_required' => false,
                        'seq' => 3,
                        'options' => [
                            ['label' => 'Normal', 'value' => 'normal'],
                            ['label' => 'Urgent', 'value' => 'urgent'],
                        ],
                    ],
                ],
                'cases' => [
                    [
                        'code' => 'B-CASE-001',
                        'title' => 'Lease — Ruko Tunjungan',
                        'status' => 'open',
                        'notes' => 'Surabaya property lease',
                        'custom' => ['lease_object' => 'Ruko Tunjungan Blok A', 'monthly_rent' => '15000000', 'priority' => 'normal'],
                    ],
                    [
                        'code' => 'B-CASE-002',
                        'title' => 'Collection — CV Nusantara',
                        'status' => 'closed',
                        'notes' => 'Settled Q2',
                        'custom' => ['lease_object' => 'N/A — collection', 'monthly_rent' => null, 'priority' => 'urgent'],
                    ],
                ],
                // Firm B is the 'legal' plan tenant (CRM enabled) — demo Contacts/Companies
                // for CRM_SPECS.md §3B/§3C. Firm A runs the 'internal' plan (CRM not
                // entitled), so no CRM demo data is defined for it.
                'crm_companies' => [
                    ['name' => 'Contoh Hukum Corp', 'registration_tax_id' => '02.345.678.9-012.000', 'industry' => 'Legal Services', 'role' => 'CLIENT', 'email' => 'client@contohhukum.example'],
                    ['name' => 'Sewa Kantor Vendor', 'registration_tax_id' => '03.456.789.0-123.000', 'industry' => null, 'role' => 'VENDOR', 'email' => 'vendor@sewakantor.example'],
                ],
                'crm_contacts' => [
                    ['name' => 'Budi Santoso', 'title_position' => 'Finance Manager', 'parent_company' => 'Contoh Hukum Corp', 'mobile' => '+62 812-3456-7890'],
                ],
                'crm_leads' => [
                    ['name' => 'Siti Rahayu', 'company_name' => 'Toko Maju Bersama', 'source' => 'Website', 'stage' => 'qualified', 'estimated_value' => 25000000, 'next_action_days' => 3, 'notes' => 'Interested in a retainer package.'],
                    ['name' => 'Andi Wijaya', 'company_name' => 'CV Sinar Abadi', 'source' => 'Referral', 'stage' => 'new', 'estimated_value' => null, 'next_action_days' => null, 'notes' => null],
                    ['name' => 'Rina Kartika', 'company_name' => null, 'source' => 'Event', 'stage' => 'contacted', 'estimated_value' => 8000000, 'next_action_days' => -1, 'notes' => 'Follow up overdue — left voicemail.'],
                ],
            ],
        ];
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedConsts(array $flavor): void
    {
        $rows = [
            ['const_group' => 'APP', 'group_code' => 'NAME', 'seq' => 1, 'str1' => $flavor['app_name'], 'note1' => 'Product display name'],
            ['const_group' => 'APP', 'group_code' => 'TZ', 'seq' => 2, 'str1' => $flavor['tz'], 'note1' => 'Default timezone'],
            ['const_group' => 'APP', 'group_code' => 'CITY', 'seq' => 3, 'str1' => $flavor['city'], 'note1' => 'HQ city'],
            ['const_group' => 'INVENTORY', 'group_code' => 'LOW_STOCK', 'seq' => 1, 'num1' => $flavor['low_stock'], 'note1' => 'Default low-stock threshold'],
            ['const_group' => 'LEGAL', 'group_code' => 'CASE_PREFIX', 'seq' => 1, 'str1' => $flavor['case_prefix'], 'note1' => 'Auto case code prefix'],
            ['const_group' => 'LEGAL', 'group_code' => 'URGENT_SETS_PENDING', 'seq' => 2, 'num1' => $flavor['urgent_sets_pending'], 'note1' => '1 = urgent priority forces pending'],
        ];

        foreach ($rows as $c) {
            ConfigConst::query()->updateOrCreate(
                [
                    'const_group' => $c['const_group'],
                    'group_code' => $c['group_code'],
                ],
                [
                    'seq' => $c['seq'],
                    'str1' => $c['str1'] ?? null,
                    'num1' => $c['num1'] ?? null,
                    'note1' => $c['note1'] ?? null,
                ],
            );
        }
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedGroupLabels(array $flavor): void
    {
        ConfigGroup::query()->where('code', 'ADMIN')->update(['descr' => $flavor['admin_descr']]);
        ConfigGroup::query()->where('code', 'STAFF')->update(['descr' => $flavor['staff_descr']]);
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedDashboardBlurb(array $flavor): void
    {
        ConfigMenu::query()->where('code', 'INVENTORY')->update([
            'menu_caption' => $flavor['inventory_header'],
        ]);
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedInventory(array $flavor): void
    {
        // Replace demo stock so re-seed stays idempotent per flavor
        InventoryItem::query()->delete();
        InventoryCategory::query()->delete();

        $categoryMap = [];
        foreach ($flavor['categories'] as $cat) {
            $created = InventoryCategory::query()->create([
                'code' => $cat['code'],
                'name' => $cat['name'],
                'status' => 'active',
            ]);
            $categoryMap[$cat['code']] = $created->id;
        }

        foreach ($flavor['items'] as $item) {
            InventoryItem::query()->create([
                'inventory_category_id' => $categoryMap[$item['category_code']],
                'code' => $item['code'],
                'name' => $item['name'],
                'description' => $flavor['app_name'].' stock',
                'stock' => $item['stock'],
                'minimum_stock' => $item['minimum_stock'],
                'unit' => $item['unit'],
                'status' => 'active',
            ]);
        }

        $extra = (int) ($flavor['extra_factory'] ?? 0);
        if ($extra > 0 && InventoryCategory::query()->exists()) {
            InventoryItem::factory()->count($extra)->create();
        }
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedSnums(array $flavor): void
    {
        foreach ($flavor['snums'] ?? [] as $row) {
            ConfigSnum::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'last_cnt' => $row['last_cnt'],
                    'wrap_low' => $row['wrap_low'] ?? 1,
                    'wrap_high' => $row['wrap_high'] ?? 999999,
                    'step_cnt' => $row['step_cnt'] ?? 1,
                    'descr' => $row['descr'] ?? null,
                    'status_code' => 'A',
                ],
            );
        }
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedCustomFields(array $flavor): void
    {
        FieldValue::query()->delete();
        FieldDef::query()->delete();

        foreach ($flavor['field_defs'] ?? [] as $def) {
            FieldDef::query()->create([
                'entity_type' => 'legal_case',
                'code' => $def['code'],
                'label' => $def['label'],
                'field_type' => $def['field_type'],
                'options' => $def['options'] ?? null,
                'is_required' => $def['is_required'] ?? false,
                'seq' => $def['seq'] ?? 0,
                'status' => 'active',
            ]);
        }
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedProjects(array $flavor): void
    {
        foreach ($flavor['projects'] ?? [] as $project) {
            Project::query()->updateOrCreate(
                ['code' => $project['code']],
                [
                    'name' => $project['name'],
                    'status' => $project['status'],
                ],
            );
        }
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedLegalCases(array $flavor): void
    {
        FieldValue::query()->where('entity_type', 'legal_case')->delete();
        LegalCase::query()->delete();

        $defs = FieldDef::query()
            ->where('entity_type', 'legal_case')
            ->get()
            ->keyBy('code');

        foreach ($flavor['cases'] as $case) {
            $created = LegalCase::query()->create([
                'code' => $case['code'],
                'title' => $case['title'],
                'status' => $case['status'],
                'notes' => $case['notes'],
            ]);

            foreach ($case['custom'] ?? [] as $code => $value) {
                $def = $defs->get($code);
                if (! $def) {
                    continue;
                }
                FieldValue::query()->create([
                    'field_def_id' => $def->id,
                    'entity_type' => 'legal_case',
                    'entity_id' => $created->id,
                    'value' => $value,
                ]);
            }
        }
    }

    /**
     * Base lookup/master data (§4) — same defaults regardless of flavor, since
     * role vocabulary is tenant-editable data, not a per-firm demo prop.
     */
    private function seedCrmLookups(): void
    {
        foreach (['Legal Services', 'Real Estate', 'Manufacturing'] as $name) {
            Industry::query()->updateOrCreate(['name' => $name]);
        }

        foreach ([
            ['code' => 'CLIENT', 'name' => 'Client'],
            ['code' => 'VENDOR', 'name' => 'Vendor'],
            ['code' => 'REFERRAL_PARTNER', 'name' => 'Referral Partner'],
        ] as $rt) {
            PartnerRoleType::query()->updateOrCreate(['code' => $rt['code']], ['name' => $rt['name']]);
        }

        foreach (['Referral', 'Website', 'Event', 'Cold Outreach'] as $name) {
            LeadSource::query()->updateOrCreate(['name' => $name]);
        }
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedCrmPartners(array $flavor): void
    {
        if (! isset($flavor['crm_companies'])) {
            return;
        }

        // Re-seed clean per flavor run, same idempotency approach as seedInventory().
        PartnerRole::query()->delete();
        ContactPoint::query()->delete();
        Address::query()->delete();
        Partner::query()->delete();

        $roleTypes = PartnerRoleType::query()->pluck('id', 'code');
        $industries = Industry::query()->pluck('id', 'name');

        $companies = [];
        foreach ($flavor['crm_companies'] as $co) {
            $partner = Partner::query()->create([
                'type' => Partner::TYPE_ORGANIZATION,
                'name' => $co['name'],
                'registration_tax_id' => $co['registration_tax_id'],
                'industry_id' => $co['industry'] ? ($industries[$co['industry']] ?? null) : null,
                'source' => 'manual',
            ]);
            $companies[$co['name']] = $partner;

            ContactPoint::query()->create([
                'partner_id' => $partner->id,
                'type' => 'email',
                'value' => $co['email'],
                'is_primary' => true,
            ]);

            if (isset($roleTypes[$co['role']])) {
                PartnerRole::query()->create([
                    'partner_id' => $partner->id,
                    'role_type_id' => $roleTypes[$co['role']],
                    'assigned_at' => now(),
                ]);
            }
        }

        foreach ($flavor['crm_contacts'] ?? [] as $person) {
            $partner = Partner::query()->create([
                'type' => Partner::TYPE_INDIVIDUAL,
                'name' => $person['name'],
                'title_position' => $person['title_position'],
                'parent_partner_id' => $companies[$person['parent_company']]->id ?? null,
                'source' => 'manual',
            ]);

            if (! empty($person['mobile'])) {
                ContactPoint::query()->create([
                    'partner_id' => $partner->id,
                    'type' => 'mobile',
                    'value' => $person['mobile'],
                    'is_primary' => true,
                ]);
            }
        }
    }

    /** @param  array<string, mixed>  $flavor */
    private function seedCrmLeads(array $flavor): void
    {
        if (! isset($flavor['crm_leads'])) {
            return;
        }

        Lead::query()->delete();

        $sources = LeadSource::query()->pluck('id', 'name');

        foreach ($flavor['crm_leads'] as $lead) {
            Lead::query()->create([
                'name' => $lead['name'],
                'company_name' => $lead['company_name'],
                'source_id' => $sources[$lead['source']] ?? null,
                'stage' => $lead['stage'],
                'estimated_value' => $lead['estimated_value'],
                'next_action_at' => $lead['next_action_days'] !== null ? now()->addDays($lead['next_action_days']) : null,
                'notes' => $lead['notes'],
            ]);
        }
    }
}
