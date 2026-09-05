<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Modules\DMS\Models\DocType;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\DocumentVersion;
use App\Modules\DMS\Models\Folder;
use App\Modules\DMS\Models\RetentionPolicy;
use App\Modules\DMS\Models\Tag;
use Illuminate\Support\Str;

/** Shared bootstrap for DMS module tests — plan activation, admin login, and fixtures. */
trait SetsUpDMS
{
    protected function loginAsDmsAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makeDocType(string $code = 'CONTRACT', string $name = 'Contract'): DocType
    {
        return DocType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
    }

    protected function makeRetentionPolicy(?DocType $docType = null, array $attrs = []): RetentionPolicy
    {
        return RetentionPolicy::query()->create([
            'doc_type_id' => $attrs['doc_type_id'] ?? ($docType ?? $this->makeDocType())->id,
            'retention_period_days' => $attrs['retention_period_days'] ?? 365,
            'action_on_expiry' => $attrs['action_on_expiry'] ?? RetentionPolicy::ACTION_NOTIFY_ONLY,
            'legal_hold_overridable' => $attrs['legal_hold_overridable'] ?? true,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeFolder(string $name = 'Contracts', array $attrs = []): Folder
    {
        return Folder::query()->create([
            'name' => $name,
            'parent_folder_id' => $attrs['parent_folder_id'] ?? null,
            'default_doc_type_id' => $attrs['default_doc_type_id'] ?? null,
            'default_retention_policy_id' => $attrs['default_retention_policy_id'] ?? null,
            'access_flag' => $attrs['access_flag'] ?? Folder::ACCESS_TENANT,
            'created_by' => $attrs['created_by'] ?? null,
        ]);
    }

    protected function makeTag(string $name = 'urgent'): Tag
    {
        return Tag::query()->firstOrCreate(['name' => $name]);
    }

    /** Direct DB fixture (bypasses DocumentService::upload()/real storage) — document + its v1 version. */
    protected function makeDocument(array $attrs = []): Document
    {
        $document = Document::query()->create([
            'uuid' => (string) Str::uuid(),
            'folder_id' => $attrs['folder_id'] ?? null,
            'doc_type_id' => $attrs['doc_type_id'] ?? null,
            'title' => $attrs['title'] ?? 'Untitled Document',
            'description' => $attrs['description'] ?? null,
            'subject_type' => $attrs['subject_type'] ?? null,
            'subject_id' => $attrs['subject_id'] ?? null,
            'status' => $attrs['status'] ?? Document::STATUS_ACTIVE,
            'effective_date' => $attrs['effective_date'] ?? null,
            'expiry_date' => $attrs['expiry_date'] ?? null,
            'retention_policy_id' => $attrs['retention_policy_id'] ?? null,
            'legal_hold' => $attrs['legal_hold'] ?? false,
        ]);

        if (! ($attrs['skip_version'] ?? false)) {
            $version = $this->makeDocumentVersion($document, $attrs['version'] ?? []);
            $document->update(['current_version_id' => $version->id]);
            $document->refresh();
        }

        return $document;
    }

    protected function makeDocumentVersion(Document $document, array $attrs = []): DocumentVersion
    {
        $versionNo = $attrs['version_no'] ?? (($document->versions()->max('version_no') ?? 0) + 1);

        return DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version_no' => $versionNo,
            'original_filename' => $attrs['original_filename'] ?? 'file.pdf',
            'checksum_sha256' => $attrs['checksum_sha256'] ?? hash('sha256', 'fixture-'.$document->uuid.'-'.$versionNo),
            'storage_key' => $attrs['storage_key'] ?? "fixture/{$document->uuid}/v{$versionNo}.pdf",
            'file_size_bytes' => $attrs['file_size_bytes'] ?? 1024,
            'mime_type' => $attrs['mime_type'] ?? 'application/pdf',
            'version_note' => $attrs['version_note'] ?? null,
            'uploaded_by' => $attrs['uploaded_by'] ?? null,
        ]);
    }
}
