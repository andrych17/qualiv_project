<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\RecurringArTemplate;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Requests\StoreRecurringArTemplateRequest;
use App\Modules\Accounting\Requests\UpdateRecurringArTemplateRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\RecurrenceService;
use App\Modules\Accounting\Services\RecurringArTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** §3P — recurring AR invoice templates (e.g. a monthly retainer). RecurringGenerationService (run via the scheduled sweep, not this controller) is what actually drafts an ArInvoice from these. */
class RecurringArTemplateController extends Controller
{
    public function __construct(
        private readonly RecurringArTemplateService $service,
        private readonly RecurrenceService $recurrence,
        private readonly CompanyContextService $companyContext,
    ) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $templates = RecurringArTemplate::query()
            ->where('company_id', $companyId)
            ->with('partner:id,name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Accounting/RecurringArTemplates/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'templates' => $templates->map(fn (RecurringArTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'partner_name' => $t->partner?->name,
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

        return Inertia::render('Accounting/RecurringArTemplates/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'invoiceTypes' => ArInvoice::TYPES,
            ...$this->formOptions($companyId),
        ]);
    }

    public function store(StoreRecurringArTemplateRequest $request)
    {
        $data = $request->validated();
        $template = $this->service->create(
            [
                'company_id' => $data['company_id'],
                'partner_id' => $data['partner_id'],
                'name' => $data['name'],
                'currency_code' => $data['currency_code'],
                'invoice_type' => $data['invoice_type'],
                'payment_terms_days' => $data['payment_terms_days'],
                'recurrence_rule' => $data['recurrence_rule'],
                'anchor_date' => $data['anchor_date'],
            ],
            $data['lines'],
            $request->user()->id,
        );

        return redirect()->route('accounting.recurring-ar-templates.edit', $template)->with('success', 'Recurring invoice template saved.');
    }

    public function edit(RecurringArTemplate $template): Response
    {
        $template->load(['lines', 'partner:id,name']);

        return Inertia::render('Accounting/RecurringArTemplates/Edit', [
            'template' => [
                'id' => $template->id,
                'company_id' => $template->company_id,
                'partner_id' => $template->partner_id,
                'partner_name' => $template->partner?->name,
                'name' => $template->name,
                'currency_code' => $template->currency_code,
                'invoice_type' => $template->invoice_type,
                'payment_terms_days' => $template->payment_terms_days,
                'recurrence_rule' => $template->recurrence_rule,
                'anchor_date' => $template->anchor_date->toDateString(),
                'next_run_date' => $template->next_run_date?->toDateString(),
                'last_run_date' => $template->last_run_date?->toDateString(),
                'is_active' => $template->is_active,
                'lines' => $template->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'discount_amount' => (float) $l->discount_amount,
                    'tax_code_id' => $l->tax_code_id,
                    'revenue_account_id' => $l->revenue_account_id,
                ]),
            ],
            'invoiceTypes' => ArInvoice::TYPES,
            'upcomingRunDates' => $this->upcomingRunDates($template->recurrence_rule, $template->anchor_date, $template->next_run_date),
            ...$this->formOptions($template->company_id),
        ]);
    }

    public function update(UpdateRecurringArTemplateRequest $request, RecurringArTemplate $template)
    {
        $data = $request->validated();
        $this->service->update(
            $template,
            [
                'partner_id' => $data['partner_id'],
                'name' => $data['name'],
                'currency_code' => $data['currency_code'],
                'invoice_type' => $data['invoice_type'],
                'payment_terms_days' => $data['payment_terms_days'],
                'recurrence_rule' => $data['recurrence_rule'],
                'anchor_date' => $data['anchor_date'],
            ],
            $data['lines'],
            $request->user()->id,
        );

        return redirect()->route('accounting.recurring-ar-templates.edit', $template)->with('success', 'Recurring invoice template updated.');
    }

    public function setActive(Request $request, RecurringArTemplate $template)
    {
        $this->service->setActive($template, $request->boolean('is_active'), $request->user()->id);

        return back()->with('success', $request->boolean('is_active') ? 'Template resumed.' : 'Template paused.');
    }

    public function destroy(Request $request, RecurringArTemplate $template)
    {
        $companyId = $template->company_id;
        $this->service->delete($template, $request->user()->id);

        return redirect()->route('accounting.recurring-ar-templates.index', ['company_id' => $companyId])->with('success', 'Recurring invoice template deleted.');
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
            return ['revenueAccounts' => [], 'taxCodes' => [], 'currencies' => []];
        }

        return [
            'revenueAccounts' => Account::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_control_account', false)
                ->where('account_type', Account::TYPE_REVENUE)
                ->orderBy('account_code')
                ->get(['id', 'account_code', 'account_name'])
                ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"]),
            'taxCodes' => TaxCode::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('tax_type', TaxCode::TYPE_OUTPUT)
                ->orderBy('code')
                ->get(['id', 'code', 'rate'])
                ->map(fn (TaxCode $t) => ['value' => $t->id, 'label' => "{$t->code} ({$t->rate}%)"]),
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('code')->get(['code', 'name']),
        ];
    }
}
