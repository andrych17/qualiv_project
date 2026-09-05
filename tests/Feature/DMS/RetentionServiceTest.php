<?php

namespace Tests\Feature\DMS;

use App\Models\User;
use App\Modules\DMS\Models\AccessLog;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\RetentionPolicy;
use App\Modules\DMS\Services\RetentionService;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F Retention & Lifecycle Engine — the daily sweep: notify/archive/delete-request actions, legal-hold blocking. */
class RetentionServiceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_expired_document_with_no_policy_defaults_to_notify_only(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $documentId = $this->makeDocument(['expiry_date' => now()->subDay()->toDateString()])->id;
        });

        $summary = $tenant->run(fn () => app(RetentionService::class)->runDailySweep());

        $this->assertSame(['notified' => 1, 'archived' => 0, 'delete_requested' => 0, 'held' => 0], $summary);

        $tenant->run(function () use ($documentId) {
            $this->assertSame(Document::STATUS_EXPIRED, Document::query()->find($documentId)->status);
            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_EXPIRED)->count());
        });

        Event::assertDispatched(NotificationRequested::class);
    }

    public function test_expired_document_with_archive_policy_is_archived(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $policy = $this->makeRetentionPolicy(null, ['action_on_expiry' => RetentionPolicy::ACTION_ARCHIVE]);
            $documentId = $this->makeDocument([
                'expiry_date' => now()->subDay()->toDateString(),
                'retention_policy_id' => $policy->id,
            ])->id;
        });

        $summary = $tenant->run(fn () => app(RetentionService::class)->runDailySweep());
        $this->assertSame(1, $summary['archived']);

        $tenant->run(function () use ($documentId) {
            $this->assertSame(Document::STATUS_ARCHIVED, Document::query()->find($documentId)->status);
            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_ARCHIVED)->count());
        });
    }

    public function test_expired_document_with_delete_policy_requests_approval_and_falls_back_to_notify(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $policy = $this->makeRetentionPolicy(null, ['action_on_expiry' => RetentionPolicy::ACTION_DELETE]);
            $documentId = $this->makeDocument([
                'expiry_date' => now()->subDay()->toDateString(),
                'retention_policy_id' => $policy->id,
            ])->id;
        });

        // No 'dms.retention_delete_approval' workflow definition exists in a fresh tenant, so
        // WorkflowService::start() throws and the sweep degrades to a notification instead.
        $summary = $tenant->run(fn () => app(RetentionService::class)->runDailySweep());
        $this->assertSame(1, $summary['delete_requested']);

        $tenant->run(function () use ($documentId) {
            $document = Document::query()->find($documentId);
            $this->assertSame(Document::STATUS_EXPIRED, $document->status);
            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_DELETE_REQUESTED)->count());
        });

        Event::assertDispatched(NotificationRequested::class);
    }

    public function test_legal_hold_blocks_the_scheduled_action_by_default(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $documentId = $this->makeDocument([
                'expiry_date' => now()->subDay()->toDateString(),
                'legal_hold' => true,
            ])->id;
        });

        $summary = $tenant->run(fn () => app(RetentionService::class)->runDailySweep());
        $this->assertSame(['notified' => 0, 'archived' => 0, 'delete_requested' => 0, 'held' => 1], $summary);

        $tenant->run(function () use ($documentId) {
            $document = Document::query()->find($documentId);
            $this->assertSame(Document::STATUS_ACTIVE, $document->status);
            $this->assertSame(1, AccessLog::query()->where('document_id', $documentId)->where('action', AccessLog::ACTION_HOLD_BLOCKED)->count());
        });

        Event::assertNotDispatched(NotificationRequested::class);
    }

    public function test_legal_hold_overridable_false_lets_the_action_proceed(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $documentId = null;
        $tenant->run(function () use (&$documentId) {
            $policy = $this->makeRetentionPolicy(null, ['legal_hold_overridable' => false]);
            $documentId = $this->makeDocument([
                'expiry_date' => now()->subDay()->toDateString(),
                'retention_policy_id' => $policy->id,
                'legal_hold' => true,
            ])->id;
        });

        $summary = $tenant->run(fn () => app(RetentionService::class)->runDailySweep());
        $this->assertSame(1, $summary['notified']);
        $this->assertSame(0, $summary['held']);
    }

    public function test_sweep_via_console_command(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $this->makeDocument(['expiry_date' => now()->subDay()->toDateString()]);
        });

        $tenant->run(function () {
            $this->artisan('dms:apply-retention-policies')->assertSuccessful();
        });
    }

    public function test_sweep_ignores_documents_not_yet_expired_or_already_inactive(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $this->makeDocument(['title' => 'Not expired yet', 'expiry_date' => now()->addDays(10)->toDateString()]);
            $this->makeDocument(['title' => 'No expiry date']);
            $this->makeDocument(['title' => 'Already archived', 'status' => Document::STATUS_ARCHIVED, 'expiry_date' => now()->subDay()->toDateString()]);
        });

        $summary = $tenant->run(fn () => app(RetentionService::class)->runDailySweep());
        $this->assertSame(['notified' => 0, 'archived' => 0, 'delete_requested' => 0, 'held' => 0], $summary);
    }

    public function test_notification_targets_the_versions_uploader_when_known(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $uploaderId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $this->makeDocument([
                'expiry_date' => now()->subDay()->toDateString(),
                'version' => ['uploaded_by' => $uploaderId],
            ]);
        });

        $tenant->run(fn () => app(RetentionService::class)->runDailySweep());

        Event::assertDispatched(NotificationRequested::class, function (NotificationRequested $event) {
            return $event->recipient['type'] === 'user';
        });
    }
}
