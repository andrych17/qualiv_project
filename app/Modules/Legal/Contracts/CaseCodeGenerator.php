<?php

namespace App\Modules\Legal\Contracts;

interface CaseCodeGenerator
{
    /** Next case code for this tenant (reads SYSCONFIG consts — not tenant_id). */
    public function next(): string;
}
