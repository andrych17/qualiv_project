<?php

namespace Tests\Feature\DMS;

use App\Modules\DMS\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D — Folder / Category Management: tree CRUD, cycle-prevention on move, non-empty-folder delete guard. */
class FolderTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_admin_can_crud_a_folder(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $this->get('/dms/folders')->assertOk()->assertInertia(fn ($page) => $page->component('DMS/Folders/Index'));
        $this->get('/dms/folders/create')->assertOk()->assertInertia(fn ($page) => $page->component('DMS/Folders/Create'));

        $this->post('/dms/folders', [
            'name' => 'Contracts',
            'access_flag' => Folder::ACCESS_TENANT,
        ])->assertRedirect(route('dms.folders.index'));

        $folderId = null;
        $tenant->run(function () use (&$folderId) {
            $folderId = Folder::query()->where('name', 'Contracts')->value('id');
        });

        $this->get("/dms/folders/{$folderId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Folders/Edit')->where('folder.name', 'Contracts'));

        $this->put("/dms/folders/{$folderId}", [
            'name' => 'Contracts (renamed)',
            'access_flag' => Folder::ACCESS_PRIVATE,
        ])->assertRedirect(route('dms.folders.index'));

        $tenant->run(function () use ($folderId) {
            $folder = Folder::query()->find($folderId);
            $this->assertSame('Contracts (renamed)', $folder->name);
            $this->assertSame(Folder::ACCESS_PRIVATE, $folder->access_flag);
        });

        $this->delete("/dms/folders/{$folderId}")->assertRedirect(route('dms.folders.index'));
        $tenant->run(function () use ($folderId) {
            $this->assertNull(Folder::query()->find($folderId));
        });
    }

    public function test_folder_index_shows_indented_tree_with_depth(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $parent = $this->makeFolder('Parent');
            $this->makeFolder('Child', ['parent_folder_id' => $parent->id]);
        });

        $this->get('/dms/folders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('folders', 2)
                ->where('folders.0.name', 'Parent')->where('folders.0.depth', 0)
                ->where('folders.1.name', 'Child')->where('folders.1.depth', 1)->where('folders.1.parent_name', 'Parent'));
    }

    public function test_create_and_edit_pages_expose_parent_options_excluding_own_subtree(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$rootId, $childId] = [null, null];
        $tenant->run(function () use (&$rootId, &$childId) {
            $root = $this->makeFolder('Root');
            $rootId = $root->id;
            $childId = $this->makeFolder('Child', ['parent_folder_id' => $root->id])->id;
        });

        $this->get('/dms/folders/create')->assertOk()
            ->assertInertia(fn ($page) => $page->has('parents', 2));

        // Editing "Root" must not offer itself or its own descendant ("Child") as a parent option.
        $this->get("/dms/folders/{$rootId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->has('parents', 0));

        $this->get("/dms/folders/{$childId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->has('parents', 1));
    }

    public function test_store_rejects_invalid_parent_doc_type_and_retention_policy(): void
    {
        $this->loginAsDmsAdmin();

        $this->post('/dms/folders', [
            'name' => 'Bad Folder',
            'access_flag' => Folder::ACCESS_TENANT,
            'parent_folder_id' => 999999,
            'default_doc_type_id' => 999999,
            'default_retention_policy_id' => 999999,
        ])->assertSessionHasErrors(['parent_folder_id', 'default_doc_type_id', 'default_retention_policy_id']);
    }

    public function test_store_rejects_invalid_access_flag(): void
    {
        $this->loginAsDmsAdmin();

        $this->post('/dms/folders', ['name' => 'Bad Flag', 'access_flag' => 'public'])
            ->assertSessionHasErrors(['access_flag']);
    }

    public function test_update_rejects_self_as_parent(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $folderId = null;
        $tenant->run(function () use (&$folderId) {
            $folderId = $this->makeFolder()->id;
        });

        $this->put("/dms/folders/{$folderId}", [
            'name' => 'Self Parent',
            'access_flag' => Folder::ACCESS_TENANT,
            'parent_folder_id' => $folderId,
        ])->assertSessionHasErrors(['parent_folder_id']);
    }

    public function test_update_rejects_invalid_doc_type_retention_policy_and_parent(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $folderId = null;
        $tenant->run(function () use (&$folderId) {
            $folderId = $this->makeFolder()->id;
        });

        $this->put("/dms/folders/{$folderId}", [
            'name' => 'Bad Refs',
            'access_flag' => Folder::ACCESS_TENANT,
            'default_doc_type_id' => 999999,
            'default_retention_policy_id' => 999999,
            'parent_folder_id' => 999999,
        ])->assertSessionHasErrors(['default_doc_type_id', 'default_retention_policy_id', 'parent_folder_id']);
    }

    public function test_update_rejects_moving_under_own_descendant(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$grandparentId, $parentId, $childId] = [null, null, null];
        $tenant->run(function () use (&$grandparentId, &$parentId, &$childId) {
            $grandparent = $this->makeFolder('Grandparent');
            $grandparentId = $grandparent->id;
            $parent = $this->makeFolder('Parent', ['parent_folder_id' => $grandparent->id]);
            $parentId = $parent->id;
            $childId = $this->makeFolder('Child', ['parent_folder_id' => $parent->id])->id;
        });

        // Moving "Grandparent" under its own descendant "Child" must be rejected (would cycle).
        $this->put("/dms/folders/{$grandparentId}", [
            'name' => 'Grandparent',
            'access_flag' => Folder::ACCESS_TENANT,
            'parent_folder_id' => $childId,
        ])->assertSessionHasErrors(['parent_folder_id']);

        // A non-cyclic move (Parent -> directly under nothing) is fine.
        $this->put("/dms/folders/{$parentId}", [
            'name' => 'Parent',
            'access_flag' => Folder::ACCESS_TENANT,
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_delete_is_blocked_when_folder_has_documents_or_children(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$withDocsId, $withChildId] = [null, null];
        $tenant->run(function () use (&$withDocsId, &$withChildId) {
            $withDocs = $this->makeFolder('Has Docs');
            $withDocsId = $withDocs->id;
            $this->makeDocument(['folder_id' => $withDocs->id]);

            $withChild = $this->makeFolder('Has Child');
            $withChildId = $withChild->id;
            $this->makeFolder('Sub', ['parent_folder_id' => $withChild->id]);
        });

        $this->delete("/dms/folders/{$withDocsId}")->assertSessionHasErrors(['folder']);
        $this->delete("/dms/folders/{$withChildId}")->assertSessionHasErrors(['folder']);
    }
}
