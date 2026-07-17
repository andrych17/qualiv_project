<?php

namespace App\Modules\Legal\Services;

use App\Modules\Config\Models\ConfigConst;
use App\Modules\Config\Services\ConfigSnumService;
use App\Modules\Legal\Contracts\CaseCodeGenerator;
use App\Modules\Legal\Models\LegalCase;

/**
 * LEGAL.CASE_PREFIX + config_snums LEGAL_CASE_LASTID (netapp1-style).
 * Firm A vs B differ via seeded const/snum — not if(tenant).
 */
class PrefixedCaseCodeGenerator implements CaseCodeGenerator
{
    public const SNUM_CODE = 'LEGAL_CASE_LASTID';

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
        while (LegalCase::query()->where('code', $code)->exists()) {
            $n = $this->serials->next(self::SNUM_CODE);
            $code = sprintf('%s-%03d', $prefix, $n);
        }

        return $code;
    }
}
