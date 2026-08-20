<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\ServiceCaseActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): ServiceCase
    {
        $data['priority'] ??= 'normal';

        return DB::transaction(function () use ($data) {
            $case = ServiceCase::query()->create([...$data, 'status' => ServiceCase::STATUS_OPEN]);
            $this->logActivity($case, 'note', 'Case opened.');

            return $case;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ServiceCase $case, array $data): ServiceCase
    {
        $case->update($data);

        return $case->refresh();
    }

    /**
     * §3E: closing is final for reporting but reopenable within a grace window
     * (default 7 days, ServiceCase::REOPEN_GRACE_DAYS) — past that, the case
     * stays closed and a new one should be filed instead.
     */
    public function updateStatus(ServiceCase $case, string $newStatus): ServiceCase
    {
        if ($case->status === ServiceCase::STATUS_CLOSED && $newStatus !== ServiceCase::STATUS_CLOSED && ! $case->canReopen()) {
            throw ValidationException::withMessages([
                'status' => 'This case closed more than '.ServiceCase::REOPEN_GRACE_DAYS.' days ago — open a new case instead.',
            ]);
        }

        $oldStatus = $case->status;

        if ($newStatus === ServiceCase::STATUS_CLOSED) {
            $closedAt = now();
        } elseif ($case->status === ServiceCase::STATUS_CLOSED) {
            $closedAt = null; // reopening
        } else {
            $closedAt = $case->closed_at;
        }

        $case->update(['status' => $newStatus, 'closed_at' => $closedAt]);
        $this->logActivity($case, 'status_change', "Status changed: {$oldStatus} → {$newStatus}");

        return $case;
    }

    public function addNote(ServiceCase $case, string $body): ServiceCaseActivity
    {
        return $this->logActivity($case, 'note', $body);
    }

    private function logActivity(ServiceCase $case, string $activityType, ?string $body): ServiceCaseActivity
    {
        return ServiceCaseActivity::query()->create([
            'case_id' => $case->id,
            'activity_type' => $activityType,
            'body' => $body,
            'logged_by' => auth()->id(),
            'logged_at' => now(),
        ]);
    }
}
