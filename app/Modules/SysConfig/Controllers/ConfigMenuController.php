<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Requests\StoreConfigMenuRequest;
use App\Modules\SysConfig\Requests\UpdateConfigMenuRequest;
use App\Modules\SysConfig\Services\ConfigMenuService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigMenuController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'menu_caption', 'menu_header', 'seq'];

    public function __construct(
        protected ConfigMenuService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'header', 'sort', 'direction', 'per_page');

        $menus = ConfigMenu::query()
            ->filter($filters)
            ->tap(fn ($query) => TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'seq'))
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ConfigMenu $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'menu_caption' => $m->menu_caption,
                'menu_header' => $m->menu_header,
                'menu_link' => $m->menu_link,
                'icon' => $m->icon,
                'seq' => $m->seq,
                'status_code' => $m->status_code,
                'status_label' => $m->status_code === 'A' ? 'active' : 'inactive',
            ]);

        $headers = ConfigMenu::query()
            ->whereNotNull('menu_header')
            ->distinct()
            ->orderBy('menu_header')
            ->pluck('menu_header')
            ->map(fn ($h) => ['label' => $h, 'value' => $h])
            ->values()
            ->all();

        return Inertia::render('Config/Menus/Index', [
            'items' => $menus,
            'filters' => $filters,
            'headers' => $headers,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Config/Menus/Create', [
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(StoreConfigMenuRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('config.menus.index')
            ->with('success', 'Menu created.');
    }

    public function edit(ConfigMenu $menu): Response
    {
        return Inertia::render('Config/Menus/Edit', [
            'menu' => [
                'id' => $menu->id,
                'code' => $menu->code,
                'menu_caption' => $menu->menu_caption,
                'menu_header' => $menu->menu_header,
                'menu_link' => $menu->menu_link,
                'icon' => $menu->icon,
                'parent_id' => $menu->parent_id,
                'seq' => $menu->seq,
                'status_code' => $menu->status_code,
            ],
            'parents' => $this->parentOptions($menu->id),
        ]);
    }

    public function update(UpdateConfigMenuRequest $request, ConfigMenu $menu)
    {
        $this->service->update($menu, $request->validated());

        return redirect()->route('config.menus.index')
            ->with('success', 'Menu updated.');
    }

    public function destroy(ConfigMenu $menu)
    {
        $this->service->delete($menu);

        return redirect()->route('config.menus.index')
            ->with('success', 'Menu deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, ConfigMenu::class, fn (ConfigMenu $menu) => $this->service->delete($menu));
    }

    /** @return list<array{label: string, value: int}> */
    private function parentOptions(?int $excludeId = null): array
    {
        return ConfigMenu::query()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('seq')
            ->get(['id', 'code', 'menu_caption'])
            ->map(fn (ConfigMenu $m) => [
                'label' => $m->code.' — '.$m->menu_caption,
                'value' => $m->id,
            ])
            ->values()
            ->all();
    }
}
