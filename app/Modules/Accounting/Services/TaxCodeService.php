<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\TaxCode;

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
        $taxCode->update($data);

        return $taxCode->refresh();
    }

    public function delete(TaxCode $taxCode): void
    {
        $taxCode->delete();
    }
}
