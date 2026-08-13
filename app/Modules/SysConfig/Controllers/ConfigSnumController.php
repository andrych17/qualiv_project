<?php

namespace App\Modules\Config\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Config\Models\ConfigSnum;
use App\Modules\Config\Requests\StoreConfigSnumRequest;
use App\Modules\Config\Requests\UpdateConfigSnumRequest;
use App\Modules\Config\Services\ConfigSnumService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigSnumController extends Controller
{
    public function __construct(
        protected ConfigSnumService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search');

        $snums = ConfigSnum::query()
            ->filter($filters)
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ConfigSnum $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'last_cnt' => $s->last_cnt,
                'wrap_low' => $s->wrap_low,
                'wrap_high' => $s->wrap_high,
                'step_cnt' => $s->step_cnt,
                'descr' => $s->descr,
                'status_code' => $s->status_code,
            ]);

        return Inertia::render('Config/Serials/Index', [
            'snums' => $snums,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Config/Serials/Create');
    }

    public function store(StoreConfigSnumRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('config.serials.index')
            ->with('success', 'Serial created.');
    }

    public function edit(ConfigSnum $configSnum): Response
    {
        return Inertia::render('Config/Serials/Edit', [
            'snum' => [
                'id' => $configSnum->id,
                'code' => $configSnum->code,
                'last_cnt' => $configSnum->last_cnt,
                'wrap_low' => $configSnum->wrap_low,
                'wrap_high' => $configSnum->wrap_high,
                'step_cnt' => $configSnum->step_cnt,
                'descr' => $configSnum->descr,
                'status_code' => $configSnum->status_code,
            ],
        ]);
    }

    public function update(UpdateConfigSnumRequest $request, ConfigSnum $configSnum)
    {
        $this->service->update($configSnum, $request->validated());

        return redirect()->route('config.serials.index')
            ->with('success', 'Serial updated.');
    }

    public function destroy(ConfigSnum $configSnum)
    {
        $this->service->delete($configSnum);

        return redirect()->route('config.serials.index')
            ->with('success', 'Serial deleted.');
    }
}
