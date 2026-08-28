<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Requests\StorePeriodRequest;
use App\Modules\Performance\Requests\UpdatePeriodRequest;
use App\Modules\Performance\Services\PeriodService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3C/§4 Periods (Entry) — fiscal period master shared by Targets and later Budgeting/Forecast/OKR cycles. */
class PeriodController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['label', 'start_date', 'created_at'];

    public function __construct(protected PeriodService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('period_type', 'status', 'sort', 'direction', 'per_page');

        $periods = Period::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'start_date', 'desc'),
                fn ($query) => $query->orderByDesc('start_date'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Period $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'period_type' => $p->period_type,
                'start_date_formatted' => $p->start_date->format('d M Y'),
                'end_date_formatted' => $p->end_date->format('d M Y'),
                'is_active' => $p->is_active,
            ]);

        return Inertia::render('Performance/Periods/Index', [
            'periods' => $periods,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/Periods/Create');
    }

    public function store(StorePeriodRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.periods.index')->with('success', 'Period created.');
    }

    public function edit(Period $period): Response
    {
        return Inertia::render('Performance/Periods/Edit', [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'period_type' => $period->period_type,
                'year' => $period->year,
                'quarter' => $period->quarter,
                'month' => $period->month,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'is_active' => $period->is_active,
            ],
        ]);
    }

    public function update(UpdatePeriodRequest $request, Period $period)
    {
        $this->service->update($period, $request->validated());

        return redirect()->route('performance.periods.index')->with('success', 'Period updated.');
    }

    public function destroy(Period $period)
    {
        $this->service->delete($period);

        return redirect()->route('performance.periods.index')->with('success', 'Period deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Period::class, fn (Period $p) => $this->service->delete($p));
    }
}
