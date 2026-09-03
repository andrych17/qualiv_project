<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\ScheduleOp;
use App\Modules\PP\Requests\ApplySchedulingRuleRequest;
use App\Modules\PP\Requests\MergeScheduleOpRequest;
use App\Modules\PP\Requests\SplitScheduleOpRequest;
use App\Modules\PP\Requests\StoreScheduleOpRequest;
use App\Modules\PP\Requests\UpdateScheduleOpRequest;
use App\Modules\PP\Services\ScheduleOpService;
use App\Modules\PP\Services\SchedulingRuleService;
use App\Modules\SysConfig\Services\ConfigService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3H Detailed Scheduling — finite-capacity Gantt proposal over a production planned order's operations. */
class ScheduleOpController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['planned_start', 'planned_end', 'seq'];

    public function __construct(
        protected ScheduleOpService $service,
        protected ConfigService $config,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('resource_type', 'status', 'sort', 'direction', 'per_page');

        $ops = ScheduleOp::query()
            ->baseline()
            ->with('plannedOrder:id,plan_number,product_id')
            ->with('plannedOrder.product:id,sku,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'planned_start'),
                fn ($query) => $query->orderBy('planned_start'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ScheduleOp $op) => $this->toRow($op));

        return Inertia::render('PP/ScheduleOps/Index', [
            'ops' => $ops,
            'filters' => $filters,
            'strategyOptions' => collect(SchedulingRuleService::AVAILABLE)
                ->map(fn (string $s) => ['value' => $s, 'label' => SchedulingRuleService::LABELS[$s]])
                ->values(),
            'defaultStrategy' => $this->config->get('PP', 'DEFAULT_SCHEDULING_STRATEGY') ?? SchedulingRuleService::STRATEGY_EARLIEST_DUE_DATE,
        ]);
    }

    /** PP_SPECS.md §3I — apply a dispatch strategy to one resource's draft queue; rewrites seq only. */
    public function applyStrategy(ApplySchedulingRuleRequest $request)
    {
        try {
            $ordered = $this->service->applyStrategy(
                $request->validated('resource_type'),
                (int) $request->validated('resource_ref_id'),
                $request->validated('strategy'),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $label = SchedulingRuleService::LABELS[$request->validated('strategy')] ?? $request->validated('strategy');

        return redirect()->route('pp.scheduleOps.index')->with('success', "{$ordered->count()} draft operation(s) resequenced using {$label}.");
    }

    public function create(): Response
    {
        return Inertia::render('PP/ScheduleOps/Create', [
            'plannedOrderOptions' => $this->plannedOrderOptions(),
        ]);
    }

    public function store(StoreScheduleOpRequest $request)
    {
        try {
            $this->service->create($request->validated());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('pp.scheduleOps.index')->with('success', 'Operation scheduled.');
    }

    public function edit(ScheduleOp $schedule_op): Response
    {
        return Inertia::render('PP/ScheduleOps/Edit', [
            'op' => $this->toFormData($schedule_op),
        ]);
    }

    public function update(UpdateScheduleOpRequest $request, ScheduleOp $schedule_op)
    {
        try {
            $this->service->update($schedule_op, $request->validated());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('pp.scheduleOps.index')->with('success', 'Operation updated.');
    }

    public function destroy(ScheduleOp $schedule_op)
    {
        $this->service->delete($schedule_op);

        return redirect()->route('pp.scheduleOps.index')->with('success', 'Operation removed.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ScheduleOp::class, fn (ScheduleOp $op) => $this->service->delete($op));
    }

    public function commit(ScheduleOp $schedule_op)
    {
        try {
            $this->service->commit($schedule_op);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('pp.scheduleOps.index')->with('success', 'Operation committed.');
    }

    public function release(ScheduleOp $schedule_op)
    {
        try {
            $this->service->release($schedule_op);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('pp.scheduleOps.index')->with('success', 'Operation released.');
    }

    public function split(SplitScheduleOpRequest $request, ScheduleOp $schedule_op)
    {
        try {
            $this->service->split($schedule_op, Carbon::parse($request->validated('split_at')));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('pp.scheduleOps.index')->with('success', 'Operation split.');
    }

    public function merge(MergeScheduleOpRequest $request, ScheduleOp $schedule_op)
    {
        $target = ScheduleOp::query()->find($request->validated('target_id'));
        if (! $target) {
            return back()->withErrors(['target_id' => 'Target operation not found.']);
        }

        try {
            $this->service->merge($schedule_op, $target);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('pp.scheduleOps.index')->with('success', 'Operations merged.');
    }

    /** @return array<string, mixed> */
    private function toRow(ScheduleOp $op): array
    {
        return [
            'id' => $op->id,
            'plan_number' => $op->plannedOrder?->plan_number,
            'product_label' => $op->plannedOrder?->product ? "{$op->plannedOrder->product->sku} — {$op->plannedOrder->product->name}" : null,
            'seq' => $op->seq,
            'resource_type' => $op->resource_type,
            'resource_ref_id' => $op->resource_ref_id,
            'planned_start' => $op->planned_start->toDateTimeString(),
            'planned_end' => $op->planned_end->toDateTimeString(),
            'status' => $op->status,
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(ScheduleOp $op): array
    {
        return [
            'id' => $op->id,
            'plan_number' => $op->plannedOrder?->plan_number,
            'seq' => $op->seq,
            'resource_type' => $op->resource_type,
            'resource_ref_id' => $op->resource_ref_id,
            'planned_start' => $op->planned_start->format('Y-m-d\TH:i'),
            'planned_end' => $op->planned_end->format('Y-m-d\TH:i'),
            'status' => $op->status,
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function plannedOrderOptions(): array
    {
        return PlannedOrder::query()
            ->baseline()
            ->where('order_type', PlannedOrder::TYPE_PRODUCTION)
            ->whereIn('status', [PlannedOrder::STATUS_PLANNED, PlannedOrder::STATUS_FIRMED])
            ->with('product:id,sku,name')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (PlannedOrder $o) => ['value' => $o->id, 'label' => "{$o->plan_number} — {$o->product?->sku}"])
            ->all();
    }
}
