<?php

/**
 * Which modules a tenant plan unlocks (packaging / vertical bundles).
 * Codes align with SYSCONFIG menu codes / module folders.
 */
return [
    'plans' => [
        'starter' => [
            'INVENTORY',
            'DESIGN_SYSTEM',
        ],
        'legal' => [
            'INVENTORY',
            'LEGAL',
            'CRM',
            'SCHEDULE',
            'NOTIFICATIONS',
            'WORKFLOW',
            'DESIGN_SYSTEM',
        ],
        'full' => [
            'INVENTORY',
            'LEGAL',
            'CRM',
            'SCHEDULE',
            'NOTIFICATIONS',
            'WORKFLOW',
            'SALES',
            'PROCUREMENT',
            'DELIVERY',
            'ASSET',
            'ACCOUNTING',
            'HCM',
            'PAYROLL',
            'DESIGN_SYSTEM',
        ],
        // Not sold on any pricing tier — set only on Nusaevo's own internal tenant's
        // `tenants.plan` (central DB) so PROJECTS stays invisible/inaccessible to
        // customer tenants. See CLAUDE.md §2 customization ladder rung 5.
        // Deliberately just PROJECTS — this tenant is the internal Jira board only,
        // not a demo of the whole ERP. CONFIG_*/DASHBOARD/DESIGN_SYSTEM menus always
        // show regardless of plan (see ConfigService::menusForUser()), no need to list them.
        'internal' => [
            'PROJECTS',
        ],
    ],
];
