<?php

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Database\Migrations\Migration;

// HCM_DEPARTMENTS/HCM_BRANCHES were seeded pointing at /hcm/departments and /hcm/branches
// (2026_08_29_113000) — URLs with no route at all, since HCM.org_units is a single generic
// tree (HCM_SPECS.md §3C: "tree (department/division/branch)") with no per-type screen. The
// preceding migration (2026_08_31_110000) added `unit_type` to that table so the one real
// Org Units screen (OrgUnitController -> /hcm/org-units) can be filtered per type; these two
// menu items now point at that page pre-filtered via query string, same "sidebar link
// pre-filters the real page" pattern DMS's flag filter and Accounting's Trial Balance drill-in
// already use.
//
// HCM_DESIGNATIONS was seeded pointing at /hcm/designations, also a URL with no route —
// OrgStructureService already had full job-catalog CRUD (createJob/updateJob/deleteJob/
// paginateJobs) with no controller/route ever built against it. JobController now fills that
// gap at /hcm/jobs (HCM.jobs — "job title/catalog (master)" per §3C), so this link is fixed
// to the real path rather than one invented to match the stale seed.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    private const FIXES = [
        'HCM_DEPARTMENTS' => '/hcm/org-units?unit_type=department',
        'HCM_DESIGNATIONS' => '/hcm/jobs',
        'HCM_BRANCHES' => '/hcm/org-units?unit_type=branch',
    ];

    public function up(): void
    {
        foreach (self::FIXES as $code => $link) {
            ConfigMenu::query()
                ->where(['app_code' => self::APP, 'code' => $code])
                ->update(['menu_link' => $link]);
        }
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
