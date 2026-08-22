<?php

namespace App\Modules\DMS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DMS\Models\DocType;
use App\Modules\DMS\Models\Folder;
use App\Modules\DMS\Models\RetentionPolicy;
use App\Modules\DMS\Requests\StoreFolderRequest;
use App\Modules\DMS\Requests\UpdateFolderRequest;
use App\Modules\DMS\Services\FolderService;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** §3D Folder / Category Management (standalone use) — tree CRUD, depth-indented flat listing. */
class FolderController extends Controller
{
    public function __construct(private readonly FolderService $service) {}

    public function index(): Response
    {
        $folders = Folder::query()
            ->with(['parent:id,name', 'defaultDocType:id,name'])
            ->withCount(['documents', 'children'])
            ->orderBy('name')
            ->get();

        return Inertia::render('DMS/Folders/Index', [
            'folders' => $this->indent($folders)->map(fn (Folder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'depth' => $f->depth,
                'parent_name' => $f->parent?->name,
                'default_doc_type_name' => $f->defaultDocType?->name,
                'access_flag' => $f->access_flag,
                'document_count' => $f->documents_count,
                'child_count' => $f->children_count,
            ])->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('DMS/Folders/Create', [
            'parents' => $this->parentOptions(),
            'docTypes' => DocType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'retentionPolicies' => $this->retentionPolicyOptions(),
        ]);
    }

    public function store(StoreFolderRequest $request)
    {
        $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('dms.folders.index')->with('success', 'Folder created.');
    }

    public function edit(Folder $folder): Response
    {
        return Inertia::render('DMS/Folders/Edit', [
            'folder' => [
                'id' => $folder->id,
                'name' => $folder->name,
                'parent_folder_id' => $folder->parent_folder_id,
                'default_doc_type_id' => $folder->default_doc_type_id,
                'default_retention_policy_id' => $folder->default_retention_policy_id,
                'access_flag' => $folder->access_flag,
            ],
            'parents' => $this->parentOptions($folder->id),
            'docTypes' => DocType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'retentionPolicies' => $this->retentionPolicyOptions(),
        ]);
    }

    public function update(UpdateFolderRequest $request, Folder $folder)
    {
        $this->service->update($folder, $request->validated());

        return redirect()->route('dms.folders.index')->with('success', 'Folder updated.');
    }

    public function destroy(Folder $folder)
    {
        $this->service->delete($folder);

        return redirect()->route('dms.folders.index')->with('success', 'Folder deleted.');
    }

    /** Depth-first order with a `depth` attribute set on each Folder, for the indented flat listing. */
    private function indent(Collection $folders): Collection
    {
        $byParent = $folders->groupBy('parent_folder_id');
        $ordered = collect();

        $walk = function (?int $parentId, int $depth) use (&$walk, &$ordered, $byParent) {
            foreach ($byParent->get($parentId) ?? [] as $folder) {
                $folder->depth = $depth;
                $ordered->push($folder);
                $walk($folder->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $ordered;
    }

    /** Flat, depth-indented options excluding $excludeFolderId's own subtree (prevents parent cycles). */
    private function parentOptions(?int $excludeFolderId = null): array
    {
        $folders = Folder::query()->orderBy('name')->get(['id', 'name', 'parent_folder_id']);
        $excludeIds = $excludeFolderId ? $this->subtreeIds($folders, $excludeFolderId) : [];

        $byParent = $folders->groupBy('parent_folder_id');
        $flatten = function (?int $parentId, int $depth) use (&$flatten, $byParent, $excludeIds) {
            return ($byParent->get($parentId) ?? collect())
                ->reject(fn (Folder $f) => in_array($f->id, $excludeIds, true))
                ->flatMap(fn (Folder $f) => collect([
                    ['value' => $f->id, 'label' => str_repeat('— ', $depth).$f->name],
                ])->concat($flatten($f->id, $depth + 1)));
        };

        return $flatten(null, 0)->values()->all();
    }

    /** @return list<int> $rootId and every descendant id, via a plain BFS over an already-loaded collection. */
    private function subtreeIds(Collection $folders, int $rootId): array
    {
        $ids = [$rootId];
        $queue = [$rootId];

        while ($queue) {
            $current = array_pop($queue);
            foreach ($folders->where('parent_folder_id', $current) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    private function retentionPolicyOptions(): array
    {
        return RetentionPolicy::query()
            ->where('is_active', true)
            ->with('docType:id,name')
            ->get()
            ->map(fn (RetentionPolicy $p) => [
                'value' => $p->id,
                'label' => "{$p->docType?->name} — {$p->retention_period_days}d ({$p->action_on_expiry})",
            ])
            ->values()
            ->all();
    }
}
