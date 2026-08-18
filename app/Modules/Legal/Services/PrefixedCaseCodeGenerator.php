<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Contracts\CaseCodeGenerator;
use App\Modules\Legal\Models\LegalCase;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Services\ConfigSnumService;
use RuntimeException;

/**
 * LEGAL.CASE_PREFIX + config_snums LEGAL_CASE_LASTID (netapp1-style).
 * Firm A vs B differ via seeded const/snum — not if(tenant).
 */
class PrefixedCaseCodeGenerator implements CaseCodeGenerator
{
    public const SNUM_CODE = 'LEGAL_CASE_LASTID';

    private const MAX_COLLISION_RETRIES = 50;

    public function __construct(
        protected ConfigSnumService $serials,
    ) {}

    public function next(): string
    {
        $prefix = ConfigConst::query()
            ->where('const_group', 'LEGAL')
            ->where('group_code', 'CASE_PREFIX')
            ->value('str1') ?: 'CASE';

        $prefix = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $prefix) ?: 'CASE';

        $n = $this->serials->next(self::SNUM_CODE);

        $code = sprintf('%s-%03d', $prefix, $n);

        // Collision guard if someone manually set last_cnt behind existing codes
        $attempts = 0;
        while (LegalCase::query()->where('code', $code)->exists()) {
            if (++$attempts > self::MAX_COLLISION_RETRIES) {
                throw new RuntimeException("Could not allocate a unique case code for prefix '{$prefix}' after ".self::MAX_COLLISION_RETRIES.' attempts — serial range likely exhausted or corrupted.');
            }
            $n = $this->serials->next(self::SNUM_CODE);
            $code = sprintf('%s-%03d', $prefix, $n);
        }

        return $code;
    }
}
