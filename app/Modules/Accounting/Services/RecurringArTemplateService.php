<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\RecurringArTemplate;
use App\Modules\Accounting\Models\RecurringArTemplateLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** §3P — recurring AR invoice template CRUD (e.g. a monthly retainer); RecurringGenerationService is what actually drafts an ArInvoice from these. */
class RecurringArTemplateService
{
    public function __construct(private readonly RecurrenceService $recurrence) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, revenue_account_id:int}>  $lines
     */
    public function create(array $header, array $lines, int $userId): RecurringArTemplate
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $template = RecurringArTemplate::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'next_run_date' => $this->computeNextRunDate($header['recurrence_rule'], $header['anchor_date'], null),
                'created_by' => $userId,
            ]);

            $this->replaceLines($template, $lines);

            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_ar_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'after_snapshot' => $template->toArray(),
            ]);

            return $template->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, revenue_account_id:int}>  $lines
     */
    public function update(RecurringArTemplate $template, array $header, array $lines, int $userId): RecurringArTemplate
    {
        return DB::transaction(function () use ($template, $header, $lines, $userId) {
            $before = $template->toArray();

            $anchor = $header['anchor_date'] ?? $template->anchor_date->toDateString();
            $rule = $header['recurrence_rule'] ?? $template->recurrence_rule;
            $header['next_run_date'] = $this->computeNextRunDate($rule, $anchor, $template->last_run_date?->toDateString());

            $template->update($header);
            $this->replaceLines($template, $lines);

            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_ar_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'before_snapshot' => $before,
                'after_snapshot' => $template->toArray(),
            ]);

            return $template->refresh();
        });
    }

    public function delete(RecurringArTemplate $template, int $userId): void
    {
        DB::transaction(function () use ($template, $userId) {
            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_ar_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'before_snapshot' => $template->toArray(),
            ]);

            $template->delete();
        });
    }

    public function setActive(RecurringArTemplate $template, bool $active, int $userId): RecurringArTemplate
    {
        return DB::transaction(function () use ($template, $active, $userId) {
            $before = $template->toArray();
            $template->update(['is_active' => $active]);

            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_ar_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'before_snapshot' => $before,
                'after_snapshot' => $template->toArray(),
            ]);

            return $template->refresh();
        });
    }

    /** Same exclusive-after semantics as RecurringJournalTemplateService::computeNextRunDate() — see that docblock. */
    private function computeNextRunDate(string $rrule, string $anchor, ?string $lastRunDate): ?string
    {
        $anchorDate = Carbon::parse($anchor)->startOfDay();
        $searchFrom = $lastRunDate ? Carbon::parse($lastRunDate)->startOfDay() : $anchorDate->copy()->subDay();

        return $this->recurrence->nextOccurrenceAfter($rrule, $anchorDate, $searchFrom)?->toDateString();
    }

    /** @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, revenue_account_id:int}>  $lines */
    private function replaceLines(RecurringArTemplate $template, array $lines): void
    {
        if (empty($lines)) {
            throw ValidationException::withMessages(['lines' => 'A recurring invoice template needs at least one line.']);
        }

        $template->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            RecurringArTemplateLine::query()->create([
                'recurring_ar_template_id' => $template->id,
                'line_no' => $i + 1,
                'description' => $line['description'],
                'qty' => $line['qty'] ?? 1,
                'unit_price' => $line['unit_price'],
                'discount_amount' => $line['discount_amount'] ?? 0,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'revenue_account_id' => $line['revenue_account_id'],
            ]);
        }
    }
}
