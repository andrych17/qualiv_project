<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use App\Modules\SysConfig\Requests\StoreConfigGroupRequest;
use App\Modules\SysConfig\Requests\UpdateConfigGroupRequest;
use App\Modules\SysConfig\Services\ConfigGroupService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigGroupController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'descr'];

    public function __construct(
        protected ConfigGroupService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $groups = ConfigGroup::query()
            ->filter($filters)
            ->withCount(['groupUsers', 'rights'])
            ->tap(fn ($query) => TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'code'))
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ConfigGroup $g) => [
                'id' => $g->id,
                'code' => $g->code,
                'descr' => $g->descr,
                'status_code' => $g->status_code,
                'status_label' => $g->status_code === 'A' ? 'active' : 'inactive',
                'users_count' => $g->group_users_count,
                'rights_count' => $g->rights_count,
            ]);

        return Inertia::render('Config/Groups/Index', [
            'groups' => $groups,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Config/Groups/Create');
    }

    public function store(StoreConfigGroupRequest $request)
    {
        $group = $this->service->create($request->validated());

        return redirect()->route('config.groups.edit', $group)
            ->with('success', 'Group created. Set access and users.');
    }

    public function edit(ConfigGroup $group): Response
    {
        $rightsByMenu = ConfigRight::query()
            ->where('group_id', $group->id)
            ->get()
            ->keyBy('menu_id');

        $menus = ConfigMenu::query()
            ->orderBy('seq')
            ->get()
            ->map(function (ConfigMenu $m) use ($rightsByMenu) {
                $trustee = $rightsByMenu->get($m->id)?->trustee ?? '';

                return [
                    'menu_id' => $m->id,
                    'code' => $m->code,
                    'label' => $m->menu_caption,
                    'header' => $m->menu_header,
                    'seq' => $m->seq,
                    'create' => str_contains($trustee, 'C'),
                    'read' => str_contains($trustee, 'R'),
                    'update' => str_contains($trustee, 'U'),
                    'delete' => str_contains($trustee, 'D'),
                ];
            })
            ->values()
            ->all();

        $userIds = $group->groupUsers()->pluck('user_id')->all();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->all();

        return Inertia::render('Config/Groups/Edit', [
            'group' => [
                'id' => $group->id,
                'code' => $group->code,
                'descr' => $group->descr,
                'status_code' => $group->status_code,
            ],
            'accessMenus' => $menus,
            'users' => $users,
            'user_ids' => $userIds,
        ]);
    }

    public function update(UpdateConfigGroupRequest $request, ConfigGroup $group)
    {
        $this->service->update($group, $request->validated());

        return redirect()->route('config.groups.index')
            ->with('success', 'Group updated.');
    }

    public function destroy(ConfigGroup $group)
    {
        $this->service->delete($group);

        return redirect()->route('config.groups.index')
            ->with('success', 'Group deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ConfigGroup::class, fn (ConfigGroup $group) => $this->service->delete($group));
    }
}
