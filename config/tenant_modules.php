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
    ],
];
