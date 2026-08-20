<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\TenantModule;
use App\Services\TenantFeatureService;
use Illuminate\Validation\ValidationException;

class TenantModuleService
{
    public function __construct(
        protected ConfigAuditLogger $audit,
        protected TenantFeatureService $features,
    ) {}

    public function isActive(string $moduleCode): bool
    {
        $code = strtoupper($moduleCode);
        if (! in_array($code, TenantModule::TOGGLEABLE, true)) {
            return true;
        }

        $row = TenantModule::query()->where('module_code', $code)->first();

        // ponytail: absence of a row = active (opt-out, SYSCONFIG_SPECS.md §3A)
        return $row === null || $row->is_active;
    }

    /**
     * @return list<array{module_code: string, entitled: bool, is_active: bool, can_toggle: bool, notes: string|null, activated_at: string|null}>
     */
    public function catalog(): array
    {
        $rows = TenantModule::query()->get()->keyBy('module_code');

        return array_map(function (string $code) use ($rows) {
            /** @var TenantModule|null $row */
            $row = $rows->get($code);
            $entitled = $this->features->entitled($code);
            $active = $row === null ? true : $row->is_active;

            return [
                'module_code' => $code,
                'entitled' => $entitled,
                'is_active' => $entitled && $active,
                'can_toggle' => $entitled,
                'notes' => $row?->notes,
                'activated_at' => $row?->activated_at?->toIso8601String(),
            ];
        }, TenantModule::TOGGLEABLE);
    }

    public function toggle(string $moduleCode, bool $isActive, ?string $notes = null): TenantModule
    {
        $code = strtoupper($moduleCode);
        if (! in_array($code, TenantModule::TOGGLEABLE, true)) {
            throw ValidationException::withMessages([
                'module_code' => 'This module is not tenant-toggleable.',
            ]);
        }
        if ($isActive && ! $this->features->entitled($code)) {
            throw ValidationException::withMessages([
                'is_active' => 'Cannot enable a module this tenant is not entitled to.',
            ]);
        }

        /** @var TenantModule|null $row */
        $row = TenantModule::query()->where('module_code', $code)->first();
        $before = $row?->only(['module_code', 'is_active', 'notes', 'activated_at']);

        $attrs = [
            'module_code' => $code,
            'is_active' => $isActive,
            'notes' => $notes ?? $row?->notes,
        ];
        if ($isActive) {
            $attrs['activated_at'] = now();
            $attrs['activated_by'] = auth()->id();
        }

        $row = TenantModule::query()->updateOrCreate(['module_code' => $code], $attrs);
        $action = $isActive ? ($before ? 'updated' : 'created') : 'deactivated';
        $this->audit->log('tenant_modules', $row->id, $action, $before, $row->only(['module_code', 'is_active', 'notes', 'activated_at']));

        return $row;
    }
}
