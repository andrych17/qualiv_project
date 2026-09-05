<?php

namespace Tests\Feature\DMS;

use App\Models\User;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3A Document Library — filters, keyword search (Postgres tsvector), folder-level access enforcement, folder tree. */
class DocumentIndexAndAccessTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_index_lists_documents_with_summary_and_folder_tree(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $folder = $this->makeFolder('Contracts');
            $this->makeDocument(['title' => 'A', 'folder_id' => $folder->id]);
            $this->makeDocument(['title' => 'B', 'legal_hold' => true]);
        });

        $this->get('/dms/documents')->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Documents/Index')
                ->has('documents.data', 2)
                ->where('summary.total_documents', 2)
                ->where('summary.on_legal_hold', 1)
                ->where('summary.active_documents', 2)
                ->has('folders', 1)->where('folders.0.name', 'Contracts')->where('folders.0.document_count', 1));
    }

    public function test_index_filters_by_folder_doc_type_status_tag_and_flag(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$folderId, $docTypeId] = [null, null];
        $tenant->run(function () use (&$folderId, &$docTypeId) {
            $folder = $this->makeFolder();
            $folderId = $folder->id;
            $docType = $this->makeDocType();
            $docTypeId = $docType->id;

            $this->makeDocument(['title' => 'In Folder', 'folder_id' => $folderId]);
            $this->makeDocument(['title' => 'Typed', 'doc_type_id' => $docTypeId]);
            $archived = $this->makeDocument(['title' => 'Archived Doc', 'status' => Document::STATUS_ARCHIVED]);
            $tagged = $this->makeDocument(['title' => 'Tagged Doc']);
            $tagged->tags()->sync([$this->makeTag('urgent')->id]);
            $this->makeDocument(['title' => 'Expiring', 'expiry_date' => now()->addDays(10)->toDateString()]);
            $this->makeDocument(['title' => 'On Hold', 'legal_hold' => true]);
            $this->makeDocument(['title' => 'Legal Owned', 'subject_type' => 'legal.matters', 'subject_id' => 1]);
        });

        $this->get("/dms/documents?folder_id={$folderId}")->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1));
        $this->get("/dms/documents?doc_type_id={$docTypeId}")->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1));
        $this->get('/dms/documents?status='.Document::STATUS_ARCHIVED)->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1));
        $this->get('/dms/documents?tag=urgent')->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Tagged Doc'));
        $this->get('/dms/documents?flag=expiring_soon')->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1));
        $this->get('/dms/documents?flag=on_legal_hold')->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1));
        $this->get('/dms/documents?subject_type=legal.matters')->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Legal Owned'));
        // An unrecognized flag value hits the match's `default => null` no-op arm.
        $this->get('/dms/documents?flag=not_a_real_flag')->assertOk();
        $this->get('/dms/documents?sort=title&direction=asc')->assertOk();
    }

    public function test_index_full_text_search_ranks_matching_titles(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $this->makeDocument(['title' => 'Vendor Master Agreement']);
            $this->makeDocument(['title' => 'Unrelated Memo']);
        });

        $this->get('/dms/documents?search=Vendor')->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Vendor Master Agreement'));
    }

    public function test_private_folder_documents_are_hidden_from_other_users_but_visible_to_creator(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$adminId, $otherUserId, $privateDocId] = [null, null, null];
        $tenant->run(function () use (&$adminId, &$otherUserId, &$privateDocId) {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $otherUserId = User::factory()->create(['email' => 'other@nusaevo.com'])->id;

            $ownFolder = $this->makeFolder('My Private', ['access_flag' => Folder::ACCESS_PRIVATE, 'created_by' => $otherUserId]);
            $privateDocId = $this->makeDocument(['title' => 'Private Doc', 'folder_id' => $ownFolder->id])->id;

            $this->makeDocument(['title' => 'Public Doc']);
        });

        // Admin (not the private folder's creator) sees only the folderless public document.
        $this->get('/dms/documents')->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Public Doc'));

        $this->get("/dms/documents/{$privateDocId}")->assertForbidden();
    }

    public function test_own_private_folder_documents_remain_visible(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $ownFolder = $this->makeFolder('Mine', ['access_flag' => Folder::ACCESS_PRIVATE, 'created_by' => $adminId]);
            $this->makeDocument(['title' => 'My Private Doc', 'folder_id' => $ownFolder->id]);
        });

        $this->get('/dms/documents')->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1));
    }
}
