<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\IssueAttachment;
use App\Modules\Projects\Models\IssueComment;
use App\Modules\Projects\Services\IssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpProjects;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D — threaded comments and file attachments on an issue. */
class IssueCommentAttachmentTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProjects;
    use SetsUpTenant;

    public function test_admin_can_comment_on_an_issue_and_delete_the_comment(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $issueId = null;
        $tenant->run(function () use (&$projectId, &$issueId) {
            $project = $this->makeProject();
            $projectId = $project->id;
            $issueId = $this->makeIssue($project)->id;
        });

        $this->post("/projects/{$projectId}/issues/{$issueId}/comments", ['body' => 'Looks good to me.'])
            ->assertRedirect();

        // Eager-loads IssueComment::user() — a fresh Edit page visit with zero comments
        // (as every other test's does) never touches that relation.
        $this->get("/projects/{$projectId}/issues/{$issueId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('comments', 1)->where('comments.0.author', 'Admin User'));

        $commentId = null;
        $tenant->run(function () use (&$commentId, $issueId) {
            $comment = IssueComment::query()->where('issue_id', $issueId)->first();
            $this->assertSame('Looks good to me.', $comment->body);
            $commentId = $comment->id;
        });

        $this->delete("/projects/{$projectId}/issues/{$issueId}/comments/{$commentId}")->assertRedirect();
        $tenant->run(function () use ($commentId) {
            $this->assertNull(IssueComment::query()->find($commentId));
        });
    }

    public function test_store_comment_validation_rejects_a_missing_body(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $issueId = null;
        $tenant->run(function () use (&$projectId, &$issueId) {
            $project = $this->makeProject();
            $projectId = $project->id;
            $issueId = $this->makeIssue($project)->id;
        });

        $this->post("/projects/{$projectId}/issues/{$issueId}/comments", [])->assertSessionHasErrors(['body']);
    }

    public function test_admin_can_upload_preview_download_and_delete_an_attachment(): void
    {
        Storage::fake('objects');

        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $issueId = null;
        $tenant->run(function () use (&$projectId, &$issueId) {
            $project = $this->makeProject();
            $projectId = $project->id;
            $issueId = $this->makeIssue($project)->id;
        });

        // ->create() with an explicit mime type, not ->image() — this host has no GD extension.
        $file = UploadedFile::fake()->create('screenshot.png', 50, 'image/png');

        $this->post("/projects/{$projectId}/issues/{$issueId}/attachments", ['file' => $file])
            ->assertRedirect();

        $attachmentId = null;
        $tenant->run(function () use (&$attachmentId, $issueId, $projectId) {
            $attachment = IssueAttachment::query()->where('issue_id', $issueId)->first();
            $this->assertNotNull($attachment);
            $this->assertSame('screenshot.png', $attachment->original_name);
            $this->assertStringContainsString("projects/{$projectId}/{$issueId}/", $attachment->storage_key);
            $this->assertTrue($attachment->isPreviewable());
            Storage::disk('objects')->assertExists($attachment->storage_key);
            $attachmentId = $attachment->id;
        });

        $this->get("/projects/{$projectId}/issues/{$issueId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('attachments', 1)
                ->where('attachments.0.previewable', true)
                ->where('attachments.0.original_name', 'screenshot.png'));

        $download = $this->get("/projects/{$projectId}/issues/{$issueId}/attachments/{$attachmentId}/download");
        $download->assertOk();
        $download->assertHeader('Content-Disposition');
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        // A StreamedResponse's callback only actually runs once its content is pulled —
        // assertOk() alone never invokes it. Empty is correct here: UploadedFile::fake()->create()
        // reports a fake size for validation but never writes real bytes to the underlying tmpfile().
        $this->assertSame('', $download->streamedContent());

        $this->delete("/projects/{$projectId}/issues/{$issueId}/attachments/{$attachmentId}")->assertRedirect();
        $tenant->run(function () use ($attachmentId) {
            $this->assertNull(IssueAttachment::query()->find($attachmentId));
        });
    }

    public function test_a_non_image_attachment_is_not_previewable(): void
    {
        Storage::fake('objects');

        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $issueId = null;
        $tenant->run(function () use (&$projectId, &$issueId) {
            $project = $this->makeProject();
            $projectId = $project->id;
            $issueId = $this->makeIssue($project)->id;
        });

        $this->post("/projects/{$projectId}/issues/{$issueId}/attachments", [
            'file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $tenant->run(function () use ($issueId) {
            $attachment = IssueAttachment::query()->where('issue_id', $issueId)->first();
            $this->assertFalse($attachment->isPreviewable());
        });
    }

    public function test_attachment_actions_reject_cross_issue_access(): void
    {
        Storage::fake('objects');

        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $issueAId = null;
        $issueBId = null;
        $attachmentId = null;
        $tenant->run(function () use (&$projectId, &$issueAId, &$issueBId, &$attachmentId) {
            $project = $this->makeProject();
            $projectId = $project->id;
            $issueA = $this->makeIssue($project, 'Issue A');
            $issueBId = $this->makeIssue($project, 'Issue B')->id;
            $issueAId = $issueA->id;

            $attachmentId = app(IssueService::class)->attachFile(
                $issueA,
                UploadedFile::fake()->create('doc.txt', 10, 'text/plain'),
                User::query()->first()->id,
            )->id;
        });

        $this->get("/projects/{$projectId}/issues/{$issueBId}/attachments/{$attachmentId}/download")->assertNotFound();
        $this->delete("/projects/{$projectId}/issues/{$issueBId}/attachments/{$attachmentId}")->assertNotFound();

        // The genuinely owning issue can still act on it.
        $this->get("/projects/{$projectId}/issues/{$issueAId}/attachments/{$attachmentId}/download")->assertOk();
    }

    public function test_store_attachment_validation_rejects_oversized_or_disallowed_files(): void
    {
        Storage::fake('objects');

        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $issueId = null;
        $tenant->run(function () use (&$projectId, &$issueId) {
            $project = $this->makeProject();
            $projectId = $project->id;
            $issueId = $this->makeIssue($project)->id;
        });

        $tooBigKb = (IssueService::maxAttachmentBytes() / 1024) + 10;

        $this->post("/projects/{$projectId}/issues/{$issueId}/attachments", [
            'file' => UploadedFile::fake()->create('huge.pdf', (int) $tooBigKb, 'application/pdf'),
        ])->assertSessionHasErrors(['file']);

        $this->post("/projects/{$projectId}/issues/{$issueId}/attachments", [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors(['file']);

        $this->post("/projects/{$projectId}/issues/{$issueId}/attachments", [])->assertSessionHasErrors(['file']);
    }
}
