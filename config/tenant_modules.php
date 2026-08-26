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
            'WNE',
            'DMS',
            'ACCOUNTING',
            'HCM',
            'PAYROLL',
            'PROJECTS',
            'DESIGN_SYSTEM',
        ],
        'full' => [
            'INVENTORY',
            'LEGAL',
            'CRM',
            'SCHEDULE',
            'WNE',
            'DMS',
            'SALES',
            'PURCHASE',
            'ACCOUNTING',
            'HCM',
            'PAYROLL',
            'PROJECTS',
            'DESIGN_SYSTEM',
        ],
        'internal' => [
            'PROJECTS',
            'HCM',
            'PAYROLL',
            'ACCOUNTING',
            'CRM',
            'SCHEDULE',
            'WNE',
            'DMS',
            'INVENTORY',
            'LEGAL',
            'DESIGN_SYSTEM',
        ],
    ],
];
