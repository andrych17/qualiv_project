<?php

namespace App\Modules\CustomFields\Services;

use App\Modules\SysConfig\Services\ConfigService;

/**
 * Config-driven hooks — never branch on tenant_id.
 * ponytail: flat const checks. Ceiling: pluggable Rule classes per entity when rules grow.
 */
class CustomLogicEngine
{
    /**
     * Mutate core entity payload before persist.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $customValues
     * @return array<string, mixed>
     */
    public function beforeSave(string $entityType, array $data, array $customValues): array
    {
        if ($entityType === 'legal_matter') {
            return $this->legalMatterRules($data, $customValues);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $customValues
     * @return array<string, mixed>
     */
    private function legalMatterRules(array $data, array $customValues): array
    {
        // LEGAL.URGENT_SETS_PENDING=1 → priority=urgent forces status on_hold
        if ($this->constEnabled('LEGAL', 'URGENT_SETS_PENDING')
            && ($customValues['priority'] ?? null) === 'urgent'
            && ($data['status'] ?? null) === 'open') {
            $data['status'] = 'on_hold';
        }

        return $data;
    }

    private function constEnabled(string $group, string $code): bool
    {
        $value = app(ConfigService::class)->get($group, $code, 'LEGAL');
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'y'], true);
    }
}
