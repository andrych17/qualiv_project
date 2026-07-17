<?php

namespace App\Modules\Legal\Services;

use App\Modules\Config\Models\ConfigConst;
use App\Modules\Legal\Contracts\CaseCodeGenerator;
use App\Modules\Legal\Models\LegalCase;

/**
 * LEGAL.CASE_PREFIX + sequential suffix. Firm A vs B differ via seeded const, not if(tenant).
 */
class PrefixedCaseCodeGenerator implements CaseCodeGenerator
{
    public function next(): string
    {
        $prefix = ConfigConst::query()
            ->where('const_group', 'LEGAL')
            ->where('group_code', 'CASE_PREFIX')
            ->value('str1') ?: 'CASE';

        $prefix = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $prefix) ?: 'CASE';

        $n = LegalCase::query()->count() + 1;

        do {
            $code = sprintf('%s-%03d', $prefix, $n);
            $n++;
        } while (LegalCase::query()->where('code', $code)->exists());

        return $code;
    }
}
