<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\WithholdingType;
use Illuminate\Support\Facades\DB;

/** §3M PPh withholding types — plain CRUD. */
class WithholdingTypeService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): WithholdingType
    {
        return WithholdingType::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(WithholdingType $withholdingType, array $data): WithholdingType
    {
        return DB::transaction(function () use ($withholdingType, $data) {
            $before = $withholdingType->toArray();
            $withholdingType->update($data);

            AuditLog::record([
                'company_id' => $withholdingType->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.withholding_types',
                'subject_id' => $withholdingType->id,
                'before_snapshot' => $before,
                'after_snapshot' => $withholdingType->toArray(),
            ]);

            return $withholdingType->refresh();
        });
    }

    public function delete(WithholdingType $withholdingType): void
    {
        DB::transaction(function () use ($withholdingType) {
            AuditLog::record([
                'company_id' => $withholdingType->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.withholding_types',
                'subject_id' => $withholdingType->id,
                'before_snapshot' => $withholdingType->toArray(),
            ]);

            $withholdingType->delete();
        });
    }
}
