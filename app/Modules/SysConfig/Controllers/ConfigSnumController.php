<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SysConfig\Models\ConfigSnum;
use App\Modules\SysConfig\Requests\StoreConfigSnumRequest;
use App\Modules\SysConfig\Requests\UpdateConfigSnumRequest;
use App\Modules\SysConfig\Services\ConfigSnumService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigSnumController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'last_cnt', 'wrap_low', 'wrap_high', 'step_cnt', 'descr', 'status_code'];

    public function __construct(
        protected ConfigSnumService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $snums = ConfigSnum::query()
            ->filter($filters)
            ->tap(fn ($query) => TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'code'))
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
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
                'padding_length' => $s->padding_length,
                'reset_rule' => $s->reset_rule,
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
                'padding_length' => $configSnum->padding_length,
                'reset_rule' => $configSnum->reset_rule,
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

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ConfigSnum::class, fn (ConfigSnum $snum) => $this->service->delete($snum));
    }
}
