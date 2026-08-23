<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Company;

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
        $company->update($data);

        return $company->refresh();
    }
}
