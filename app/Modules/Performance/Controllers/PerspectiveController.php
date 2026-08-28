<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Models\Perspective;
use App\Modules\Performance\Requests\StorePerspectiveRequest;
use App\Modules\Performance\Requests\UpdatePerspectiveRequest;
use App\Modules\Performance\Services\PerspectiveService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3C Perspectives (Entry) — tenant-editable Balanced-Scorecard categories. */
class PerspectiveController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['name', 'created_at'];

    public function __construct(protected PerspectiveService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $perspectives = Perspective::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'name'),
                fn ($query) => $query->orderBy('name'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Perspective $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'is_active' => $p->is_active,
            ]);

        return Inertia::render('Performance/Perspectives/Index', [
            'perspectives' => $perspectives,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/Perspectives/Create');
    }

    public function store(StorePerspectiveRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.perspectives.index')->with('success', 'Perspective created.');
    }

    public function edit(Perspective $perspective): Response
    {
        return Inertia::render('Performance/Perspectives/Edit', [
            'perspective' => $perspective->only('id', 'name', 'description', 'is_active'),
        ]);
    }

    public function update(UpdatePerspectiveRequest $request, Perspective $perspective)
    {
        $this->service->update($perspective, $request->validated());

        return redirect()->route('performance.perspectives.index')->with('success', 'Perspective updated.');
    }

    public function destroy(Perspective $perspective)
    {
        $this->service->delete($perspective);

        return redirect()->route('performance.perspectives.index')->with('success', 'Perspective deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Perspective::class, fn (Perspective $p) => $this->service->delete($p));
    }
}
