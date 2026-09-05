<?php

namespace Tests\Feature\DMS;

use App\Modules\DMS\Models\AccessLog;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3B Document Entry — upload (new document + v1), metadata edit, tag syncing, validation. */
class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_admin_can_upload_a_document_with_tags_and_metadata(): void
    {
        Storage::fake('objects');

        $tenant = $this->loginAsDmsAdmin();

        [$folderId, $docTypeId, $retentionPolicyId] = [null, null, null];
        $tenant->run(function () use (&$folderId, &$docTypeId, &$retentionPolicyId) {
            $folderId = $this->makeFolder()->id;
            $docType = $this->makeDocType();
            $docTypeId = $docType->id;
            $retentionPolicyId = $this->makeRetentionPolicy($docType)->id;
        });

        $this->get('/dms/documents/create')->assertOk()->assertInertia(fn ($page) => $page->component('DMS/Documents/Create'));

        $response = $this->post('/dms/documents', [
            'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
            'title' => 'Master Services Agreement',
            'description' => 'Signed MSA with vendor.',
            'folder_id' => $folderId,
            'doc_type_id' => $docTypeId,
            'tags' => 'legal, vendor, legal',
            'retention_policy_id' => $retentionPolicyId,
        ]);

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $documentId = Document::query()->where('title', 'Master Services Agreement')->value('id');
        });
        $response->assertRedirect(route('dms.documents.edit', $documentId));

        $tenant->run(function () use ($documentId) {
            $document = Document::query()->find($documentId);
            $this->assertSame(Document::STATUS_ACTIVE, $document->status);
            $this->assertNotNull($document->current_version_id);
            $this->assertNotNull($document->uuid);

            $version = $document->currentVersion;
            $this->assertSame('contract.pdf', $version->original_filename);
            $this->assertSame(1, $version->version_no);
            $this->assertNotEmpty($version->checksum_sha256);
            Storage::disk('objects')->assertExists($version->storage_key);

            // Duplicate "legal" tag collapses to a single row.
            $this->assertSame(['legal', 'vendor'], $document->tags->pluck('name')->all());

            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_UPLOAD)->count());
        });
    }

    public function test_upload_without_folder_or_doc_type_still_succeeds(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        $this->post('/dms/documents', [
            'file' => UploadedFile::fake()->create('note.txt', 5, 'text/plain'),
            'title' => 'Standalone Note',
        ])->assertSessionDoesntHaveErrors();

        $tenant->run(function () {
            $document = Document::query()->where('title', 'Standalone Note')->first();
            $this->assertNull($document->folder_id);
            $this->assertNull($document->doc_type_id);
        });
    }

    public function test_store_validation_rejects_missing_title_oversized_and_disallowed_files(): void
    {
        Storage::fake('objects');
        $this->loginAsDmsAdmin();

        $this->post('/dms/documents', [
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors(['title']);

        $tooBigKb = (DocumentService::maxUploadBytes() / 1024) + 10;
        $this->post('/dms/documents', [
            'file' => UploadedFile::fake()->create('huge.pdf', (int) $tooBigKb, 'application/pdf'),
            'title' => 'Too Big',
        ])->assertSessionHasErrors(['file']);

        $this->post('/dms/documents', [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            'title' => 'Bad Type',
        ])->assertSessionHasErrors(['file']);

        $this->post('/dms/documents', [])->assertSessionHasErrors(['file', 'title']);
    }

    public function test_store_rejects_invalid_folder_doc_type_and_retention_policy(): void
    {
        Storage::fake('objects');
        $this->loginAsDmsAdmin();

        $this->post('/dms/documents', [
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'title' => 'Invalid Refs',
            'folder_id' => 999999,
            'doc_type_id' => 999999,
            'retention_policy_id' => 999999,
        ])->assertSessionHasErrors(['folder_id', 'doc_type_id', 'retention_policy_id']);
    }

    public function test_upload_uses_doc_types_default_retention_policy_when_none_given(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        [$docTypeId, $policyId] = [null, null];
        $tenant->run(function () use (&$docTypeId, &$policyId) {
            $docType = $this->makeDocType();
            $docTypeId = $docType->id;
            $policyId = $this->makeRetentionPolicy($docType)->id;
        });

        $this->post('/dms/documents', [
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'title' => 'Auto Policy',
            'doc_type_id' => $docTypeId,
        ])->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($policyId) {
            $document = Document::query()->where('title', 'Auto Policy')->first();
            $this->assertSame($policyId, $document->retention_policy_id);
        });
    }

    public function test_admin_can_update_document_metadata_and_tags(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $document = $this->makeDocument(['title' => 'Original Title']);
            $document->tags()->sync([$this->makeTag('draft')->id]);
            $documentId = $document->id;
        });

        $this->get("/dms/documents/{$documentId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Documents/Edit')
                ->where('document.title', 'Original Title')->where('document.tags', 'draft'));

        $this->put("/dms/documents/{$documentId}", [
            'title' => 'Updated Title',
            'description' => 'Now with a description.',
            'tags' => 'final, signed',
        ])->assertRedirect(route('dms.documents.edit', $documentId));

        $tenant->run(function () use ($documentId) {
            $document = Document::query()->find($documentId);
            $this->assertSame('Updated Title', $document->title);
            $this->assertSame(['final', 'signed'], $document->tags->pluck('name')->all());
            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_EDIT_METADATA)->count());
        });
    }

    public function test_update_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $documentId = $this->makeDocument()->id;
        });

        $this->put("/dms/documents/{$documentId}", [
            'title' => 'Bad Refs',
            'folder_id' => 999999,
            'doc_type_id' => 999999,
            'retention_policy_id' => 999999,
        ])->assertSessionHasErrors(['folder_id', 'doc_type_id', 'retention_policy_id']);
    }
}
