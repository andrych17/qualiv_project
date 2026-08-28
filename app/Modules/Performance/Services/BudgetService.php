<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\BudgetLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3B Budgeting — draft CRUD plus the status ladder (draft → submitted → approved → locked).
 * Only a draft budget's header/lines can be mutated directly; `createNewVersion()` is the sole
 * way to change a submitted/approved/locked budget's numbers, per §3B's "append-only history
 * for audit" rule.
 *
 * §3B also says submit "optionally fires `WorkflowRequested`… if the tenant wants manager
 * sign-off" — no `WorkflowRequested` event exists anywhere in WNE yet, and no module in this
 * codebase currently dispatches a workflow by `workflow_code` (checked before writing this).
 * `submit()` below implements the real status transition only; wiring the WNE dispatch is left
 * as an explicit, documented gap, same posture as Inventory Adjustment's own
 * `STATUS_PENDING_APPROVAL` (see AdjustmentService).
 */
class BudgetService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Budget
    {
        return DB::transaction(function () use ($data) {
            $budget = Budget::query()->create([
                ...$this->headerAttributes($data),
                'status' => Budget::STATUS_DRAFT,
                'version_no' => 1,
                'prior_version_id' => null,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($budget, $data['lines'] ?? []);

            return $budget->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Budget $budget, array $data): Budget
    {
        $this->assertDraft($budget);

        return DB::transaction(function () use ($budget, $data) {
            $budget->update($this->headerAttributes($data));
            $this->syncLines($budget, $data['lines'] ?? []);

            return $budget->refresh()->load('lines');
        });
    }

    public function delete(Budget $budget): void
    {
        $this->assertDraft($budget);
        $budget->delete();
    }

    public function submit(Budget $budget): Budget
    {
        $this->assertStatus($budget, Budget::STATUS_DRAFT, 'submitted');

        if ($budget->lines()->count() === 0) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before submitting.']);
        }

        $budget->update(['status' => Budget::STATUS_SUBMITTED]);

        return $budget->refresh();
    }

    public function approve(Budget $budget): Budget
    {
        $this->assertStatus($budget, Budget::STATUS_SUBMITTED, 'approved');
        $budget->update(['status' => Budget::STATUS_APPROVED]);

        return $budget->refresh();
    }

    public function lock(Budget $budget): Budget
    {
        $this->assertStatus($budget, Budget::STATUS_APPROVED, 'locked');
        $budget->update(['status' => Budget::STATUS_LOCKED]);

        return $budget->refresh();
    }

    /**
     * Clones the header (as a fresh draft, version_no + 1, pointing back via
     * `prior_version_id`) and lines. Deliberately does NOT clone `budget_actuals` — actuals are
     * facts recorded against a specific line's history, not part of the plan being revised; the
     * new version starts with no actuals until re-entered against its own lines.
     */
    public function createNewVersion(Budget $budget): Budget
    {
        if (! in_array($budget->status, [Budget::STATUS_APPROVED, Budget::STATUS_LOCKED], true)) {
            throw ValidationException::withMessages(['status' => 'Only an approved or locked budget can be revised into a new version.']);
        }

        return DB::transaction(function () use ($budget) {
            $newVersion = Budget::query()->create([
                'name' => $budget->name,
                'subject_type' => $budget->subject_type,
                'subject_id' => $budget->subject_id,
                'fiscal_year' => $budget->fiscal_year,
                'fiscal_quarter' => $budget->fiscal_quarter,
                'status' => Budget::STATUS_DRAFT,
                'owner_id' => $budget->owner_id,
                'version_no' => $budget->version_no + 1,
                'prior_version_id' => $budget->id,
                'notes' => $budget->notes,
                'created_by' => auth()->id(),
            ]);

            foreach ($budget->lines as $line) {
                BudgetLine::query()->create([
                    'budget_id' => $newVersion->id,
                    'category' => $line->category,
                    'period_id' => $line->period_id,
                    'amount_planned' => $line->amount_planned,
                    'notes' => $line->notes,
                ]);
            }

            return $newVersion->load('lines');
        });
    }

    private function assertDraft(Budget $budget): void
    {
        if ($budget->status !== Budget::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'This budget is no longer a draft and can only be revised via a new version.']);
        }
    }

    private function assertStatus(Budget $budget, string $expected, string $transitionLabel): void
    {
        if ($budget->status !== $expected) {
            throw ValidationException::withMessages(['status' => "This budget must be {$expected} before it can be {$transitionLabel}."]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function headerAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_type'] === Budget::SUBJECT_COMPANY ? null : $data['subject_id'],
            'fiscal_year' => $data['fiscal_year'],
            'fiscal_quarter' => $data['fiscal_quarter'] ?? null,
            'owner_id' => $data['owner_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function syncLines(Budget $budget, array $lines): void
    {
        $budget->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['category']) || empty($line['period_id']) || ! isset($line['amount_planned'])) {
                continue;
            }

            BudgetLine::query()->create([
                'budget_id' => $budget->id,
                'category' => $line['category'],
                'period_id' => $line['period_id'],
                'amount_planned' => $line['amount_planned'],
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }
}
