<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\ConfigConst;

class ConfigConstService
{
    public function __construct(
        protected ConfigAuditLogger $audit,
        protected ConfigService $config,
    ) {}

    public function create(array $data): ConfigConst
    {
        $row = ConfigConst::query()->create($this->attrs($data));
        $this->audit->log('config_consts', $row->id, 'created', null, $this->snapshot($row));
        $this->config->bumpCache($row->const_group);

        return $row;
    }

    public function update(ConfigConst $const, array $data): ConfigConst
    {
        $before = $this->snapshot($const);
        $const->update($this->attrs($data, $const));
        $const->refresh();
        $this->audit->log('config_consts', $const->id, 'updated', $before, $this->snapshot($const));
        $this->config->bumpCache($const->const_group);

        return $const;
    }

    public function delete(ConfigConst $const): void
    {
        $before = $this->snapshot($const);
        $const->update(['is_active' => false]);
        $this->audit->log('config_consts', $const->id, 'deactivated', $before, $this->snapshot($const->refresh()));
        $this->config->bumpCache($const->const_group);
    }

    /** Single-field edit for DataTable's InlineEditor — caller whitelists which fields reach here. */
    public function quickUpdate(ConfigConst $const, string $field, mixed $value): ConfigConst
    {
        $before = $this->snapshot($const);
        $const->update([$field => $value]);
        $const->refresh();
        $this->audit->log('config_consts', $const->id, 'updated', $before, $this->snapshot($const));
        $this->config->bumpCache($const->const_group);

        return $const;
    }

    /** @param  array<string, mixed>  $data */
    private function attrs(array $data, ?ConfigConst $existing = null): array
    {
        return [
            'appl_id' => $this->blankToNull($data['appl_id'] ?? null),
            'group_id' => $data['group_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'const_group' => $data['const_group'],
            'group_code' => $data['group_code'],
            'value' => $this->blankToNull($data['value'] ?? null),
            'value_type' => $data['value_type'] ?? $existing?->value_type ?? 'text',
            'seq' => (int) ($data['seq'] ?? $existing?->seq ?? 0),
            'str1' => $this->blankToNull($data['str1'] ?? null),
            'str2' => $this->blankToNull($data['str2'] ?? null),
            'num1' => $data['num1'] ?? null,
            'num2' => $data['num2'] ?? null,
            'note1' => $this->blankToNull($data['note1'] ?? null),
            'effective_date' => $data['effective_date'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(ConfigConst $const): array
    {
        return $const->only([
            'appl_id', 'group_id', 'user_id', 'const_group', 'group_code', 'value', 'value_type',
            'str1', 'str2', 'num1', 'num2', 'note1', 'seq', 'effective_date', 'is_active',
        ]);
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === '' || $value === false) {
            return null;
        }

        return $value;
    }
}
