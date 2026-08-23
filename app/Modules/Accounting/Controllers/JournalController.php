<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Requests\StoreJournalRequest;
use App\Modules\Accounting\Requests\UpdateJournalRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3C — manual journal entry screen, the only caller of JournalService in this pass. */
class JournalController extends Controller
{
    public function __construct(private readonly JournalService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $journals = GlJournal::query()
            ->where('company_id', $companyId)
            ->with('fiscalPeriod:id,period_no,fiscal_year_id')
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Accounting/Journals/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'journals' => $journals->map(fn (GlJournal $j) => [
                'id' => $j->id,
                'journal_date' => $j->journal_date->toDateString(),
                'memo' => $j->memo,
                'source' => $j->source,
                'status' => $j->status,
                'period_no' => $j->fiscalPeriod?->period_no,
                'currency_code' => $j->currency_code,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/Journals/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            ...$this->formOptions($companyId),
        ]);
    }

    public function store(StoreJournalRequest $request)
    {
        $data = $request->validated();
        $journal = $this->service->create(
            [
                'company_id' => $data['company_id'],
                'fiscal_period_id' => $data['fiscal_period_id'],
                'journal_date' => $data['journal_date'],
                'currency_code' => $data['currency_code'],
                'memo' => $data['memo'] ?? null,
            ],
            $data['lines'],
            $request->user()->id,
        );

        return redirect()->route('accounting.journals.show', $journal)->with('success', 'Journal saved as draft.');
    }

    public function show(GlJournal $journal): Response
    {
        $journal->load(['lines.account:id,account_code,account_name', 'lines.costCenter:id,code,name', 'fiscalPeriod', 'createdBy:id,name', 'postedBy:id,name', 'reversedJournal:id', 'reversal:id,reversed_journal_id']);

        return Inertia::render('Accounting/Journals/Show', [
            'journal' => [
                'id' => $journal->id,
                'company_id' => $journal->company_id,
                'journal_date' => $journal->journal_date->toDateString(),
                'currency_code' => $journal->currency_code,
                'memo' => $journal->memo,
                'source' => $journal->source,
                'status' => $journal->status,
                'fiscal_period' => $journal->fiscalPeriod?->only(['id', 'period_no', 'status']),
                'created_by' => $journal->createdBy?->name,
                'posted_by' => $journal->postedBy?->name,
                'posted_at' => $journal->posted_at?->toDateTimeString(),
                'reversed_journal_id' => $journal->reversed_journal_id,
                'reversal_id' => $journal->reversal?->id,
                'total_debit' => $journal->lines->sum('debit'),
                'total_credit' => $journal->lines->sum('credit'),
                'lines' => $journal->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'account_code' => $l->account->account_code,
                    'account_name' => $l->account->account_name,
                    'cost_center_name' => $l->costCenter?->name,
                    'debit' => $l->debit,
                    'credit' => $l->credit,
                    'description' => $l->description,
                ]),
            ],
        ]);
    }

    public function edit(GlJournal $journal): Response
    {
        $journal->load('lines');

        return Inertia::render('Accounting/Journals/Edit', [
            'journal' => [
                'id' => $journal->id,
                'company_id' => $journal->company_id,
                'fiscal_period_id' => $journal->fiscal_period_id,
                'journal_date' => $journal->journal_date->toDateString(),
                'currency_code' => $journal->currency_code,
                'memo' => $journal->memo,
                'lines' => $journal->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'cost_center_id' => $l->cost_center_id,
                    'debit' => (float) $l->debit,
                    'credit' => (float) $l->credit,
                    'description' => $l->description,
                ]),
            ],
            ...$this->formOptions($journal->company_id),
        ]);
    }

    public function update(UpdateJournalRequest $request, GlJournal $journal)
    {
        $data = $request->validated();
        $this->service->update(
            $journal,
            [
                'fiscal_period_id' => $data['fiscal_period_id'],
                'journal_date' => $data['journal_date'],
                'currency_code' => $data['currency_code'],
                'memo' => $data['memo'] ?? null,
            ],
            $data['lines'],
        );

        return redirect()->route('accounting.journals.show', $journal)->with('success', 'Journal updated.');
    }

    public function post(Request $request, GlJournal $journal)
    {
        $this->service->post($journal, $request->user()->id);

        return redirect()->route('accounting.journals.show', $journal)->with('success', 'Journal posted.');
    }

    public function reverse(Request $request, GlJournal $journal)
    {
        $reversal = $this->service->reverse($journal, $request->user()->id);

        return redirect()->route('accounting.journals.show', $reversal)->with('success', 'Reversal journal posted.');
    }

    public function destroy(GlJournal $journal)
    {
        $companyId = $journal->company_id;
        $this->service->delete($journal);

        return redirect()->route('accounting.journals.index', ['company_id' => $companyId])->with('success', 'Draft journal deleted.');
    }

    private function formOptions(?int $companyId): array
    {
        if (! $companyId) {
            return ['accounts' => [], 'costCenters' => [], 'fiscalPeriods' => [], 'currencies' => []];
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
            'fiscalPeriods' => FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->orderByDesc('start_date')
                ->get(['id', 'period_no', 'fiscal_year_id', 'start_date'])
                ->map(fn (FiscalPeriod $p) => ['value' => $p->id, 'label' => 'Period '.$p->period_no.' ('.$p->start_date->format('M Y').')']),
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('code')->get(['code', 'name']),
        ];
    }
}
