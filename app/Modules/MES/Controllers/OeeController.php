<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Services\OeeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3O — OEE & Process KPIs. Pure read model; see OeeService for the aggregation and its documented scope (Work Center × Day, not Machine — see the service's own docblock). */
class OeeController extends Controller
{
    public function __construct(protected OeeService $service) {}

    public function index(Request $request): Response
    {
        $workCenterId = $request->integer('work_center_id') ?: null;
        $date = $request->input('date') ?: now()->toDateString();

        return Inertia::render('MES/Oee/Index', array_merge(
            $this->service->summary($workCenterId, $date),
            ['workCenters' => $this->workCenterOptions()],
        ));
    }

    /** @return list<array{value: int, label: string}> */
    private function workCenterOptions(): array
    {
        return WorkCenter::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (WorkCenter $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])
            ->all();
    }
}
