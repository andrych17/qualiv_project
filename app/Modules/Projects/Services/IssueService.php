<?php

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\IssueAttachment;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IssueService
{
    private const ATTACHMENT_MAX_MB = 20;

    /** @var list<string> */
    private const ATTACHMENT_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt',
    ];

    /** @param  array<string, mixed>  $data */
    public function create(Project $project, array $data): Issue
    {
        return DB::transaction(function () use ($project, $data) {
            // Row-locked per-project sequence (PRJ-1, PRJ-2, ...) so two concurrent
            // creates on the same project can't allocate the same issue number.
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $seq = $locked->next_issue_seq;
            $locked->update(['next_issue_seq' => $seq + 1]);

            $data['project_id'] = $project->id;
            $data['code'] = "{$locked->code}-{$seq}";

            return Issue::query()->create($data);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Issue $issue, array $data): Issue
    {
        $issue->update($data);

        return $issue->refresh();
    }

    public function updateStatus(Issue $issue, string $status): Issue
    {
        $issue->update(['status' => $status]);

        return $issue->refresh();
    }

    public function updateAssignee(Issue $issue, ?int $assigneeId): Issue
    {
        $issue->update(['assignee_id' => $assigneeId]);

        return $issue->refresh();
    }

    public function delete(Issue $issue): void
    {
        $issue->delete();
    }

    /**
     * Store an uploaded file on the object disk under
     * "{tenant}/projects/{project}/{issue}/{uuid}.{ext}" (CLAUDE.md §7B object layout),
     * then record the row. File content is streamed — the request stays small even
     * for large attachments.
     */
    public function attachFile(Issue $issue, UploadedFile $file, int $userId): IssueAttachment
    {
        $disk = Storage::disk('objects');
        $dir = sprintf(
            '%s/projects/%s/%s',
            tenant()?->id ?? 'local',
            $issue->project_id,
            $issue->id,
        );
        $name = Str::uuid().'.'.$file->extension();

        $disk->writeStream("$dir/$name", fopen($file->getRealPath(), 'rb'));

        return IssueAttachment::query()->create([
            'issue_id' => $issue->id,
            'user_id' => $userId,
            'original_name' => $file->getClientOriginalName(),
            'storage_key' => "$dir/$name",
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    public function deleteAttachment(IssueAttachment $attachment): void
    {
        // Files live on a shared server but rows are per-tenant — always delete the
        // row regardless, and best-effort remove the file (orphaned files are inert).
        Storage::disk('objects')->delete($attachment->storage_key);
        $attachment->delete();
    }

    public function download(IssueAttachment $attachment): StreamedResponse
    {
        return response()->streamDownload(function () use ($attachment) {
            // fpassthru streams from the SFTP buffer — no full file in PHP memory.
            $stream = Storage::disk('objects')->readStream($attachment->storage_key);
            if ($stream !== false) {
                fpassthru($stream);
            }
        }, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
            // Client-supplied MIME is reflected here — stop browsers from sniffing it
            // into something executable (e.g. an .svg upload rendered as image/svg+xml).
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function maxAttachmentBytes(): int
    {
        return self::ATTACHMENT_MAX_MB * 1024 * 1024;
    }

    /** @return list<string> */
    public static function allowedAttachmentExtensions(): array
    {
        return self::ATTACHMENT_EXTENSIONS;
    }
}
