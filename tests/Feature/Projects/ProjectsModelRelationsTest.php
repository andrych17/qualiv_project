<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Models\IssueAttachment;
use App\Modules\Projects\Models\IssueComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpProjects;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Inverse relations (child -> parent) that no controller ever navigates — see CrmModelRelationsTest / ScheduleModelRelationsTest for the same pattern. */
class ProjectsModelRelationsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProjects;
    use SetsUpTenant;

    public function test_inverse_relations_resolve_to_their_owning_records(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $reporter = User::query()->first();
            $project = $this->makeProject();
            $issue = $this->makeIssue($project, attrs: ['reporter_id' => $reporter->id]);
            $comment = IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $reporter->id, 'body' => 'x']);
            $attachment = IssueAttachment::query()->create([
                'issue_id' => $issue->id, 'user_id' => $reporter->id,
                'original_name' => 'x.txt', 'storage_key' => 'x/x.txt', 'mime_type' => 'text/plain', 'size' => 1,
            ]);

            $this->assertSame($project->id, $issue->project->id);
            $this->assertSame($reporter->id, $issue->reporter->id);
            $this->assertSame($issue->id, $comment->issue->id);
            $this->assertSame($issue->id, $attachment->issue->id);
        });
    }
}
