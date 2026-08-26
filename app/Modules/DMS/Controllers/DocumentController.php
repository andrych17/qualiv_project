<?php

namespace App\Modules\DMS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\DMS\Models\AccessLog;
use App\Modules\DMS\Models\DocType;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\DocumentRelation;
use App\Modules\DMS\Models\DocumentVersion;
use App\Modules\DMS\Models\Folder;
use App\Modules\DMS\Models\RetentionPolicy;
use App\Modules\DMS\Requests\StoreDocumentRelationRequest;
use App\Modules\DMS\Requests\StoreDocumentRequest;
use App\Modules\DMS\Requests\StoreDocumentVersionRequest;
use App\Modules\DMS\Requests\UpdateDocumentRequest;
use App\Modules\DMS\Services\DocumentService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §3A Main Dashboard (Document Library) — folder tree + filterable document table +
 * upload + row-click drawer (metadata/versions/audit/relations tabs). Preview and
 * folder CRUD (§3D) are later pages; this is the "browse, upload, drill in" front door.
 */
class DocumentController extends Controller
{
    private const SORTABLE = ['title', 'created_at', 'expiry_date'];

    public function __construct(
        private readonly DocumentService $service,
        private readonly CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $filters = $request->only('search', 'folder_id', 'doc_type_id', 'status', 'flag', 'sort', 'direction', 'per_page');
        $searchTerm = $filters['search'] ?? null;

        $documents = Document::query()
            // §3E ranked results: select() must run before withCount()/filter() add their own
            // columns, so this stays the query's one explicit column list rather than colliding
            // with a later bare "*" (Eloquent drops the implicit "*" the moment any select runs).
            ->when($searchTerm, fn ($query) => $query->select('DMS.documents.*')
                ->selectRaw("ts_rank(search_vector, plainto_tsquery('simple', ?)) as search_rank", [$searchTerm]))
            ->with(['folder:id,name', 'docType:id,name', 'currentVersion:id,document_id,original_filename,file_size_bytes,uploaded_at'])
            ->withCount('versions')
            ->where(fn ($q) => $q->whereHas('folder', fn ($q2) => $q2->accessibleTo($userId))->orWhereNull('folder_id'))
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc'),
                fn ($query) => $searchTerm ? $query->orderByDesc('search_rank') : $query->orderByDesc('created_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 25))
            ->withQueryString()
            ->through(fn (Document $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'folder_name' => $d->folder?->name,
                'doc_type_name' => $d->docType?->name,
                'status' => $d->status,
                'rail' => $d->rail,
                'legal_hold' => $d->legal_hold,
                'version_count' => $d->versions_count,
                'current_filename' => $d->currentVersion?->original_filename,
                'current_size_bytes' => $d->currentVersion?->file_size_bytes,
                'subject_type' => $d->subject_type,
                'subject_id' => $d->subject_id,
                'expiry_date_formatted' => $d->expiry_date?->format('d M Y'),
                'created_at_formatted' => $d->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('DMS/Dashboard/Index', [
            'documents' => $documents,
            'filters' => $filters,
            'summary' => [
                'total_documents' => Document::query()->accessibleTo($userId)->count(),
                'expiring_soon' => Document::query()->accessibleTo($userId)->filter(['flag' => 'expiring_soon'])->count(),
                'on_legal_hold' => Document::query()->accessibleTo($userId)->where('legal_hold', true)->count(),
                'active_documents' => Document::query()->accessibleTo($userId)->where('status', Document::STATUS_ACTIVE)->count(),
            ],
            'folders' => $this->folderTree($userId),
            'docTypes' => DocType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** §3B Document Entry — upload form (file, folder, doc type, tags, custom fields, dates, retention). */
    public function create(Request $request): Response
    {
        $userId = $request->user()->id;

        return Inertia::render('DMS/Documents/Create', [
            'folders' => $this->folderOptions($userId),
            'docTypes' => DocType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'retentionPolicies' => $this->retentionPolicyOptions(),
            'customFields' => $this->customFields->formPayload(Document::CUSTOM_FIELD_ENTITY),
            'selectedFolderId' => $request->integer('folder_id') ?: null,
        ]);
    }

    public function store(StoreDocumentRequest $request)
    {
        $document = $this->service->upload($request->file('file'), $request->validated(), $request->user()->id);

        return redirect()->route('dms.documents.edit', $document)->with('success', 'Document uploaded.');
    }

    /** §3B Edit — metadata form, pre-filled; re-upload is a separate action on the same page. */
    public function edit(Document $document): Response
    {
        $this->assertAccessible($document, (int) auth()->id());
        $document->load(['tags:id,name', 'currentVersion']);

        return Inertia::render('DMS/Documents/Edit', [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'description' => $document->description,
                'folder_id' => $document->folder_id,
                'doc_type_id' => $document->doc_type_id,
                'tags' => $document->tags->pluck('name')->implode(', '),
                'subject_type' => $document->subject_type,
                'subject_id' => $document->subject_id,
                'effective_date' => $document->effective_date?->format('Y-m-d'),
                'expiry_date' => $document->expiry_date?->format('Y-m-d'),
                'retention_policy_id' => $document->retention_policy_id,
                'status' => $document->status,
                'current_filename' => $document->currentVersion?->original_filename,
            ],
            'folders' => $this->folderOptions(auth()->id()),
            'docTypes' => DocType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'retentionPolicies' => $this->retentionPolicyOptions(),
            'customFields' => $this->customFields->formPayload(Document::CUSTOM_FIELD_ENTITY, $document->id),
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document)
    {
        $this->assertAccessible($document, $request->user()->id);
        $this->service->updateMetadata($document, $request->validated(), $request->user()->id);

        return redirect()->route('dms.documents.edit', $document)->with('success', 'Document updated.');
    }

    /** §3B re-upload — a new immutable version, kept separate from the metadata form above. */
    public function storeVersion(StoreDocumentVersionRequest $request, Document $document)
    {
        $this->assertAccessible($document, $request->user()->id);
        $this->service->uploadNewVersion($document, $request->file('file'), $request->user()->id, $request->validated('version_note'));

        return redirect()->route('dms.documents.edit', $document)->with('success', 'New version uploaded.');
    }

    /** §3C Version History Viewer — full list (uploader, timestamp, size, checksum, note) + restore/compare. */
    public function versions(Document $document): Response
    {
        $this->assertAccessible($document, (int) auth()->id());
        $document->load('versions.uploadedBy:id,name');

        return Inertia::render('DMS/Documents/Versions', [
            'document' => ['id' => $document->id, 'title' => $document->title],
            'versions' => $document->versions->map(fn (DocumentVersion $v) => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'original_filename' => $v->original_filename,
                'checksum_sha256' => $v->checksum_sha256,
                'file_size_bytes' => $v->file_size_bytes,
                'mime_type' => $v->mime_type,
                'file_url' => route('dms.versions.file', $v->id),
                'uploaded_by_name' => $v->uploadedBy?->name,
                'uploaded_at_formatted' => $v->uploaded_at?->format('d M Y H:i'),
                'version_note' => $v->version_note,
                'is_current' => $v->id === $document->current_version_id,
            ]),
        ]);
    }

    /** §3C restore — never destructive, always creates a new version (DocumentService::restoreVersion). */
    public function restoreVersion(Request $request, Document $document, DocumentVersion $version)
    {
        $this->assertAccessible($document, $request->user()->id);
        abort_unless($version->document_id === $document->id, 404);

        $this->service->restoreVersion($document, $version, $request->user()->id);

        return redirect()->route('dms.documents.versions', $document)->with('success', "Restored v{$version->version_no} as the current version.");
    }

    /** §3H — link {document} (as source) to another document; read side is DocumentController::show()'s 'relations' key. */
    public function storeRelation(StoreDocumentRelationRequest $request, Document $document)
    {
        $this->assertAccessible($document, $request->user()->id);
        $this->service->addRelation($document, (int) $request->validated('target_document_id'), $request->validated('relation_type'));

        return back()->with('success', 'Relation added.');
    }

    public function destroyRelation(Document $document, DocumentRelation $relation)
    {
        $this->assertAccessible($document, (int) auth()->id());
        abort_unless($relation->source_document_id === $document->id || $relation->target_document_id === $document->id, 404);

        $this->service->removeRelation($relation);

        return back()->with('success', 'Relation removed.');
    }

    /** JSON drawer payload — metadata, version history, audit trail, relations (§3A row-click drawer). */
    public function show(Request $request, Document $document)
    {
        $this->assertAccessible($document, $request->user()->id);
        $document->load([
            'folder:id,name',
            'docType:id,name',
            'tags:id,name',
            'versions.uploadedBy:id,name',
            'accessLogs.actor:id,name',
            'relationsFrom.target:id,title',
            'relationsTo.source:id,title',
        ]);

        AccessLog::record([
            'document_id' => $document->id,
            'document_version_id' => $document->current_version_id,
            'action' => AccessLog::ACTION_VIEW,
            'actor_id' => $request->user()->id,
        ]);

        return response()->json([
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'description' => $document->description,
                'folder_name' => $document->folder?->name,
                'doc_type_name' => $document->docType?->name,
                'status' => $document->status,
                'legal_hold' => $document->legal_hold,
                'subject_type' => $document->subject_type,
                'subject_id' => $document->subject_id,
                'effective_date_formatted' => $document->effective_date?->format('d M Y'),
                'expiry_date_formatted' => $document->expiry_date?->format('d M Y'),
                'tags' => $document->tags->pluck('name'),
            ],
            'versions' => $document->versions->take(5)->map(fn ($v) => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'original_filename' => $v->original_filename,
                'file_size_bytes' => $v->file_size_bytes,
                'mime_type' => $v->mime_type,
                'file_url' => route('dms.versions.file', $v->id),
                'uploaded_by_name' => $v->uploadedBy?->name,
                'uploaded_at_formatted' => $v->uploaded_at?->format('d M Y H:i'),
                'version_note' => $v->version_note,
                'is_current' => $v->id === $document->current_version_id,
            ]),
            'versionCount' => $document->versions->count(),
            // §3I: capped preview + count, same "dashboard tab links to the full page" pattern
            // as the Versions tab above — the full unbounded log lives at dms.audit-log.
            'auditLog' => $document->accessLogs->take(5)->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_name' => $log->actor?->name,
                'created_at_formatted' => $log->created_at?->format('d M Y H:i'),
            ]),
            'auditLogCount' => $document->accessLogs->count(),
            // §3H: relationsFrom/relationsTo are both Eloquent Collections; mapping to plain
            // arrays doesn't downgrade the collection class (map() keeps `static`), so a bare
            // ->merge() would hit Eloquent Collection's overridden merge() — which assumes
            // every item is still a Model and calls ->getKey() on it, crashing on a plain array.
            // Spreading into a fresh collect() sidesteps that entirely.
            'relations' => collect([
                ...$document->relationsFrom->map(fn ($r) => [
                    'id' => $r->id,
                    'relation_type' => $r->relation_type,
                    'document_title' => $r->target?->title,
                ])->all(),
                ...$document->relationsTo->map(fn ($r) => [
                    'id' => $r->id,
                    'relation_type' => $this->inverseRelationLabel($r->relation_type),
                    'document_title' => $r->source?->title,
                ])->all(),
            ]),
        ]);
    }

    /**
     * §3A quick preview panel / download fallback: streams inline (not forced attachment) so
     * a PDF/image mime renders directly in an <iframe>/<img>; other types still just download
     * via the browser's own handling of an unrenderable inline response.
     */
    public function versionFile(Request $request, DocumentVersion $version): StreamedResponse
    {
        $document = $version->document ?? Document::query()->findOrFail($version->document_id);
        $this->assertAccessible($document, $request->user()->id);

        AccessLog::record([
            'document_id' => $version->document_id,
            'document_version_id' => $version->id,
            'action' => AccessLog::ACTION_DOWNLOAD,
            'actor_id' => $request->user()->id,
        ]);

        return response()->streamDownload(function () use ($version) {
            $stream = Storage::disk('objects')->readStream($version->storage_key);
            if ($stream !== false) {
                fpassthru($stream);
            }
        }, $version->original_filename, [
            'Content-Type' => $version->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function assertAccessible(Document $document, int $userId): void
    {
        abort_unless($document->isAccessibleTo($userId), 403, 'You do not have access to this document.');
    }

    /** §3H: a relationsTo row reads backwards if shown with its literal type — "supersedes" from
     * the target's own drawer would imply the target supersedes the source, when it's the other
     * way around. Flip to the natural passive phrasing for that direction. */
    private function inverseRelationLabel(string $relationType): string
    {
        return match ($relationType) {
            DocumentRelation::TYPE_AMENDMENT_OF => 'amended_by',
            DocumentRelation::TYPE_SUPERSEDES => 'superseded_by',
            DocumentRelation::TYPE_ATTACHMENT_OF => 'has_attachment',
            default => $relationType,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function folderTree(int $userId): array
    {
        $folders = Folder::query()->accessibleTo($userId)->withCount('documents')->orderBy('name')->get();
        $byParent = $folders->groupBy('parent_folder_id');

        $build = function (?int $parentId) use (&$build, $byParent) {
            return ($byParent->get($parentId) ?? collect())->map(fn (Folder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'document_count' => $f->documents_count,
                'access_flag' => $f->access_flag,
                'children' => $build($f->id),
            ])->values()->all();
        };

        return $build(null);
    }

    /** Flat, depth-indented options for a <select> — the recursive tree in folderTree() isn't select-friendly. */
    private function folderOptions(int $userId): array
    {
        $folders = Folder::query()->accessibleTo($userId)->orderBy('name')->get(['id', 'name', 'parent_folder_id']);
        $byParent = $folders->groupBy('parent_folder_id');

        $flatten = function (?int $parentId, int $depth) use (&$flatten, $byParent) {
            return ($byParent->get($parentId) ?? collect())->flatMap(fn (Folder $f) => collect([
                ['value' => $f->id, 'label' => str_repeat('— ', $depth).$f->name],
            ])->concat($flatten($f->id, $depth + 1)));
        };

        return $flatten(null, 0)->values()->all();
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
