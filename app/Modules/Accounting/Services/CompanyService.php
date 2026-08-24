<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Company;
use Illuminate\Support\Facades\DB;

/** §3K minimal master — §3B's own dependency, full switcher/combined reporting is a later build. */
class CompanyService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Company
    {
        return Company::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Company $company, array $data): Company
    {
        return DB::transaction(function () use ($company, $data) {
            $before = $company->toArray();
            $company->update($data);

            AuditLog::record([
                'company_id' => $company->id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.companies',
                'subject_id' => $company->id,
                'before_snapshot' => $before,
                'after_snapshot' => $company->toArray(),
            ]);

            return $company->refresh();
        });
    }
}
