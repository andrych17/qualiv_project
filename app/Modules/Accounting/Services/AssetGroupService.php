<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AssetGroup;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3G — Indonesian fiscal tax classification, tenant-editable (never hardcoded — see
 * migration docblock). seedStarterGroups() ships current PMK-regulated defaults per
 * company, same "seed once, then it's just data" shape as AccountService::seedStarterCoa().
 */
class AssetGroupService
{
    /** @var list<array{code:string, name:string, building:bool, life:int, sl:float, db:?float}> */
    private const STARTER_GROUPS = [
        ['code' => 'KELOMPOK_1', 'name' => 'Kelompok 1 (bukan bangunan)', 'building' => false, 'life' => 48, 'sl' => 0.2500, 'db' => 0.5000],
        ['code' => 'KELOMPOK_2', 'name' => 'Kelompok 2 (bukan bangunan)', 'building' => false, 'life' => 96, 'sl' => 0.1250, 'db' => 0.2500],
        ['code' => 'KELOMPOK_3', 'name' => 'Kelompok 3 (bukan bangunan)', 'building' => false, 'life' => 192, 'sl' => 0.0625, 'db' => 0.1250],
        ['code' => 'KELOMPOK_4', 'name' => 'Kelompok 4 (bukan bangunan)', 'building' => false, 'life' => 240, 'sl' => 0.0500, 'db' => 0.1000],
        ['code' => 'BANGUNAN_PERMANEN', 'name' => 'Bangunan Permanen', 'building' => true, 'life' => 240, 'sl' => 0.0500, 'db' => null],
        ['code' => 'BANGUNAN_NON_PERMANEN', 'name' => 'Bangunan Tidak Permanen', 'building' => true, 'life' => 120, 'sl' => 0.1000, 'db' => null],
    ];

    /** No-op if the company already has any groups — never overwrites a company that's already been set up. */
    public function seedStarterGroups(Company $company): void
    {
        if (AssetGroup::query()->where('company_id', $company->id)->exists()) {
            throw ValidationException::withMessages(['asset_group' => 'This company already has asset groups — the starter set is only for a fresh company.']);
        }

        foreach (self::STARTER_GROUPS as $row) {
            AssetGroup::query()->create([
                'company_id' => $company->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'is_building' => $row['building'],
                'fiscal_useful_life_months' => $row['life'],
                'fiscal_straight_line_rate' => $row['sl'],
                'fiscal_declining_rate' => $row['db'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): AssetGroup
    {
        $this->assertDecliningRateForBuilding($data);

        return AssetGroup::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(AssetGroup $group, array $data): AssetGroup
    {
        $this->assertDecliningRateForBuilding([...$group->toArray(), ...$data]);

        return DB::transaction(function () use ($group, $data) {
            $before = $group->toArray();
            $group->update($data);

            AuditLog::record([
                'company_id' => $group->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.fa_asset_groups',
                'subject_id' => $group->id,
                'before_snapshot' => $before,
                'after_snapshot' => $group->toArray(),
            ]);

            return $group->refresh();
        });
    }

    public function delete(AssetGroup $group): void
    {
        if ($group->assets()->exists()) {
            throw ValidationException::withMessages(['asset_group' => 'This group has assets assigned — reassign or remove them first.']);
        }

        DB::transaction(function () use ($group) {
            AuditLog::record([
                'company_id' => $group->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.fa_asset_groups',
                'subject_id' => $group->id,
                'before_snapshot' => $group->toArray(),
            ]);

            $group->delete();
        });
    }

    /** A building group has no valid declining-balance fiscal election (§3G / Indonesian tax law) — enforced here, not just as a UI hint. */
    private function assertDecliningRateForBuilding(array $data): void
    {
        if (($data['is_building'] ?? false) && ! empty($data['fiscal_declining_rate'])) {
            throw ValidationException::withMessages(['fiscal_declining_rate' => 'Building groups may only use the straight-line fiscal method — leave the declining rate empty.']);
        }
    }
}
