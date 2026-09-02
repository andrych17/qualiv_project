<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Services\AndonService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3R — Andon Board. Pure read model; alert delivery/history is a separate sweep, see AndonService::checkAndFireAlerts(). */
class AndonController extends Controller
{
    public function __construct(protected AndonService $service) {}

    public function index(Request $request): Response
    {
        $workCenterId = $request->integer('work_center_id') ?: null;

        return Inertia::render('MES/Andon/Index', [
            'board' => $this->service->board($workCenterId),
            'workCenterId' => $workCenterId,
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    /** @return list<array{value: int, label: string}> */
    private function workCenterOptions(): array
    {
        return WorkCenter::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (WorkCenter $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])
            ->all();
    }
}
