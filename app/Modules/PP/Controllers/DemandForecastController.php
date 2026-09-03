<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\DemandForecast;
use App\Modules\PP\Requests\StoreDemandForecastRequest;
use App\Modules\PP\Requests\UpdateDemandForecastRequest;
use App\Modules\PP\Services\DemandAggregationService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3B Demand Forecasts (Entry) — master data feeding one demand line each. */
class DemandForecastController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['period_start', 'qty', 'created_at'];

    public function __construct(protected DemandAggregationService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $forecasts = DemandForecast::query()
            ->with('product:id,sku,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'period_start', 'desc'),
                fn ($query) => $query->orderByDesc('period_start'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (DemandForecast $f) => [
                'id' => $f->id,
                'product_sku' => $f->product?->sku,
                'product_name' => $f->product?->name,
                'period_start' => $f->period_start->toDateString(),
                'qty' => (float) $f->qty,
                'source' => $f->source,
            ]);

        return Inertia::render('PP/DemandForecasts/Index', [
            'forecasts' => $forecasts,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/DemandForecasts/Create');
    }

    public function store(StoreDemandForecastRequest $request)
    {
        $this->service->createForecast($request->validated());

        return redirect()->route('pp.demandForecasts.index')->with('success', 'Forecast added.');
    }

    public function edit(DemandForecast $demandForecast): Response
    {
        return Inertia::render('PP/DemandForecasts/Edit', [
            'forecast' => [
                'id' => $demandForecast->id,
                'product_id' => $demandForecast->product_id,
                'product_label' => $demandForecast->product ? "{$demandForecast->product->sku} — {$demandForecast->product->name}" : null,
                'period_start' => $demandForecast->period_start->toDateString(),
                'qty' => (float) $demandForecast->qty,
                'source' => $demandForecast->source,
                'note' => $demandForecast->note,
            ],
        ]);
    }

    public function update(UpdateDemandForecastRequest $request, DemandForecast $demandForecast)
    {
        $this->service->updateForecast($demandForecast, $request->validated());

        return redirect()->route('pp.demandForecasts.index')->with('success', 'Forecast updated.');
    }

    public function destroy(DemandForecast $demandForecast)
    {
        $this->service->deleteForecast($demandForecast);

        return redirect()->route('pp.demandForecasts.index')->with('success', 'Forecast deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, DemandForecast::class, fn (DemandForecast $f) => $this->service->deleteForecast($f));
    }
}
