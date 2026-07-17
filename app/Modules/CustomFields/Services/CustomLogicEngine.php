<?php

namespace App\Modules\CustomFields\Services;

use App\Modules\Config\Models\ConfigConst;

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
        if ($entityType === 'legal_case') {
            return $this->legalCaseRules($data, $customValues);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $customValues
     * @return array<string, mixed>
     */
    private function legalCaseRules(array $data, array $customValues): array
    {
        // LEGAL.URGENT_SETS_PENDING=1 → priority=urgent forces status pending
        if ($this->constEnabled('LEGAL', 'URGENT_SETS_PENDING')
            && ($customValues['priority'] ?? null) === 'urgent'
            && ($data['status'] ?? null) === 'open') {
            $data['status'] = 'pending';
        }

        return $data;
    }

    private function constEnabled(string $group, string $code): bool
    {
        $row = ConfigConst::query()
            ->where('const_group', $group)
            ->where('group_code', $code)
            ->first();

        if (! $row) {
            return false;
        }

        if ($row->num1 !== null) {
            return (float) $row->num1 > 0;
        }

        return in_array(strtolower((string) $row->str1), ['1', 'true', 'yes', 'y'], true);
    }
}
