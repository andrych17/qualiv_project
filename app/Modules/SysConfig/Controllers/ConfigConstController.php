<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Requests\StoreConfigConstRequest;
use App\Modules\SysConfig\Requests\UpdateConfigConstRequest;
use App\Modules\SysConfig\Services\ConfigConstService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConfigConstController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['const_group', 'group_code', 'seq', 'str1', 'num1', 'note1'];

    public function __construct(
        protected ConfigConstService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'const_group', 'sort', 'direction', 'per_page');

        $consts = ConfigConst::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'const_group'),
                fn ($query) => $query->orderBy('const_group')->orderBy('seq'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
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

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ConfigConst::class, fn (ConfigConst $const) => $this->service->delete($const));
    }

    /** DataTable InlineEditor demo — only str1/seq are editable inline; everything else needs the full form. */
    public function quickUpdate(Request $request, ConfigConst $configConst)
    {
        $data = $request->validate([
            'field' => ['required', 'string', Rule::in(['str1', 'seq'])],
            'value' => ['required'],
        ]);

        $value = $data['field'] === 'seq' ? (int) $data['value'] : $data['value'];

        $this->service->quickUpdate($configConst, $data['field'], $value);

        return back()->with('success', 'Const updated.');
    }
}
