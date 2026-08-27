<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurBudget;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Models\PurRequisitionLine;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequisitionService
{
    public function __construct(
        protected ?WorkflowService $workflowService = null,
    ) {
        $this->workflowService ??= app(WorkflowService::class);
    }

    public function generatePrNumber(): string
    {
        $prefix = 'PR-'.now()->format('Ym').'-';
        $maxAttempts = 50;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $latest = PurRequisitionHdr::query()
                ->where('pr_no', 'like', "{$prefix}%")
                ->orderByDesc('pr_no')
                ->value('pr_no');

            $nextSeq = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
                $nextSeq = ((int) $m[1]) + 1;
            }

            $candidate = $prefix.sprintf('%04d', $nextSeq + $i);
            if (! PurRequisitionHdr::query()->where('pr_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $prefix.substr(uniqid(), -4);
    }

    /**
     * §3B duplicate-PR check in MVP: same requester + same catalog item (or exact description)
     * in an open PR within 30 days.
     */
    public function checkDuplicate(int $requesterId, array $lines, ?int $ignorePrId = null): bool
    {
        $since = now()->subDays(30);

        $catalogIds = array_filter(array_column($lines, 'catalog_item_id'));
        $descriptions = array_map('trim', array_filter(array_column($lines, 'description')));

        $query = PurRequisitionHdr::query()
            ->where('requester_id', $requesterId)
            ->whereIn('status', [
                PurRequisitionHdr::STATUS_DRAFT,
                PurRequisitionHdr::STATUS_PENDING_APPROVAL,
                PurRequisitionHdr::STATUS_APPROVED,
            ])
            ->where('created_at', '>=', $since);

        if ($ignorePrId) {
            $query->where('id', '!=', $ignorePrId);
        }

        $openPrIds = $query->pluck('id');
        if ($openPrIds->isEmpty()) {
            return false;
        }

        $existingLinesQuery = PurRequisitionLine::query()->whereIn('pr_id', $openPrIds);

        $hasMatch = false;
        if (! empty($catalogIds)) {
            $hasMatch = (clone $existingLinesQuery)->whereIn('catalog_item_id', $catalogIds)->exists();
        }

        if (! $hasMatch && ! empty($descriptions)) {
            $hasMatch = (clone $existingLinesQuery)->whereIn('description', $descriptions)->exists();
        }

        return $hasMatch;
    }

    /**
     * §3B/§3F soft budget check against PURCHASE.pur_budgets (warns, does not hard-block).
     */
    public function checkBudget(?int $costCenterId, array $lines, ?string $neededBy = null): bool
    {
        if (! $costCenterId) {
            return false;
        }

        $period = Carbon::parse($neededBy ?? now())->format('Y-m');

        // Group estimated totals by category_id
        $categoryTotals = [];
        foreach ($lines as $line) {
            $catId = $line['category_id'] ?? null;
            if ($catId) {
                $lineTotal = ((float) ($line['qty'] ?? 0)) * ((float) ($line['estimated_unit_price'] ?? 0));
                $categoryTotals[$catId] = ($categoryTotals[$catId] ?? 0) + $lineTotal;
            }
        }

        foreach ($categoryTotals as $catId => $total) {
            $budget = PurBudget::query()
                ->where('period', $period)
                ->where('cost_center_id', $costCenterId)
                ->where('category_id', $catId)
                ->first();

            if ($budget) {
                $projected = (float) $budget->committed_amount + (float) $budget->actual_amount + $total;
                if ($projected > (float) $budget->budget_amount) {
                    return true;
                }
            }
        }

        return false;
    }

    public function create(array $data, int $userId): PurRequisitionHdr
    {
        return DB::transaction(function () use ($data, $userId) {
            $prNo = $data['pr_no'] ?? $this->generatePrNumber();
            $lines = $data['lines'] ?? [];

            $estimatedTotal = 0;
            foreach ($lines as $line) {
                $estimatedTotal += ((float) ($line['qty'] ?? 0)) * ((float) ($line['estimated_unit_price'] ?? 0));
            }

            $requesterId = (int) ($data['requester_id'] ?? $userId);
            $costCenterId = ! empty($data['cost_center_id']) ? (int) $data['cost_center_id'] : null;
            $neededBy = $data['needed_by'] ?? null;

            $duplicateWarning = $this->checkDuplicate($requesterId, $lines);
            $budgetWarning = $this->checkBudget($costCenterId, $lines, $neededBy);

            $pr = PurRequisitionHdr::create([
                'pr_no' => $prNo,
                'requester_id' => $requesterId,
                'cost_center_id' => $costCenterId,
                'needed_by' => $neededBy,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'status' => PurRequisitionHdr::STATUS_DRAFT,
                'estimated_total' => $estimatedTotal,
                'budget_warning' => $duplicateWarning ? true : $budgetWarning,
                'duplicate_warning' => $duplicateWarning,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $lineNo = 1;
            foreach ($lines as $line) {
                $pr->lines()->create([
                    'line_no' => $lineNo++,
                    'catalog_item_id' => $line['catalog_item_id'] ?? null,
                    'description' => $line['description'],
                    'qty' => $line['qty'],
                    'estimated_unit_price' => $line['estimated_unit_price'] ?? 0,
                    'category_id' => $line['category_id'] ?? null,
                    'local_content_pct' => $line['local_content_pct'] ?? null,
                ]);
            }

            return $pr->fresh(['lines', 'costCenter', 'requester']);
        });
    }

    public function update(PurRequisitionHdr $pr, array $data, int $userId): PurRequisitionHdr
    {
        if (! in_array($pr->status, [PurRequisitionHdr::STATUS_DRAFT, PurRequisitionHdr::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or rejected requisitions can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($pr, $data) {
            $lines = $data['lines'] ?? [];

            $estimatedTotal = 0;
            foreach ($lines as $line) {
                $estimatedTotal += ((float) ($line['qty'] ?? 0)) * ((float) ($line['estimated_unit_price'] ?? 0));
            }

            $requesterId = (int) ($data['requester_id'] ?? $pr->requester_id);
            $costCenterId = array_key_exists('cost_center_id', $data) ? ($data['cost_center_id'] ? (int) $data['cost_center_id'] : null) : $pr->cost_center_id;
            $neededBy = array_key_exists('needed_by', $data) ? $data['needed_by'] : $pr->needed_by?->toDateString();

            $duplicateWarning = $this->checkDuplicate($requesterId, $lines, $pr->id);
            $budgetWarning = $this->checkBudget($costCenterId, $lines, $neededBy);

            $pr->update([
                'requester_id' => $requesterId,
                'cost_center_id' => $costCenterId,
                'needed_by' => $neededBy,
                'subject_type' => $data['subject_type'] ?? $pr->subject_type,
                'subject_id' => $data['subject_id'] ?? $pr->subject_id,
                'estimated_total' => $estimatedTotal,
                'budget_warning' => $budgetWarning,
                'duplicate_warning' => $duplicateWarning,
                'notes' => $data['notes'] ?? $pr->notes,
            ]);

            $pr->lines()->delete();

            $lineNo = 1;
            foreach ($lines as $line) {
                $pr->lines()->create([
                    'line_no' => $lineNo++,
                    'catalog_item_id' => $line['catalog_item_id'] ?? null,
                    'description' => $line['description'],
                    'qty' => $line['qty'],
                    'estimated_unit_price' => $line['estimated_unit_price'] ?? 0,
                    'category_id' => $line['category_id'] ?? null,
                    'local_content_pct' => $line['local_content_pct'] ?? null,
                ]);
            }

            return $pr->fresh(['lines', 'costCenter', 'requester']);
        });
    }

    /**
     * Submit PR: triggers WNE workflow `purchase.pr_approval` if configured.
     */
    public function submit(PurRequisitionHdr $pr, int $userId): PurRequisitionHdr
    {
        if (! in_array($pr->status, [PurRequisitionHdr::STATUS_DRAFT, PurRequisitionHdr::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Requisition cannot be submitted from current status: '.$pr->status],
            ]);
        }

        return DB::transaction(function () use ($pr, $userId) {
            $pr->refresh();

            // Refresh warning states
            $lines = $pr->lines->toArray();
            $pr->duplicate_warning = $this->checkDuplicate($pr->requester_id, $lines, $pr->id);
            $pr->budget_warning = $this->checkBudget($pr->cost_center_id, $lines, $pr->needed_by?->toDateString());
            $pr->status = PurRequisitionHdr::STATUS_PENDING_APPROVAL;
            $pr->save();

            // Trigger WNE workflow if published
            try {
                if ($this->workflowService) {
                    $this->workflowService->start(
                        'purchase.pr_approval',
                        PurRequisitionHdr::class,
                        $pr->id,
                        [
                            'pr_no' => $pr->pr_no,
                            'requester_id' => $pr->requester_id,
                            'cost_center_id' => $pr->cost_center_id,
                            'estimated_total' => $pr->estimated_total,
                            'budget_warning' => $pr->budget_warning,
                            'duplicate_warning' => $pr->duplicate_warning,
                        ],
                        $userId
                    );
                }
            } catch (WorkflowEngineException) {
                // Standalone / default mode: pending approval without active WNE definition
            }

            return $pr;
        });
    }

    public function approve(PurRequisitionHdr $pr, int $userId): PurRequisitionHdr
    {
        if ($pr->status !== PurRequisitionHdr::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => ['Only pending approval requisitions can be approved.'],
            ]);
        }

        $pr->status = PurRequisitionHdr::STATUS_APPROVED;
        $pr->save();

        return $pr;
    }

    public function reject(PurRequisitionHdr $pr, int $userId, ?string $reason = null): PurRequisitionHdr
    {
        if ($pr->status !== PurRequisitionHdr::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => ['Only pending approval requisitions can be rejected.'],
            ]);
        }

        $pr->status = PurRequisitionHdr::STATUS_REJECTED;
        if ($reason) {
            $pr->notes = trim(($pr->notes ? $pr->notes."\n" : '').'Rejection reason: '.$reason);
        }
        $pr->save();

        return $pr;
    }

    public function cancel(PurRequisitionHdr $pr, int $userId): PurRequisitionHdr
    {
        if ($pr->status === PurRequisitionHdr::STATUS_CONVERTED) {
            throw ValidationException::withMessages([
                'status' => ['Cannot cancel a requisition that has already been converted to a Purchase Order.'],
            ]);
        }

        $pr->status = PurRequisitionHdr::STATUS_CANCELLED;
        $pr->save();

        return $pr;
    }
}
