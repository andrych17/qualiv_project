<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Requests\StoreArInvoiceRequest;
use App\Modules\Accounting\Requests\UpdateArInvoiceRequest;
use App\Modules\Accounting\Services\ArCreditNoteService;
use App\Modules\Accounting\Services\ArInvoiceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3D — customer invoices, the AR engine's primary screen. */
class ArInvoiceController extends Controller
{
    public function __construct(
        private readonly ArInvoiceService $service,
        private readonly ArCreditNoteService $creditNotes,
    ) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);

        $invoices = ArInvoice::query()
            ->where('company_id', $companyId)
            ->when($request->integer('partner_id'), fn ($q, $partnerId) => $q->where('partner_id', $partnerId))
            ->with('partner:id,name')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Accounting/ArInvoices/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'selectedPartnerId' => $request->integer('partner_id') ?: null,
            'invoices' => $invoices->map(fn (ArInvoice $i) => [
                'id' => $i->id,
                'invoice_no' => $i->invoice_no,
                'partner_name' => $i->partner?->name,
                'issue_date' => $i->issue_date->toDateString(),
                'due_date' => $i->due_date->toDateString(),
                'status' => $i->status,
                'total_amount' => (float) $i->total_amount,
                'open_balance' => $i->openBalance(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/ArInvoices/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            ...$this->formOptions($companyId),
        ]);
    }

    public function store(StoreArInvoiceRequest $request)
    {
        $data = $request->validated();
        $invoice = $this->service->create(
            [
                'company_id' => $data['company_id'],
                'partner_id' => $data['partner_id'],
                'currency_code' => $data['currency_code'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'invoice_type' => $data['invoice_type'],
            ],
            $data['lines'],
            $request->user()->id,
        );

        return redirect()->route('accounting.ar-invoices.show', $invoice)->with('success', 'Invoice saved as draft.');
    }

    public function show(ArInvoice $invoice): Response
    {
        $invoice->load([
            'lines.taxCode:id,code,rate', 'lines.revenueAccount:id,account_code,account_name',
            'partner:id,name', 'journal:id', 'fakturPajak:id,ar_invoice_id,nomor_seri_faktur,status',
            'creditNotes' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return Inertia::render('Accounting/ArInvoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'invoice_no' => $invoice->invoice_no,
                'invoice_type' => $invoice->invoice_type,
                'partner_id' => $invoice->partner_id,
                'partner_name' => $invoice->partner?->name,
                'currency_code' => $invoice->currency_code,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'status' => $invoice->status,
                'subtotal' => (float) $invoice->subtotal,
                'tax_amount' => (float) $invoice->tax_amount,
                'total_amount' => (float) $invoice->total_amount,
                'paid_amount' => (float) $invoice->paid_amount,
                'credited_amount' => (float) $invoice->credited_amount,
                'open_balance' => $invoice->openBalance(),
                'journal_id' => $invoice->journal_id,
                'faktur_pajak' => $invoice->fakturPajak ? [
                    'nomor_seri_faktur' => $invoice->fakturPajak->nomor_seri_faktur,
                    'status' => $invoice->fakturPajak->status,
                ] : null,
                'lines' => $invoice->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'discount_amount' => (float) $l->discount_amount,
                    'tax_code' => $l->taxCode ? "{$l->taxCode->code} ({$l->taxCode->rate}%)" : null,
                    'revenue_account' => "{$l->revenueAccount->account_code} — {$l->revenueAccount->account_name}",
                    'line_amount' => (float) $l->line_amount,
                    'tax_amount' => (float) $l->tax_amount,
                ]),
                'credit_notes' => $invoice->creditNotes->map(fn ($c) => [
                    'id' => $c->id,
                    'credit_note_no' => $c->credit_note_no,
                    'credit_date' => $c->credit_date->toDateString(),
                    'amount' => (float) $c->amount,
                    'status' => $c->status,
                ]),
            ],
            ...$this->formOptions($invoice->company_id, forCreditNote: true),
        ]);
    }

    public function edit(ArInvoice $invoice): Response
    {
        $invoice->load('lines');

        return Inertia::render('Accounting/ArInvoices/Edit', [
            'invoice' => [
                'id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'partner_id' => $invoice->partner_id,
                'currency_code' => $invoice->currency_code,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'invoice_type' => $invoice->invoice_type,
                'lines' => $invoice->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'discount_amount' => (float) $l->discount_amount,
                    'tax_code_id' => $l->tax_code_id,
                    'revenue_account_id' => $l->revenue_account_id,
                ]),
            ],
            ...$this->formOptions($invoice->company_id),
        ]);
    }

    public function update(UpdateArInvoiceRequest $request, ArInvoice $invoice)
    {
        $data = $request->validated();
        $this->service->update(
            $invoice,
            [
                'partner_id' => $data['partner_id'],
                'currency_code' => $data['currency_code'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'invoice_type' => $data['invoice_type'],
            ],
            $data['lines'],
        );

        return redirect()->route('accounting.ar-invoices.show', $invoice)->with('success', 'Invoice updated.');
    }

    public function post(Request $request, ArInvoice $invoice)
    {
        $this->service->post($invoice, $request->user()->id);

        return redirect()->route('accounting.ar-invoices.show', $invoice)->with('success', 'Invoice posted.');
    }

    public function destroy(ArInvoice $invoice)
    {
        $companyId = $invoice->company_id;
        $this->service->delete($invoice);

        return redirect()->route('accounting.ar-invoices.index', ['company_id' => $companyId])->with('success', 'Draft invoice deleted.');
    }

    private function formOptions(?int $companyId, bool $forCreditNote = false): array
    {
        if (! $companyId) {
            return ['revenueAccounts' => [], 'taxCodes' => [], 'currencies' => []];
        }

        $revenueAccounts = Account::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_control_account', false)
            ->whereIn('account_type', [Account::TYPE_REVENUE])
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"]);

        if ($forCreditNote) {
            return ['revenueAccounts' => $revenueAccounts];
        }

        return [
            'revenueAccounts' => $revenueAccounts,
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
