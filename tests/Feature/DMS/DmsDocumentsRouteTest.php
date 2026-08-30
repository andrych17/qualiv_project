<?php

namespace Tests\Feature\DMS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Regression: SysConfigSeeder's DMS_DOCUMENTS menu (/dms/documents) had no GET route —
 * only the POST store route matched that URI, so visiting the sidebar entry threw
 * MethodNotAllowedHttpException. dms.documents.index now routes to the same
 * DocumentController::index() action as dms.dashboard (DMS/Routes/web.php).
 */
class DmsDocumentsRouteTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_dms_documents_index_route_is_reachable(): void
    {
        $tenant = $this->provisionTenant('dms_01');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/dms/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Dashboard/Index'));

        // Same underlying action — both names must keep resolving to it.
        $this->get('/dms/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Dashboard/Index'));
    }

    /**
     * Regression: DMS_CATEGORIES (SysConfigSeeder) pointed at /dms/categories, a URL with no
     * route — "categories" in DMS are Folders (FolderController, dms.folders.* -> /dms/folders).
     */
    public function test_dms_folders_index_route_is_reachable(): void
    {
        $tenant = $this->provisionTenant('dms_02');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/dms/folders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/Folders/Index'));
    }
}
