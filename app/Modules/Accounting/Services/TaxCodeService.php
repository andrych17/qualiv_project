<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Support\Facades\DB;

/** §3M PPN tax codes — plain CRUD. */
class TaxCodeService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): TaxCode
    {
        return TaxCode::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(TaxCode $taxCode, array $data): TaxCode
    {
        return DB::transaction(function () use ($taxCode, $data) {
            $before = $taxCode->toArray();
            $taxCode->update($data);

            AuditLog::record([
                'company_id' => $taxCode->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.tax_codes',
                'subject_id' => $taxCode->id,
                'before_snapshot' => $before,
                'after_snapshot' => $taxCode->toArray(),
            ]);

            return $taxCode->refresh();
        });
    }

    public function delete(TaxCode $taxCode): void
    {
        DB::transaction(function () use ($taxCode) {
            AuditLog::record([
                'company_id' => $taxCode->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.tax_codes',
                'subject_id' => $taxCode->id,
                'before_snapshot' => $taxCode->toArray(),
            ]);

            $taxCode->delete();
        });
    }
}
