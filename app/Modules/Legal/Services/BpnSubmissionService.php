<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use RuntimeException;

class BpnSubmissionService
{
    public function createPending(Deed $deed, string $submissionType): BpnSubmission
    {
        return BpnSubmission::query()->create([
            'deed_id' => $deed->id,
            'submission_type' => $submissionType,
            'pnbp_amount' => $deed->transaction_value ? BpnSubmission::calculatePnbp((float) $deed->transaction_value) : null,
            'status' => BpnSubmission::STATUS_PREPARED,
        ]);
    }

    public function submit(BpnSubmission $submission, string $trackingNumber, ?string $submittedAt = null): BpnSubmission
    {
        if ($submission->status !== BpnSubmission::STATUS_PREPARED) {
            throw new RuntimeException('Only a prepared submission can be submitted.');
        }

        $submission->update([
            'tracking_number' => $trackingNumber,
            'submitted_at' => $submittedAt ?? now()->toDateString(),
            'status' => BpnSubmission::STATUS_SUBMITTED,
        ]);

        return $submission->refresh();
    }

    public function markInProcess(BpnSubmission $submission): BpnSubmission
    {
        if ($submission->status !== BpnSubmission::STATUS_SUBMITTED) {
            throw new RuntimeException('Only a submitted submission can move to in-process.');
        }

        $submission->update(['status' => BpnSubmission::STATUS_IN_PROCESS]);

        return $submission->refresh();
    }

    public function complete(BpnSubmission $submission): BpnSubmission
    {
        if (! in_array($submission->status, [BpnSubmission::STATUS_SUBMITTED, BpnSubmission::STATUS_IN_PROCESS], true)) {
            throw new RuntimeException('Only a submitted or in-process submission can be completed.');
        }

        $submission->update(['status' => BpnSubmission::STATUS_COMPLETED, 'completed_at' => now()->toDateString()]);

        return $submission->refresh();
    }

    public function reject(BpnSubmission $submission, string $reason): BpnSubmission
    {
        if (! in_array($submission->status, [BpnSubmission::STATUS_SUBMITTED, BpnSubmission::STATUS_IN_PROCESS], true)) {
            throw new RuntimeException('Only a submitted or in-process submission can be rejected.');
        }

        $submission->update(['status' => BpnSubmission::STATUS_REJECTED, 'rejection_reason' => $reason]);

        return $submission->refresh();
    }

    /** Never edits a rejected row — always a new one, chained via resubmission_of_id (§3L). */
    public function resubmit(BpnSubmission $rejected): BpnSubmission
    {
        if ($rejected->status !== BpnSubmission::STATUS_REJECTED) {
            throw new RuntimeException('Only a rejected submission can be resubmitted.');
        }

        if (BpnSubmission::query()->where('resubmission_of_id', $rejected->id)->exists()) {
            throw new RuntimeException('This submission has already been resubmitted.');
        }

        return BpnSubmission::query()->create([
            'deed_id' => $rejected->deed_id,
            'submission_type' => $rejected->submission_type,
            'pnbp_amount' => $rejected->pnbp_amount,
            'status' => BpnSubmission::STATUS_PREPARED,
            'resubmission_of_id' => $rejected->id,
        ]);
    }
}
