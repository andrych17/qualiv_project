<?php

namespace App\Modules\Config\Services;

use App\Modules\Config\Models\ConfigConst;

class ConfigConstService
{
    public function create(array $data): ConfigConst
    {
        return ConfigConst::query()->create([
            'const_group' => $data['const_group'],
            'group_code' => $data['group_code'],
            'seq' => (int) ($data['seq'] ?? 0),
            'str1' => $data['str1'] ?? null,
            'str2' => $data['str2'] ?? null,
            'num1' => $data['num1'] ?? null,
            'num2' => $data['num2'] ?? null,
            'note1' => $data['note1'] ?? null,
        ]);
    }

    public function update(ConfigConst $const, array $data): ConfigConst
    {
        $const->update([
            'const_group' => $data['const_group'],
            'group_code' => $data['group_code'],
            'seq' => (int) ($data['seq'] ?? $const->seq),
            'str1' => $data['str1'] ?? null,
            'str2' => $data['str2'] ?? null,
            'num1' => $data['num1'] ?? null,
            'num2' => $data['num2'] ?? null,
            'note1' => $data['note1'] ?? null,
        ]);

        return $const->refresh();
    }

    public function delete(ConfigConst $const): void
    {
        $const->delete();
    }

    /** Single-field edit for DataTable's InlineEditor — caller whitelists which fields reach here. */
    public function quickUpdate(ConfigConst $const, string $field, mixed $value): ConfigConst
    {
        $const->update([$field => $value]);

        return $const->refresh();
    }
}
