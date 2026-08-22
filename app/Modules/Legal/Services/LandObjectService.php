<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\LandObject;

class LandObjectService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): LandObject
    {
        return LandObject::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(LandObject $landObject, array $data): LandObject
    {
        $landObject->update($data);

        return $landObject->refresh();
    }

    public function delete(LandObject $landObject): void
    {
        $landObject->delete();
    }
}
