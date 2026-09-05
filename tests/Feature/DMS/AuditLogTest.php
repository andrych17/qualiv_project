<?php

namespace Tests\Feature\DMS;

use App\Models\User;
use App\Modules\DMS\Models\AccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3I Audit Trail — tenant-wide read-only log view, append-only guard on the model itself. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_audit_log_index_lists_and_filters(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$documentId, $actorId] = [null, null];
        $tenant->run(function () use (&$documentId, &$actorId) {
            $document = $this->makeDocument(['title' => 'Audited Doc']);
            $documentId = $document->id;
            $actorId = User::query()->where('email', 'admin@nusaevo.com')->value('id');

            AccessLog::record(['document_id' => $documentId, 'action' => AccessLog::ACTION_UPLOAD, 'actor_id' => $actorId]);
            AccessLog::record(['document_id' => $documentId, 'action' => AccessLog::ACTION_VIEW, 'actor_id' => $actorId]);
        });

        $this->get('/dms/audit-log')->assertOk()
            ->assertInertia(fn ($page) => $page->component('DMS/AuditLog/Index')
                ->has('logs.data', 2)->has('actions')->has('actors'));

        $this->get('/dms/audit-log?search=Audited')->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 2));
        $this->get('/dms/audit-log?action='.AccessLog::ACTION_UPLOAD)->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 1));
        $this->get("/dms/audit-log?document_id={$documentId}")->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 2));
        $this->get("/dms/audit-log?actor_id={$actorId}")->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 2));
        $this->get('/dms/audit-log?sort=created_at&direction=asc')->assertOk();
    }

    public function test_audit_log_search_matches_by_actor_name(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $document = $this->makeDocument();
            $actorId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            AccessLog::record(['document_id' => $document->id, 'action' => AccessLog::ACTION_VIEW, 'actor_id' => $actorId]);
        });

        $this->get('/dms/audit-log?search=Admin User')->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 1));
    }

    public function test_access_log_is_append_only(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $document = $this->makeDocument();
            $log = AccessLog::record(['document_id' => $document->id, 'action' => AccessLog::ACTION_VIEW]);

            $this->expectException(\LogicException::class);
            $log->update(['action' => AccessLog::ACTION_DELETE]);
        });
    }

    public function test_access_log_delete_is_blocked(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $document = $this->makeDocument();
            $log = AccessLog::record(['document_id' => $document->id, 'action' => AccessLog::ACTION_VIEW]);

            $this->expectException(\LogicException::class);
            $log->delete();
        });
    }
}
