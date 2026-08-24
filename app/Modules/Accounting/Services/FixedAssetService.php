<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AssetGroup;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\FixedAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** §3G — the asset register. Depreciation itself lives in DepreciationRunService/AssetDisposalService — this is plain CRUD plus the method/rate guards. */
class FixedAssetService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data, int $userId): FixedAsset
    {
        $this->assertMethodRatesValid($data);

        return FixedAsset::query()->create([
            ...$data,
            'uuid' => (string) Str::uuid(),
            'status' => FixedAsset::STATUS_ACTIVE,
            'created_by' => $userId,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        $this->assertNotDisposed($asset);
        $this->assertMethodRatesValid([...$asset->toArray(), ...$data]);

        return DB::transaction(function () use ($asset, $data) {
            $before = $asset->toArray();
            $asset->update($data);

            AuditLog::record([
                'company_id' => $asset->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.fa_assets',
                'subject_id' => $asset->id,
                'before_snapshot' => $before,
                'after_snapshot' => $asset->toArray(),
            ]);

            return $asset->refresh();
        });
    }

    public function delete(FixedAsset $asset): void
    {
        $this->assertNotDisposed($asset);

        if ($asset->commercialSchedule()->exists() || $asset->fiscalSchedule()->exists()) {
            throw ValidationException::withMessages(['asset' => 'This asset already has depreciation history and cannot be deleted — dispose it instead.']);
        }

        DB::transaction(function () use ($asset) {
            AuditLog::record([
                'company_id' => $asset->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.fa_assets',
                'subject_id' => $asset->id,
                'before_snapshot' => $asset->toArray(),
            ]);

            $asset->delete();
        });
    }

    private function assertNotDisposed(FixedAsset $asset): void
    {
        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            throw ValidationException::withMessages(['asset' => 'A disposed asset cannot be edited or deleted.']);
        }
    }

    /**
     * commercial_declining_rate is a business choice (not regulated), so it's required
     * explicitly rather than derived from a convention when the method calls for it.
     * fiscal_method on a building-group asset is locked to straight-line, same rule
     * AssetGroupService enforces on the group's own declining rate.
     */
    private function assertMethodRatesValid(array $data): void
    {
        if (($data['commercial_method'] ?? null) === FixedAsset::METHOD_DECLINING_BALANCE && empty($data['commercial_declining_rate'])) {
            throw ValidationException::withMessages(['commercial_declining_rate' => 'Required when the commercial method is declining-balance.']);
        }

        $group = AssetGroup::query()->find($data['asset_group_id'] ?? null);
        if ($group && $group->is_building && ($data['fiscal_method'] ?? null) === FixedAsset::METHOD_DECLINING_BALANCE) {
            throw ValidationException::withMessages(['fiscal_method' => 'This asset group is a building — fiscal depreciation must be straight-line.']);
        }
    }
}
