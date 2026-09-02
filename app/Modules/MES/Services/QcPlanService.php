<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\QcCharacteristic;
use App\Modules\MES\Models\QcInspectionPlan;
use Illuminate\Support\Facades\DB;

/** MES_SPECS.md §3L — inspection plan header + characteristics CRUD, same replace-all-lines discipline as PP's BomService. */
class QcPlanService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): QcInspectionPlan
    {
        return DB::transaction(function () use ($data) {
            $plan = QcInspectionPlan::query()->create([
                'product_id' => $data['product_id'] ?? null,
                'name' => $data['name'],
            ]);

            $this->syncCharacteristics($plan, $data['characteristics'] ?? []);

            return $plan->load('characteristics');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(QcInspectionPlan $plan, array $data): QcInspectionPlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan->update([
                'product_id' => $data['product_id'] ?? null,
                'name' => $data['name'],
            ]);

            $this->syncCharacteristics($plan, $data['characteristics'] ?? []);

            return $plan->refresh()->load('characteristics');
        });
    }

    public function delete(QcInspectionPlan $plan): void
    {
        $plan->delete();
    }

    /** @param  list<array<string, mixed>>  $characteristics */
    private function syncCharacteristics(QcInspectionPlan $plan, array $characteristics): void
    {
        $plan->characteristics()->delete();

        foreach ($characteristics as $characteristic) {
            if (empty($characteristic['characteristic_name'])) {
                continue;
            }

            QcCharacteristic::query()->create([
                'plan_id' => $plan->id,
                'characteristic_name' => $characteristic['characteristic_name'],
                'spec_type' => $characteristic['spec_type'] ?? QcCharacteristic::SPEC_NUMERIC,
                'target_value' => $characteristic['target_value'] ?? null,
                'min_value' => $characteristic['min_value'] ?? null,
                'max_value' => $characteristic['max_value'] ?? null,
                'uom_code' => $characteristic['uom_code'] ?? null,
            ]);
        }
    }
}
