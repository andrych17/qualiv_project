<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Contracts\MatterCodeGenerator;
use App\Modules\Legal\Models\Matter;
use App\Modules\SysConfig\Services\ConfigService;
use App\Modules\SysConfig\Services\ConfigSnumService;
use RuntimeException;

/**
 * LEGAL.MATTER_PREFIX + config_snums LEGAL_MATTER_LASTID (netapp1-style).
 * Firm A vs B differ via seeded const/snum — not if(tenant).
 */
class PrefixedMatterCodeGenerator implements MatterCodeGenerator
{
    public const SNUM_CODE = 'LEGAL_MATTER_LASTID';

    private const MAX_COLLISION_RETRIES = 50;

    public function __construct(
        protected ConfigSnumService $serials,
    ) {}

    public function next(): string
    {
        $prefix = (string) (app(ConfigService::class)->get('LEGAL', 'MATTER_PREFIX', 'LEGAL') ?: 'MATTER');

        $prefix = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $prefix) ?: 'MATTER';

        $n = $this->serials->next(self::SNUM_CODE);

        $code = sprintf('%s-%03d', $prefix, $n);

        // Collision guard if someone manually set last_cnt behind existing codes
        $attempts = 0;
        while (Matter::query()->where('code', $code)->exists()) {
            if (++$attempts > self::MAX_COLLISION_RETRIES) {
                throw new RuntimeException("Could not allocate a unique matter code for prefix '{$prefix}' after ".self::MAX_COLLISION_RETRIES.' attempts — serial range likely exhausted or corrupted.');
            }
            $n = $this->serials->next(self::SNUM_CODE);
            $code = sprintf('%s-%03d', $prefix, $n);
        }

        return $code;
    }
}
