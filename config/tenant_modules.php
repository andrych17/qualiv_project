<?php

/**
 * Which modules a tenant plan unlocks (packaging / vertical bundles).
 * Codes align with SYSCONFIG menu codes / module folders.
 */
return [
    'plans' => [
        'starter' => [
            'INVENTORY',
        ],
        'legal' => [
            'INVENTORY',
            'LEGAL',
            'CRM',
            'SCHEDULE',
            'NOTIFICATIONS',
            'WORKFLOW',
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
        ],
    ],
];
