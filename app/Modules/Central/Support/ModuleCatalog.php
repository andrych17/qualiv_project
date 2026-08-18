<?php

namespace App\Modules\Central\Support;

use Illuminate\Support\Facades\Config;

/** Candidate module codes for plan/addon pickers — sourced from config/tenant_modules.php's existing enumeration. */
class ModuleCatalog
{
    /** @return list<string> */
    public static function codes(): array
    {
        $plans = Config::get('tenant_modules.plans', []);

        return collect($plans)
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
