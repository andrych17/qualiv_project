<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Services\DispatchQueueService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3Q — MES Scheduling / live dispatch queue. Pure read model + one write lever (`promote`); see DispatchQueueService for the boundary with PP's own §3H planning engine. */
class DispatchQueueController extends Controller
{
    public function __construct(protected DispatchQueueService $service) {}

    public function index(Request $request): Response
    {
        $workCenterId = $request->integer('work_center_id') ?: null;

        return Inertia::render('MES/DispatchQueue/Index', [
            'queue' => $this->service->forWorkCenter($workCenterId),
            'workCenterId' => $workCenterId,
            'workCenters' => $this->workCenterOptions(),
            'shiftInSession' => $this->service->shiftInSession(),
        ]);
    }

    public function promote(Request $request, ProdOrder $prodOrder)
    {
        try {
            $this->service->promote($prodOrder, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Order promoted to urgent in the dispatch queue.');
    }

    /** @return list<array{value: int, label: string}> */
    private function workCenterOptions(): array
    {
        return WorkCenter::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (WorkCenter $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])
            ->all();
    }
}
