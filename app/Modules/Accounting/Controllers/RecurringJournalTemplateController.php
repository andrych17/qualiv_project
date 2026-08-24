<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\RecurringJournalTemplate;
use App\Modules\Accounting\Requests\StoreRecurringJournalTemplateRequest;
use App\Modules\Accounting\Requests\UpdateRecurringJournalTemplateRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\RecurrenceService;
use App\Modules\Accounting\Services\RecurringJournalTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** §3P — recurring journal templates. RecurringGenerationService (run via the scheduled sweep, not this controller) is what actually drafts a GlJournal from these. */
class RecurringJournalTemplateController extends Controller
{
    public function __construct(
        private readonly RecurringJournalTemplateService $service,
        private readonly RecurrenceService $recurrence,
        private readonly CompanyContextService $companyContext,
    ) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $templates = RecurringJournalTemplate::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return Inertia::render('Accounting/RecurringJournalTemplates/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'templates' => $templates->map(fn (RecurringJournalTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'recurrence_rule' => $t->recurrence_rule,
                'next_run_date' => $t->next_run_date?->toDateString(),
                'last_run_date' => $t->last_run_date?->toDateString(),
                'is_active' => $t->is_active,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/RecurringJournalTemplates/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            ...$this->formOptions($companyId),
        ]);
    }

    public function store(StoreRecurringJournalTemplateRequest $request)
    {
        $data = $request->validated();
        $template = $this->service->create(
            [
                'company_id' => $data['company_id'],
                'name' => $data['name'],
                'memo' => $data['memo'] ?? null,
                'currency_code' => $data['currency_code'],
                'recurrence_rule' => $data['recurrence_rule'],
                'anchor_date' => $data['anchor_date'],
            ],
            $data['lines'],
            $request->user()->id,
        );

        return redirect()->route('accounting.recurring-journal-templates.edit', $template)->with('success', 'Recurring journal template saved.');
    }

    public function edit(RecurringJournalTemplate $template): Response
    {
        $template->load('lines');

        return Inertia::render('Accounting/RecurringJournalTemplates/Edit', [
            'template' => [
                'id' => $template->id,
                'company_id' => $template->company_id,
                'name' => $template->name,
                'memo' => $template->memo,
                'currency_code' => $template->currency_code,
                'recurrence_rule' => $template->recurrence_rule,
                'anchor_date' => $template->anchor_date->toDateString(),
                'next_run_date' => $template->next_run_date?->toDateString(),
                'last_run_date' => $template->last_run_date?->toDateString(),
                'is_active' => $template->is_active,
                'lines' => $template->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'cost_center_id' => $l->cost_center_id,
                    'debit' => (float) $l->debit,
                    'credit' => (float) $l->credit,
                    'description' => $l->description,
                ]),
            ],
            'upcomingRunDates' => $this->upcomingRunDates($template->recurrence_rule, $template->anchor_date, $template->next_run_date),
            ...$this->formOptions($template->company_id),
        ]);
    }

    public function update(UpdateRecurringJournalTemplateRequest $request, RecurringJournalTemplate $template)
    {
        $data = $request->validated();
        $this->service->update(
            $template,
            [
                'name' => $data['name'],
                'memo' => $data['memo'] ?? null,
                'currency_code' => $data['currency_code'],
                'recurrence_rule' => $data['recurrence_rule'],
                'anchor_date' => $data['anchor_date'],
            ],
            $data['lines'],
            $request->user()->id,
        );

        return redirect()->route('accounting.recurring-journal-templates.edit', $template)->with('success', 'Recurring journal template updated.');
    }

    public function setActive(Request $request, RecurringJournalTemplate $template)
    {
        $this->service->setActive($template, $request->boolean('is_active'), $request->user()->id);

        return back()->with('success', $request->boolean('is_active') ? 'Template resumed.' : 'Template paused.');
    }

    public function destroy(Request $request, RecurringJournalTemplate $template)
    {
        $companyId = $template->company_id;
        $this->service->delete($template, $request->user()->id);

        return redirect()->route('accounting.recurring-journal-templates.index', ['company_id' => $companyId])->with('success', 'Recurring journal template deleted.');
    }

    /** @return list<string> */
    private function upcomingRunDates(string $rrule, Carbon $anchor, ?Carbon $from, int $count = 5): array
    {
        $dates = [];
        $cursor = $from;
        for ($i = 0; $i < $count && $cursor; $i++) {
            $dates[] = $cursor->toDateString();
            $cursor = $this->recurrence->nextOccurrenceAfter($rrule, $anchor, $cursor);
        }

        return $dates;
    }

    private function formOptions(?int $companyId): array
    {
        if (! $companyId) {
            return ['accounts' => [], 'costCenters' => [], 'currencies' => []];
        }

        return [
            'accounts' => Account::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('account_code')
                ->get(['id', 'account_code', 'account_name', 'is_control_account'])
                ->map(fn (Account $a) => [
                    'value' => $a->id,
                    'label' => "{$a->account_code} — {$a->account_name}",
                    'is_control_account' => $a->is_control_account,
                ]),
            'costCenters' => CostCenter::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (CostCenter $c) => ['value' => $c->id, 'label' => "{$c->code} {$c->name}"]),
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('code')->get(['code', 'name']),
        ];
    }
}
