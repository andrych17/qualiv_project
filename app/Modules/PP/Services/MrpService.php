<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\PP\Models\MrpRun;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\PpException;
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
 *
 * PP_SPECS.md §3K — a recipe-driven (process) product's netted qty is further batch-sized:
 * `applyBatchSizing()` inflates for `recipe.expected_yield_pct` and rounds up to a whole multiple
 * of `recipe.batch_size` before either the planned order is created or `RecipeService::scale()`
 * explodes dependent ingredient demand, so both reflect what actually gets produced (whole
 * batches), not the raw net requirement.
 *
 * PP_SPECS.md §3L — the "Material" constraint check: `checkMaterialShortage()` fires when a
 * covering order is actually created for a product whose available-to-promise was already
 * negative (not when netting alone would have shown it, and not for a firmed order — a planner's
 * firm override skips this check the same way it skips lot-sizing, see the §3C paragraph above),
 * and `checkLateOrders()` splits `TYPE_LATE_ORDER`/`TYPE_LATE_PURCHASE` by `order_type`. The other
 * four §3L checks (Resource, Sequence, Tank, Quality, Labor) are not run from here: Tank already
 * runs generically through `CapacityPlanService::checkOverload()` (§3F, no PP-specific code
 * needed); Resource/Sequence/Quality need MES equipment status, routing/phase predecessors, and
 * `mes_qc_holds` (none exist yet); Labor needs an HCM certification/skill model that doesn't
 * exist yet either — same "not built yet" posture as `PlannedOrderService::release()`.
 */
class MrpService
{
    private const MAX_ITERATIONS = 500;

    public function __construct(
        protected AvailabilityService $availability,
        protected PpService $pp,
        protected RecipeService $recipeScaler,
        protected ConfigSnumService $serials,
        protected PpExceptionService $exceptions,
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
            $stalePlannedIds = PlannedOrder::query()->baseline()->where('status', PlannedOrder::STATUS_PLANNED)->pluck('id');
            PlannedOrder::query()->baseline()->where('status', PlannedOrder::STATUS_PLANNED)->delete();
            DemandHeader::query()->where('source_type', DemandHeader::SOURCE_DEPENDENT)->delete();

            // §3L exceptions are keyed on `subject_id` = a planned order's own PK, which does not
            // survive regeneration (the row above was just deleted and, if the condition still
            // holds, a fresh order with a fresh id is about to replace it). Without this, every
            // re-run would orphan the prior exception and PpExceptionService::record()'s
            // firstOrCreate — matched on the new id — would create a duplicate open row instead
            // of recognizing "same condition, still true". Resolved (not deleted, per this
            // repo's "keep non-destructive" migration convention) rather than removed outright:
            // an acknowledged row is a planner's work product, not disposable, and a resolved
            // trail survives even though the order it once pointed at is gone (the Exception
            // Center already renders a deleted subject gracefully — see
            // PpExceptionController::subjectLabel()).
            PpException::query()
                ->where('subject_type', PpException::SUBJECT_PLANNED_ORDER)
                ->whereIn('subject_id', $stalePlannedIds)
                ->whereIn('status', [PpException::STATUS_OPEN, PpException::STATUS_ACKNOWLEDGED])
                ->update(['status' => PpException::STATUS_RESOLVED, 'resolved_at' => now()]);

            [$netted, $childContributions] = $this->explode($this->seedRequirements());

            $this->persist($mrpRun, $netted, $childContributions);
            $this->checkLateOrders();

            $mrpRun->update(['status' => MrpRun::STATUS_COMPLETED]);

            return $mrpRun->fresh();
        });
    }

    /**
     * @return array<int, array{qty: float, need_by: Carbon, driving_line_id: int|null}>
     *                                                                                   product_id => accumulated independent (non-dependent) gross requirement
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

                if ($recipe) {
                    $lotQty = $this->applyBatchSizing($recipe, $lotQty);
                }

                $netted[$productId] = $lotQty > 0 ? [
                    'qty' => $lotQty,
                    'need_by' => $req['need_by'],
                    'driving_line_id' => $req['driving_line_id'],
                    'type' => ($bom || $recipe) ? PlannedOrder::TYPE_PRODUCTION : PlannedOrder::TYPE_PURCHASE,
                    'bom_id' => $bom?->id,
                    'recipe_id' => $recipe?->id,
                    'available' => $available,
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
     * PP_SPECS.md §3K "batch-size planning" — applied after §3A's item-level lot sizing, since
     * `recipe.batch_size` is a physical constraint (a process order can only run in whole
     * batches) rather than a planning policy. `expected_yield_pct` means a batch's *good* output
     * is less than its `batch_size`, so netting $netQty good units requires producing enough
     * gross batches to cover the yield loss before rounding up to the nearest whole batch.
     */
    private function applyBatchSizing(Recipe $recipe, float $netQty): float
    {
        $batchSize = (float) $recipe->batch_size;
        if ($batchSize <= 0) {
            return $netQty;
        }

        $yieldPct = (float) $recipe->expected_yield_pct;
        $grossQty = $yieldPct > 0 ? $netQty / ($yieldPct / 100) : $netQty;

        return ceil($grossQty / $batchSize) * $batchSize;
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

            if (($data['available'] ?? 0) < 0) {
                $this->checkMaterialShortage($order, (float) $data['available']);
            }

            $this->recordDependentDemand($order, $childContributions[$productId] ?? []);
        }
    }

    /**
     * PP_SPECS.md §3L "Material" — reads PP's own `AvailabilityService::totalAvailableQty()`,
     * the warehouse-agnostic MRP-planning equivalent of `InventoryService::checkAvailability()`
     * the spec names (see that service's own docblock). A negative available-to-promise means
     * this product was already oversold/overcommitted against physical + reserved stock before
     * this covering order — a distinct condition from `checkLateOrders()`'s "will this order
     * arrive in time". Only reached for a newly-created (non-firmed) order — see this class's
     * own docblock for why firmed orders and a fully-covered net requirement (`$lotQty === 0`,
     * never persisted) skip it.
     */
    private function checkMaterialShortage(PlannedOrder $order, float $available): void
    {
        $this->exceptions->record(
            PpException::TYPE_MATERIAL_SHORTAGE,
            PpException::SUBJECT_PLANNED_ORDER,
            $order->id,
            "Planned order {$order->plan_number} ({$order->product?->sku}) covers a shortage — available-to-promise was already {$available} before this order.",
            PpException::SEVERITY_HIGH,
        );
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

    /**
     * PP_SPECS.md §3M "late production orders" — scans every still-open baseline planned order
     * (not just this run's output, since a firmed order survives a run untouched — see class
     * docblock) whose need_by_date has already passed. PP_SPECS.md §3L splits the exception type
     * by `order_type`: a purchase order past due is `TYPE_LATE_PURCHASE` (the material never
     * arrived), everything else (production/transfer) is `TYPE_LATE_ORDER` — two distinct rows
     * in §3M's dashboard, `PpExceptionService::suggestedActions()` already groups them.
     */
    private function checkLateOrders(): void
    {
        $late = PlannedOrder::query()->baseline()
            ->whereIn('status', [PlannedOrder::STATUS_PLANNED, PlannedOrder::STATUS_FIRMED])
            ->where('need_by_date', '<', now()->toDateString())
            ->with('product:id,sku')
            ->get();

        foreach ($late as $order) {
            $daysLate = (int) now()->diffInDays($order->need_by_date);
            $severity = match (true) {
                $daysLate >= 14 => PpException::SEVERITY_CRITICAL,
                $daysLate >= 7 => PpException::SEVERITY_HIGH,
                default => PpException::SEVERITY_MEDIUM,
            };
            $type = $order->order_type === PlannedOrder::TYPE_PURCHASE
                ? PpException::TYPE_LATE_PURCHASE
                : PpException::TYPE_LATE_ORDER;

            $this->exceptions->record(
                $type,
                PpException::SUBJECT_PLANNED_ORDER,
                $order->id,
                "Planned order {$order->plan_number} ({$order->product?->sku}) is overdue by {$daysLate} day(s) — need-by was {$order->need_by_date->toDateString()}.",
                $severity,
            );
        }
    }

    private function nextPlanNumber(): string
    {
        $n = $this->serials->next('PP_PLAN_LASTID');

        return sprintf('PP-PLAN-%06d', $n);
    }
}
