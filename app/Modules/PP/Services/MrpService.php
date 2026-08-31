<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\PP\Models\MrpRun;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\Recipe;
use App\Modules\SysConfig\Services\ConfigSnumService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PP_SPECS.md §3D — the MRP engine. Regenerative: every run replaces prior unreleased
 * (`status = 'planned'`) baseline planned orders and prior `dependent`-source demand from the
 * previous run, then rebuilds the whole plan from the current baseline demand. Released
 * planned orders are never touched — they already belong to Purchase/MES/Inventory.
 *
 * Multi-level BOM/Recipe explosion is computed in-run with a worklist: a product is re-netted
 * whenever the dependent demand it receives from a parent changes, converging for acyclic
 * BOMs/Recipes (`chk_pp_planned_orders_production_source` plus each Store*Request's own
 * component/ingredient self-reference guard keep the trivial one-hop cycle out of the data;
 * `MAX_ITERATIONS` is the backstop for anything deeper). `expected_production` in the netting
 * formula is always 0 — there is no MES yet to report in-progress production; `scheduled_receipts`
 * nets against PP's own already-released purchase-type planned orders (real, cheaply available
 * data), so a second run right after releasing a requisition does not double-order it.
 *
 * PP_SPECS.md §3C — a product with an existing `status = 'firmed'` baseline planned order skips
 * netting/lot-sizing entirely: the firmed order's own qty/bom_id/recipe_id drive that product's
 * dependent-demand explosion, and no new row is created for it (only `status = 'planned'` rows
 * are cleared at the top of `run()`, so the firmed row survives untouched). This is how a
 * planner's "firm" action on an MPS cell excludes that order from automatic MRP regeneration.
 */
class MrpService
{
    private const MAX_ITERATIONS = 500;

    public function __construct(
        protected AvailabilityService $availability,
        protected PpService $pp,
        protected RecipeService $recipeScaler,
        protected ConfigSnumService $serials,
    ) {}

    public function run(): MrpRun
    {
        return DB::transaction(function () {
            $mrpRun = MrpRun::query()->create([
                'run_at' => now(),
                'triggered_by' => auth()->id(),
                'status' => MrpRun::STATUS_RUNNING,
            ]);

            // Regenerative: clear what a prior run produced that nothing downstream owns yet.
            PlannedOrder::query()->baseline()->where('status', PlannedOrder::STATUS_PLANNED)->delete();
            DemandHeader::query()->where('source_type', DemandHeader::SOURCE_DEPENDENT)->delete();

            [$netted, $childContributions] = $this->explode($this->seedRequirements());

            $this->persist($mrpRun, $netted, $childContributions);

            $mrpRun->update(['status' => MrpRun::STATUS_COMPLETED]);

            return $mrpRun->fresh();
        });
    }

    /**
     * @return array<int, array{qty: float, need_by: Carbon, driving_line_id: int|null}>
     *         product_id => accumulated independent (non-dependent) gross requirement
     */
    private function seedRequirements(): array
    {
        $requirements = [];

        $lines = DemandLine::query()
            ->baseline()
            ->whereHas('header', fn ($query) => $query->where('source_type', '!=', DemandHeader::SOURCE_DEPENDENT))
            ->orderBy('need_by_date')
            ->get(['id', 'product_id', 'need_by_date', 'qty']);

        foreach ($lines as $line) {
            $productId = $line->product_id;
            if (! isset($requirements[$productId])) {
                $requirements[$productId] = ['qty' => 0.0, 'need_by' => $line->need_by_date, 'driving_line_id' => $line->id];
            }
            $requirements[$productId]['qty'] += (float) $line->qty;
            if ($line->need_by_date->lt($requirements[$productId]['need_by'])) {
                $requirements[$productId]['need_by'] = $line->need_by_date;
                $requirements[$productId]['driving_line_id'] = $line->id;
            }
        }

        return $requirements;
    }

    /**
     * @param  array<int, array{qty: float, need_by: Carbon, driving_line_id: int|null}>  $requirements
     * @return array{0: array<int, array<string, mixed>|null>, 1: array<int, array<int, float>>}
     */
    private function explode(array $requirements): array
    {
        $queue = array_keys($requirements);
        $netted = [];
        /** @var array<int, array<int, float>> $childContributions parentProductId => [childProductId => qty] */
        $childContributions = [];
        $iterations = 0;

        while ($queue !== []) {
            if (++$iterations > self::MAX_ITERATIONS) {
                throw new RuntimeException('MRP run exceeded its iteration limit — check for a circular BOM/recipe reference.');
            }

            $productId = array_shift($queue);
            $req = $requirements[$productId];

            $firmed = PlannedOrder::query()->baseline()
                ->where('product_id', $productId)
                ->where('status', PlannedOrder::STATUS_FIRMED)
                ->first();

            if ($firmed) {
                $lotQty = (float) $firmed->qty;
                $bom = $firmed->bom_id ? Bom::query()->with('lines')->find($firmed->bom_id) : null;
                $recipe = $firmed->recipe_id ? Recipe::query()->with('ingredients')->find($firmed->recipe_id) : null;
                $netted[$productId] = ['existing_order' => $firmed];
            } else {
                $available = $this->availability->totalAvailableQty($productId);
                $scheduledReceipts = (float) PlannedOrder::query()
                    ->baseline()
                    ->where('product_id', $productId)
                    ->where('order_type', PlannedOrder::TYPE_PURCHASE)
                    ->where('status', PlannedOrder::STATUS_RELEASED)
                    ->sum('qty');
                $safetyStock = (float) (ItemPlanningParam::query()->where('product_id', $productId)->value('safety_stock_qty') ?? 0);

                $netRequirement = $req['qty'] - $available - $scheduledReceipts + $safetyStock;
                $lotQty = $netRequirement > 0 ? $this->applyLotSizing($productId, $netRequirement) : 0.0;

                $bom = $lotQty > 0 ? $this->pp->getActiveBom($productId) : null;
                $recipe = ($lotQty > 0 && $bom === null) ? $this->pp->getActiveRecipe($productId) : null;

                $netted[$productId] = $lotQty > 0 ? [
                    'qty' => $lotQty,
                    'need_by' => $req['need_by'],
                    'driving_line_id' => $req['driving_line_id'],
                    'type' => ($bom || $recipe) ? PlannedOrder::TYPE_PRODUCTION : PlannedOrder::TYPE_PURCHASE,
                    'bom_id' => $bom?->id,
                    'recipe_id' => $recipe?->id,
                ] : null;
            }

            $newContribution = [];
            if ($bom) {
                foreach ($bom->lines as $line) {
                    $qty = (float) $line->qty_per_parent_unit * (1 + (float) $line->scrap_pct / 100) * $lotQty;
                    $newContribution[$line->component_product_id] = ($newContribution[$line->component_product_id] ?? 0) + $qty;
                }
            } elseif ($recipe) {
                foreach ($this->recipeScaler->scale($recipe, $lotQty) as $ingredient) {
                    $newContribution[$ingredient['product_id']] = ($newContribution[$ingredient['product_id']] ?? 0) + $ingredient['qty'];
                }
            }

            $oldContribution = $childContributions[$productId] ?? [];
            foreach (array_unique([...array_keys($newContribution), ...array_keys($oldContribution)]) as $childId) {
                $delta = ($newContribution[$childId] ?? 0.0) - ($oldContribution[$childId] ?? 0.0);
                if (abs($delta) < 0.0000005) {
                    continue;
                }

                if (! isset($requirements[$childId])) {
                    $requirements[$childId] = ['qty' => 0.0, 'need_by' => $req['need_by'], 'driving_line_id' => null];
                }
                $requirements[$childId]['qty'] += $delta;
                if ($req['need_by']->lt($requirements[$childId]['need_by'])) {
                    $requirements[$childId]['need_by'] = $req['need_by'];
                }

                if (! in_array($childId, $queue, true)) {
                    $queue[] = $childId;
                }
            }
            $childContributions[$productId] = $newContribution;
        }

        return [$netted, $childContributions];
    }

    /**
     * §3A lot sizing — only ever rounds UP (never caps at `max_lot_qty`, which would silently
     * under-supply a genuine shortage; splitting an over-`max` requirement into several lots is
     * a real feature left for later, not approximated here).
     */
    private function applyLotSizing(int $productId, float $netQty): float
    {
        $param = ItemPlanningParam::query()->where('product_id', $productId)->first();
        if ($param === null) {
            return $netQty;
        }

        $qty = $netQty;

        if ($param->fixed_lot_qty !== null && (float) $param->fixed_lot_qty > 0) {
            $fixed = (float) $param->fixed_lot_qty;
            $qty = ceil($qty / $fixed) * $fixed;
        } elseif ($param->economic_lot_qty !== null && (float) $param->economic_lot_qty > $qty) {
            $qty = (float) $param->economic_lot_qty;
        } elseif ($param->min_lot_qty !== null && (float) $param->min_lot_qty > $qty) {
            $qty = (float) $param->min_lot_qty;
        }

        if ($param->order_multiple !== null && (float) $param->order_multiple > 0) {
            $multiple = (float) $param->order_multiple;
            $qty = ceil($qty / $multiple) * $multiple;
        }

        return $qty;
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $netted
     * @param  array<int, array<int, float>>  $childContributions
     */
    private function persist(MrpRun $mrpRun, array $netted, array $childContributions): void
    {
        foreach ($netted as $productId => $data) {
            if ($data === null) {
                continue;
            }

            if (isset($data['existing_order'])) {
                $this->recordDependentDemand($data['existing_order'], $childContributions[$productId] ?? []);

                continue;
            }

            $leadTimeDays = (int) (ItemPlanningParam::query()->where('product_id', $productId)->value('lead_time_days') ?? 0);

            $order = PlannedOrder::query()->create([
                'mrp_run_id' => $mrpRun->id,
                'plan_number' => $this->nextPlanNumber(),
                'order_type' => $data['type'],
                'product_id' => $productId,
                'qty' => $data['qty'],
                'need_by_date' => $data['need_by']->copy()->subDays($leadTimeDays),
                'source_type' => 'demand_line',
                'source_id' => $data['driving_line_id'],
                'bom_id' => $data['bom_id'],
                'recipe_id' => $data['recipe_id'],
                'status' => PlannedOrder::STATUS_PLANNED,
            ]);

            $this->recordDependentDemand($order, $childContributions[$productId] ?? []);
        }
    }

    /** @param  array<int, float>  $contributions childProductId => qty */
    private function recordDependentDemand(PlannedOrder $order, array $contributions): void
    {
        foreach ($contributions as $childProductId => $qty) {
            if ($qty <= 0) {
                continue;
            }

            $header = DemandHeader::query()->create([
                'source_type' => DemandHeader::SOURCE_DEPENDENT,
                'subject_type' => PlannedOrder::class,
                'subject_id' => $order->id,
                'demand_date' => now()->toDateString(),
                'note' => "Exploded from planned order {$order->plan_number}",
            ]);

            DemandLine::query()->create([
                'demand_hdr_id' => $header->id,
                'product_id' => $childProductId,
                'need_by_date' => $order->need_by_date,
                'qty' => $qty,
            ]);
        }
    }

    private function nextPlanNumber(): string
    {
        $n = $this->serials->next('PP_PLAN_LASTID');

        return sprintf('PP-PLAN-%06d', $n);
    }
}
