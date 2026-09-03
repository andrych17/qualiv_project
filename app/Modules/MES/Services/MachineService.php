<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\Machine;

/** MES_SPECS.md §3D — flat CRUD for a `mes_machines` row. */
class MachineService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Machine
    {
        return Machine::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Machine $machine, array $data): Machine
    {
        $machine->update($this->attributes($data));

        return $machine->refresh();
    }

    public function delete(Machine $machine): void
    {
        $machine->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'work_center_id' => $data['work_center_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'status' => $data['status'],
        ];
    }
}
