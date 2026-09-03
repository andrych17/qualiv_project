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
            'SALES',
            'PURCHASE',
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
            'PERFORMANCE',
            'PROJECTS',
            'PP',
            'MES',
            'POS',
            'DESIGN_SYSTEM',
        ],
        'internal' => [
            'PROJECTS',
            'HCM',
            'PAYROLL',
            'PERFORMANCE',
            'ACCOUNTING',
            'CRM',
            'SCHEDULE',
            'WNE',
            'DMS',
            'INVENTORY',
            'LEGAL',
            'SALES',
            'PURCHASE',
            'PP',
            'MES',
            'POS',
            'DESIGN_SYSTEM',
        ],
    ],
];
