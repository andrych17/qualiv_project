<?php

namespace App\Modules\PP\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\PP\Models\ItemPlanningParam;
use Illuminate\Support\Facades\DB;

/** PP_SPECS.md §3A — CRUD for the one planning-parameter row per product. */
class ItemPlanningParamService
{
    public const ENTITY = 'pp_item_planning_param';

    public function __construct(protected CustomFieldService $customFields) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): ItemPlanningParam
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($data, $custom) {
            $param = ItemPlanningParam::query()->create($this->attributes($data));

            $this->customFields->sync(self::ENTITY, $param->id, $custom);

            return $param;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ItemPlanningParam $param, array $data): ItemPlanningParam
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($param, $data, $custom) {
            $param->update($this->attributes($data));

            $this->customFields->sync(self::ENTITY, $param->id, $custom);

            return $param->refresh();
        });
    }

    public function delete(ItemPlanningParam $param): void
    {
        DB::transaction(function () use ($param) {
            $this->customFields->deleteFor(self::ENTITY, $param->id);
            $param->delete();
        });
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'product_id' => $data['product_id'],
            'make_type' => $data['make_type'] ?? ItemPlanningParam::MAKE_TO_STOCK,
            'min_lot_qty' => $data['min_lot_qty'] ?? null,
            'max_lot_qty' => $data['max_lot_qty'] ?? null,
            'fixed_lot_qty' => $data['fixed_lot_qty'] ?? null,
            'economic_lot_qty' => $data['economic_lot_qty'] ?? null,
            'safety_stock_qty' => $data['safety_stock_qty'] ?? 0,
            'lead_time_days' => $data['lead_time_days'] ?? 0,
            'planning_lead_time_days' => $data['planning_lead_time_days'] ?? 0,
            'order_multiple' => $data['order_multiple'] ?? null,
            'scrap_pct' => $data['scrap_pct'] ?? 0,
            'yield_pct_override' => $data['yield_pct_override'] ?? null,
            'production_calendar_ref' => $data['production_calendar_ref'] ?? null,
            'preferred_line_type' => $data['preferred_line_ref_id'] ?? null ? 'mes_work_center' : null,
            'preferred_line_ref_id' => $data['preferred_line_ref_id'] ?? null,
            'alternate_line_ref_id' => $data['alternate_line_ref_id'] ?? null,
            'planning_fence_days' => $data['planning_fence_days'] ?? 0,
        ];
    }
}
