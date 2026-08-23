<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\WithholdingType;

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
        $withholdingType->update($data);

        return $withholdingType->refresh();
    }

    public function delete(WithholdingType $withholdingType): void
    {
        $withholdingType->delete();
    }
}
