<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\CapacityPlan;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\PpException;
use App\Modules\PP\Services\PpExceptionService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3M — Planning Exception Center. Read-only list + status workflow; generation happens in CapacityPlanService/MrpService/§3L. */
class PpExceptionController extends Controller
{
    private const SORTABLE = ['detected_at'];

    public function __construct(
        protected PpExceptionService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'exception_type', 'sort', 'direction', 'per_page');
        $status = $filters['status'] ?? PpException::STATUS_OPEN;

        $query = PpException::query()
            ->with('resolver:id,name')
            ->filter(['status' => $status, 'exception_type' => $filters['exception_type'] ?? null]);

        // Default order (severity-priority then most recent) only applies when the DataTable
        // header hasn't requested an explicit sort — same "default vs. explicit" shape as every
        // other PP index (see ScheduleOpController::index()).
        if (($filters['sort'] ?? null) && in_array($filters['sort'], self::SORTABLE, true)) {
            TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'detected_at');
        } else {
            $query->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
                ->orderByDesc('detected_at');
        }

        /** @var LengthAwarePaginator<int, PpException> $paginated */
        $paginated = $query
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        $rows = collect($paginated->items());
        $orderIds = $rows->where('subject_type', PpException::SUBJECT_PLANNED_ORDER)->pluck('subject_id');
        $planIds = $rows->where('subject_type', PpException::SUBJECT_CAPACITY_PLAN)->pluck('subject_id');
        $orders = PlannedOrder::query()->whereIn('id', $orderIds)->with('product:id,sku')->get()->keyBy('id');
        $plans = CapacityPlan::query()->whereIn('id', $planIds)->with('resourceGroup:id,name')->get()->keyBy('id');

        $exceptions = $paginated->through(fn (PpException $e) => $this->toRow($e, $orders, $plans));

        $counts = PpException::query()
            ->where('status', '!=', PpException::STATUS_RESOLVED)
            ->selectRaw('exception_type, count(*) as total')
            ->groupBy('exception_type')
            ->pluck('total', 'exception_type');

        return Inertia::render('PP/Exceptions/Index', [
            'exceptions' => $exceptions,
            'counts' => $counts,
            'filters' => $filters,
            'currentStatus' => $status,
            'currentType' => $filters['exception_type'] ?? null,
        ]);
    }

    public function acknowledge(PpException $exception)
    {
        $this->service->acknowledge($exception);

        return redirect()->back()->with('success', 'Exception acknowledged.');
    }

    public function resolve(PpException $exception, Request $request)
    {
        $this->service->resolve($exception, $request->user()->id);

        return redirect()->back()->with('success', 'Exception marked as resolved.');
    }

    /**
     * @param  Collection<int, PlannedOrder>  $orders
     * @param  Collection<int, CapacityPlan>  $plans
     * @return array<string, mixed>
     */
    private function toRow(PpException $e, $orders, $plans): array
    {
        return [
            'id' => $e->id,
            'exception_type' => $e->exception_type,
            'severity' => $e->severity,
            'subject_type' => $e->subject_type,
            'subject_id' => $e->subject_id,
            'subject_label' => $this->subjectLabel($e, $orders, $plans),
            'detail' => $e->detail,
            'status' => $e->status,
            'suggested_actions' => $this->service->suggestedActions($e),
            'resolved_by' => $e->resolver?->name,
            'resolved_at' => $e->resolved_at?->toDateTimeString(),
            'detected_at' => $e->detected_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  Collection<int, PlannedOrder>  $orders
     * @param  Collection<int, CapacityPlan>  $plans
     */
    private function subjectLabel(PpException $e, $orders, $plans): string
    {
        if ($e->subject_type === PpException::SUBJECT_PLANNED_ORDER) {
            $order = $orders->get($e->subject_id);

            return $order ? "{$order->plan_number} ({$order->product?->sku})" : "Planned order #{$e->subject_id} (deleted)";
        }

        if ($e->subject_type === PpException::SUBJECT_CAPACITY_PLAN) {
            $plan = $plans->get($e->subject_id);

            return $plan?->resourceGroup?->name ?? "Capacity plan #{$e->subject_id}";
        }

        return "#{$e->subject_id}";
    }
}
