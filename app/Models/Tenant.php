<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'plan',
        ];
    }

    /**
     * Mode B uses stable string ids (001 → tenant_001).
     * Without an id generator, stancl's GeneratesIds treats keys as incrementing ints
     * and would cast "001" → 1.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public function displayName(): string
    {
        return $this->name ?: 'Tenant '.$this->getTenantKey();
    }
}
