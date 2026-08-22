<?php

namespace App\Modules\DMS\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\DMS\Events\DocumentUploaded;
use App\Modules\DMS\Models\AccessLog;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\DocumentRelation;
use App\Modules\DMS\Models\DocumentVersion;
use App\Modules\DMS\Models\RetentionPolicy;
use App\Modules\DMS\Models\Tag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * §3B Document Entry — upload (new document + first version) and metadata edit, both wired
 * to CUSTOMFIELDS the same way MatterService wires Legal Matters (§4 customization ladder
 * rung 3). §3C's version restore also lives here (restoreVersion()) since it's the same
 * "document + versions" transactional unit. §3F's retention scheduler is a later build.
 */
class DocumentService
{
    private const UPLOAD_MAX_MB = 25;

    /** @var list<string> */
    private const UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt',
    ];

    public function __construct(private readonly CustomFieldService $customFields) {}

    /** @param  array<string, mixed>  $data */
    public function upload(UploadedFile $file, array $data, int $userId): Document
    {
        $custom = $this->customFields->validateAndNormalize(Document::CUSTOM_FIELD_ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($file, $data, $custom, $userId) {
            $document = Document::query()->create([
                'uuid' => (string) Str::uuid(),
                'folder_id' => $data['folder_id'] ?? null,
                'doc_type_id' => $data['doc_type_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'status' => Document::STATUS_ACTIVE,
                'effective_date' => $data['effective_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'retention_policy_id' => $data['retention_policy_id'] ?? $this->defaultRetentionPolicyId($data['doc_type_id'] ?? null),
            ]);

            $version = $this->storeVersion($document, $file, $userId, 1);
            $document->update(['current_version_id' => $version->id]);

            $this->syncTags($document, $this->splitTags($data['tags'] ?? ''));
            $this->customFields->sync(Document::CUSTOM_FIELD_ENTITY, $document->id, $custom);

            AccessLog::record([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'action' => AccessLog::ACTION_UPLOAD,
                'actor_id' => $userId,
            ]);

            $document = $document->fresh();
            DocumentUploaded::dispatch($document, $version, true);

            return $document;
        });
    }

    /** §3B Edit — metadata only, never touches versions/files. */
    public function updateMetadata(Document $document, array $data, int $userId): Document
    {
        $custom = $this->customFields->validateAndNormalize(Document::CUSTOM_FIELD_ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($document, $data, $custom, $userId) {
            $document->update([
                'folder_id' => $data['folder_id'] ?? null,
                'doc_type_id' => $data['doc_type_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'effective_date' => $data['effective_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'retention_policy_id' => $data['retention_policy_id'] ?? $this->defaultRetentionPolicyId($data['doc_type_id'] ?? null),
            ]);

            $this->syncTags($document, $this->splitTags($data['tags'] ?? ''));
            $this->customFields->sync(Document::CUSTOM_FIELD_ENTITY, $document->id, $custom);

            AccessLog::record([
                'document_id' => $document->id,
                'document_version_id' => $document->current_version_id,
                'action' => AccessLog::ACTION_EDIT_METADATA,
                'actor_id' => $userId,
            ]);

            return $document->fresh();
        });
    }

    public function uploadNewVersion(Document $document, UploadedFile $file, int $userId, ?string $note = null): DocumentVersion
    {
        return DB::transaction(function () use ($document, $file, $userId, $note) {
            $nextVersionNo = ($document->versions()->max('version_no') ?? 0) + 1;
            $version = $this->storeVersion($document, $file, $userId, $nextVersionNo, $note);

            $document->update(['current_version_id' => $version->id]);

            AccessLog::record([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'action' => AccessLog::ACTION_VERSION_UPLOAD,
                'actor_id' => $userId,
            ]);

            DocumentUploaded::dispatch($document->fresh(), $version, false);

            return $version;
        });
    }

    /**
     * §3C "restoring creates a NEW version pointing at the old file — history is never
     * destructive." No new bytes are written to storage: the new row reuses $sourceVersion's
     * storage_key/checksum, since the old file content is exactly what's being restored.
     * Deliberately does not dispatch DocumentUploaded — no new content exists for OCR/auto-tag
     * to re-process, only a version-pointer change.
     */
    public function restoreVersion(Document $document, DocumentVersion $sourceVersion, int $userId): DocumentVersion
    {
        return DB::transaction(function () use ($document, $sourceVersion, $userId) {
            $nextVersionNo = ($document->versions()->max('version_no') ?? 0) + 1;

            $version = DocumentVersion::query()->create([
                'document_id' => $document->id,
                'version_no' => $nextVersionNo,
                'original_filename' => $sourceVersion->original_filename,
                'checksum_sha256' => $sourceVersion->checksum_sha256,
                'storage_key' => $sourceVersion->storage_key,
                'file_size_bytes' => $sourceVersion->file_size_bytes,
                'mime_type' => $sourceVersion->mime_type,
                'version_note' => "Restored from v{$sourceVersion->version_no}",
                'uploaded_by' => $userId,
            ]);

            $document->update(['current_version_id' => $version->id]);

            AccessLog::record([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'action' => AccessLog::ACTION_RESTORE,
                'actor_id' => $userId,
            ]);

            return $version;
        });
    }

    /**
     * §3H Object Relation Engine. $document is always the relation's source — "this document
     * {relationType} target" (e.g. "this supersedes target"). The read side already shows both
     * directions (DocumentController::show() merges relationsFrom + relationsTo), so no reverse
     * row is needed here.
     */
    public function addRelation(Document $document, int $targetDocumentId, string $relationType): DocumentRelation
    {
        return DocumentRelation::query()->create([
            'source_document_id' => $document->id,
            'target_document_id' => $targetDocumentId,
            'relation_type' => $relationType,
        ]);
    }

    public function removeRelation(DocumentRelation $relation): void
    {
        $relation->delete();
    }

    /** @return list<string> trimmed, deduped tag names, split from a comma-separated free-tag string (§3B MVP, no controlled vocabulary yet). */
    private function splitTags(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $t) => trim($t))
            ->filter()
            ->unique(fn (string $t) => Str::lower($t))
            ->values()
            ->all();
    }

    /** @param  list<string>  $tagNames */
    private function syncTags(Document $document, array $tagNames): void
    {
        $tagIds = collect($tagNames)->map(
            fn (string $name) => Tag::query()->firstOrCreate(['name' => $name])->id,
        )->all();

        $document->tags()->sync($tagIds);
    }

    /** §3B "retention policy defaults from doc_type, overridable" — first active policy for that doc_type. */
    private function defaultRetentionPolicyId(?int $docTypeId): ?int
    {
        if ($docTypeId === null) {
            return null;
        }

        return RetentionPolicy::query()->where('doc_type_id', $docTypeId)->where('is_active', true)->value('id');
    }

    private function storeVersion(Document $document, UploadedFile $file, int $userId, int $versionNo, ?string $note = null): DocumentVersion
    {
        // Object key layout per CLAUDE.md §7B; tenant segment follows the precedent already
        // shipped in Projects\Services\IssueService (bare tenant id, not "tenant_{id}").
        $module = $document->subject_type ? strtoupper(explode('.', $document->subject_type)[0]) : 'DMS';
        $dir = sprintf(
            '%s/DMS/%s/%s/%s',
            tenant()?->id ?? 'local',
            $module,
            now()->format('Y'),
            now()->format('m'),
        );
        $ext = $file->extension() ?: $file->getClientOriginalExtension();
        $key = "{$dir}/{$document->uuid}/v{$versionNo}.{$ext}";

        Storage::disk('objects')->writeStream($key, fopen($file->getRealPath(), 'rb'));

        return DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version_no' => $versionNo,
            'original_filename' => $file->getClientOriginalName(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
            'storage_key' => $key,
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'version_note' => $note,
            'uploaded_by' => $userId,
        ]);
    }

    public static function maxUploadBytes(): int
    {
        return self::UPLOAD_MAX_MB * 1024 * 1024;
    }

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return self::UPLOAD_EXTENSIONS;
    }
}
