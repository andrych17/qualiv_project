<?php

namespace App\Modules\Legal\Contracts;

interface MatterCodeGenerator
{
    /** Next matter code for this tenant (reads SYSCONFIG consts — not tenant_id). */
    public function next(): string;
}
