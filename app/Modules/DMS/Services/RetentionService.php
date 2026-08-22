<?php

namespace App\Modules\DMS\Services;

use App\Modules\DMS\Models\AccessLog;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\RetentionPolicy;
use App\Modules\WNE\Events\NotificationRequested;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Support\Facades\Log;

/**
 * §3F Retention & Lifecycle Engine — the daily sweep. Scoped to what the spec's own bullet
 * describes: reaching expiry triggers notify_only/archive/delete-approval-request, and a legal
 * hold blocks the action (logged, not silently skipped). Explicitly NOT built here (§5's own
 * MVP-scope discipline, matching how DocumentUploaded ships with no OCR listener yet):
 * - The actual "purge after grace period" hard-delete step — no grace-period column exists on
 *   retention_policies, and building one is a separate future increment.
 * - Consuming the delete-approval workflow's completion to actually purge — that's a
 *   WorkflowInstanceCompleted listener, a different piece of machinery than this sweep.
 * - Authoring the 'dms.retention_delete_approval' workflow definition itself — WNE_SPECS.md is
 *   explicit that definitions are admin-authored via the builder UI, not seeded by callers.
 */
class RetentionService
{
    public function __construct(private readonly WorkflowService $workflows) {}

    /** @return array{notified: int, archived: int, delete_requested: int, held: int} */
    public function runDailySweep(): array
    {
        $summary = ['notified' => 0, 'archived' => 0, 'delete_requested' => 0, 'held' => 0];

        Document::query()
            ->where('status', Document::STATUS_ACTIVE)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->toDateString())
            ->with(['retentionPolicy', 'currentVersion'])
            ->each(function (Document $document) use (&$summary) {
                $policy = $document->retentionPolicy;

                // §4 storage note: legal_hold_overridable is "can a legal_hold block THIS
                // policy's scheduled action" — a policy that opts out (false) runs its action
                // regardless of hold. No policy at all defaults to the conservative reading:
                // an unqualified hold always blocks.
                $holdBlocks = $document->legal_hold && ($policy === null || $policy->legal_hold_overridable);
                if ($holdBlocks) {
                    $this->log($document, AccessLog::ACTION_HOLD_BLOCKED);
                    $summary['held']++;

                    return;
                }

                $action = $policy?->action_on_expiry ?? RetentionPolicy::ACTION_NOTIFY_ONLY;

                match ($action) {
                    RetentionPolicy::ACTION_ARCHIVE => $this->archive($document, $summary),
                    RetentionPolicy::ACTION_DELETE => $this->requestDeleteApproval($document, $summary),
                    default => $this->notifyOnly($document, $summary),
                };
            });

        return $summary;
    }

    private function notifyOnly(Document $document, array &$summary): void
    {
        $document->update(['status' => Document::STATUS_EXPIRED]);
        $this->log($document, AccessLog::ACTION_EXPIRED);
        $this->notify($document, 'expired', 'has expired and is awaiting review');
        $summary['notified']++;
    }

    private function archive(Document $document, array &$summary): void
    {
        $document->update(['status' => Document::STATUS_ARCHIVED]);
        $this->log($document, AccessLog::ACTION_ARCHIVED);
        $this->notify($document, 'archived', 'reached its retention period and was archived automatically');
        $summary['archived']++;
    }

    /** §3F: "so a reviewer confirms before destructive action" — never auto-deletes. */
    private function requestDeleteApproval(Document $document, array &$summary): void
    {
        $document->update(['status' => Document::STATUS_EXPIRED]);
        $this->log($document, AccessLog::ACTION_DELETE_REQUESTED);

        try {
            $this->workflows->start(
                'dms.retention_delete_approval',
                'dms.documents',
                $document->id,
                ['document_id' => $document->id, 'title' => $document->title],
            );
        } catch (WorkflowEngineException $e) {
            // Not configured yet (no published 'dms.retention_delete_approval' definition) —
            // degrade to a notification so the expiry isn't silently unactionable, per §5's
            // "surface a clear admin-facing error" contract for an unconfigured workflow code.
            Log::warning('DMS retention: delete-approval workflow unavailable, falling back to notification.', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            $this->notify($document, 'expired', 'is due for deletion but no approval workflow is configured — review manually');
        }

        $summary['delete_requested']++;
    }

    private function notify(Document $document, string $verb, string $bodyVerb): void
    {
        $recipient = $document->currentVersion?->uploaded_by
            ? ['type' => 'user', 'user_id' => $document->currentVersion->uploaded_by]
            : ['type' => 'role', 'role' => 'ADMIN'];

        NotificationRequested::dispatch(
            'dms.retention_expiry',
            $recipient,
            ['document_id' => $document->id, 'status' => $verb],
            'dms.documents',
            $document->id,
            "Document \"{$document->title}\" {$verb}",
            "\"{$document->title}\" {$bodyVerb} (expiry date: {$document->expiry_date->toDateString()}).",
        );
    }

    private function log(Document $document, string $action): void
    {
        AccessLog::record([
            'document_id' => $document->id,
            'document_version_id' => $document->current_version_id,
            'action' => $action,
            'actor_id' => null,
        ]);
    }
}
