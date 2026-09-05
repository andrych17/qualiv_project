<?php

namespace Tests\Feature\DMS;

use App\Modules\DMS\Models\AccessLog;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3C Version History Viewer — re-upload creates a new immutable version, restore is non-destructive, inline file streaming. */
class DocumentVersionTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_admin_can_upload_a_new_version_and_it_becomes_current(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $documentId = $this->makeDocument()->id;
        });

        $this->post("/dms/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->create('v2.pdf', 20, 'application/pdf'),
            'version_note' => 'Fixed a typo.',
        ])->assertRedirect(route('dms.documents.edit', $documentId));

        $tenant->run(function () use ($documentId) {
            $document = Document::query()->find($documentId);
            $this->assertSame(2, DocumentVersion::query()->where('document_id', $documentId)->count());

            $current = $document->currentVersion;
            $this->assertSame(2, $current->version_no);
            $this->assertSame('Fixed a typo.', $current->version_note);
            Storage::disk('objects')->assertExists($current->storage_key);

            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_VERSION_UPLOAD)->count());
        });
    }

    public function test_store_version_validation_rejects_missing_or_disallowed_file(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $documentId = $this->makeDocument()->id;
        });

        $this->post("/dms/documents/{$documentId}/versions", [])->assertSessionHasErrors(['file']);

        $this->post("/dms/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors(['file']);
    }

    public function test_admin_can_view_version_history_and_restore_an_old_version(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        [$documentId, $v1Id] = [null, null];
        $tenant->run(function () use (&$documentId, &$v1Id) {
            $document = $this->makeDocument();
            $documentId = $document->id;
            $v1Id = $document->current_version_id;
        });

        $this->post("/dms/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->create('v2.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $this->get("/dms/documents/{$documentId}/versions")->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Documents/Versions')
                ->has('versions', 2)
                ->where('versions.0.is_current', true)
                ->where('versions.0.version_no', 2)
                ->where('versions.1.is_current', false));

        $this->post("/dms/documents/{$documentId}/versions/{$v1Id}/restore")->assertRedirect(route('dms.documents.versions', $documentId));

        $tenant->run(function () use ($documentId, $v1Id) {
            $document = Document::query()->find($documentId);
            $this->assertSame(3, DocumentVersion::query()->where('document_id', $documentId)->count());

            $current = $document->currentVersion;
            $this->assertSame(3, $current->version_no);
            $this->assertSame('Restored from v1', $current->version_note);

            $original = DocumentVersion::query()->find($v1Id);
            $this->assertSame($original->storage_key, $current->storage_key);
            $this->assertSame($original->checksum_sha256, $current->checksum_sha256);

            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_RESTORE)->count());
        });
    }

    public function test_restore_rejects_a_version_belonging_to_a_different_document(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        [$documentAId, $otherVersionId] = [null, null];
        $tenant->run(function () use (&$documentAId, &$otherVersionId) {
            $documentAId = $this->makeDocument()->id;
            $otherVersionId = $this->makeDocument()->current_version_id;
        });

        $this->post("/dms/documents/{$documentAId}/versions/{$otherVersionId}/restore")->assertNotFound();
    }

    public function test_admin_can_download_a_version_file(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsDmsAdmin();

        $versionId = null;
        $tenant->run(function () use (&$versionId) {
            $document = $this->makeDocument();
            $versionId = $document->current_version_id;
            Storage::disk('objects')->put($document->currentVersion->storage_key, 'fake pdf bytes');
        });

        $download = $this->get(route('dms.versions.file', $versionId));
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertSame('fake pdf bytes', $download->streamedContent());

        $tenant->run(function () use ($versionId) {
            $version = DocumentVersion::query()->find($versionId);
            $this->assertSame(1, AccessLog::query()->where('document_version_id', $version->id)->where('action', AccessLog::ACTION_DOWNLOAD)->count());
        });
    }
}
