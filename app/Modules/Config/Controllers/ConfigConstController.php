<?php

namespace App\Modules\Config\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Config\Models\ConfigConst;
use App\Modules\Config\Requests\StoreConfigConstRequest;
use App\Modules\Config\Requests\UpdateConfigConstRequest;
use App\Modules\Config\Services\ConfigConstService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigConstController extends Controller
{
    public function __construct(
        protected ConfigConstService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'const_group');

        $consts = ConfigConst::query()
            ->filter($filters)
            ->orderBy('const_group')
            ->orderBy('seq')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ConfigConst $c) => [
                'id' => $c->id,
                'const_group' => $c->const_group,
                'group_code' => $c->group_code,
                'seq' => $c->seq,
                'str1' => $c->str1,
                'str2' => $c->str2,
                'num1' => $c->num1,
                'note1' => $c->note1,
            ]);

        $groups = ConfigConst::query()
            ->whereNotNull('const_group')
            ->distinct()
            ->orderBy('const_group')
            ->pluck('const_group')
            ->map(fn ($g) => ['label' => $g, 'value' => $g])
            ->values()
            ->all();

        return Inertia::render('Config/Consts/Index', [
            'consts' => $consts,
            'filters' => $filters,
            'groups' => $groups,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Config/Consts/Create');
    }

    public function store(StoreConfigConstRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('config.consts.index')
            ->with('success', 'Const created.');
    }

    public function edit(ConfigConst $configConst): Response
    {
        return Inertia::render('Config/Consts/Edit', [
            'constItem' => [
                'id' => $configConst->id,
                'const_group' => $configConst->const_group,
                'group_code' => $configConst->group_code,
                'seq' => $configConst->seq,
                'str1' => $configConst->str1,
                'str2' => $configConst->str2,
                'num1' => $configConst->num1,
                'num2' => $configConst->num2,
                'note1' => $configConst->note1,
            ],
        ]);
    }

    public function update(UpdateConfigConstRequest $request, ConfigConst $configConst)
    {
        $this->service->update($configConst, $request->validated());

        return redirect()->route('config.consts.index')
            ->with('success', 'Const updated.');
    }

    public function destroy(ConfigConst $configConst)
    {
        $this->service->delete($configConst);

        return redirect()->route('config.consts.index')
            ->with('success', 'Const deleted.');
    }
}
