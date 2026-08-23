<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Models\WithholdingType;
use App\Modules\Accounting\Requests\StoreApBillRequest;
use App\Modules\Accounting\Requests\UpdateApBillRequest;
use App\Modules\Accounting\Services\ApBillService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3E — vendor bills, the AP engine's primary screen. */
class ApBillController extends Controller
{
    public function __construct(private readonly ApBillService $service) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);

        $bills = ApBill::query()
            ->where('company_id', $companyId)
            ->when($request->integer('partner_id'), fn ($q, $partnerId) => $q->where('partner_id', $partnerId))
            ->with('partner:id,name')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Accounting/ApBills/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'selectedPartnerId' => $request->integer('partner_id') ?: null,
            'bills' => $bills->map(fn (ApBill $b) => [
                'id' => $b->id,
                'bill_no' => $b->bill_no,
                'partner_name' => $b->partner?->name,
                'issue_date' => $b->issue_date->toDateString(),
                'due_date' => $b->due_date->toDateString(),
                'status' => $b->status,
                'total_amount' => (float) $b->total_amount,
                'open_balance' => $b->openBalance(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/ApBills/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            ...$this->formOptions($companyId),
        ]);
    }

    public function store(StoreApBillRequest $request)
    {
        $data = $request->validated();
        $bill = $this->service->create(
            [
                'company_id' => $data['company_id'],
                'partner_id' => $data['partner_id'],
                'bill_no' => $data['bill_no'],
                'currency_code' => $data['currency_code'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'vendor_faktur_no' => $data['vendor_faktur_no'] ?? null,
                'withholding_type_id' => $data['withholding_type_id'] ?? null,
            ],
            $data['lines'],
            $request->user()->id,
        );

        return redirect()->route('accounting.ap-bills.show', $bill)->with('success', 'Bill saved as draft.');
    }

    public function show(ApBill $bill): Response
    {
        $bill->load([
            'lines.taxCode:id,code,rate', 'lines.expenseAccount:id,account_code,account_name',
            'partner:id,name', 'journal:id', 'withholdingType:id,code,bp_type',
            'inputFakturPajak:id,ap_bill_id,nomor_seri_faktur,status', 'buktiPotong:id,ap_bill_id,bp_number,status',
            'debitNotes' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return Inertia::render('Accounting/ApBills/Show', [
            'bill' => [
                'id' => $bill->id,
                'company_id' => $bill->company_id,
                'bill_no' => $bill->bill_no,
                'partner_id' => $bill->partner_id,
                'partner_name' => $bill->partner?->name,
                'currency_code' => $bill->currency_code,
                'issue_date' => $bill->issue_date->toDateString(),
                'due_date' => $bill->due_date->toDateString(),
                'vendor_faktur_no' => $bill->vendor_faktur_no,
                'withholding_type_label' => $bill->withholdingType?->code,
                'status' => $bill->status,
                'subtotal' => (float) $bill->subtotal,
                'tax_amount' => (float) $bill->tax_amount,
                'withheld_amount' => (float) $bill->withheld_amount,
                'total_amount' => (float) $bill->total_amount,
                'paid_amount' => (float) $bill->paid_amount,
                'debited_amount' => (float) $bill->debited_amount,
                'open_balance' => $bill->openBalance(),
                'journal_id' => $bill->journal_id,
                'input_faktur_pajak' => $bill->inputFakturPajak ? [
                    'nomor_seri_faktur' => $bill->inputFakturPajak->nomor_seri_faktur,
                    'status' => $bill->inputFakturPajak->status,
                ] : null,
                'bukti_potong' => $bill->buktiPotong ? [
                    'bp_number' => $bill->buktiPotong->bp_number,
                    'status' => $bill->buktiPotong->status,
                ] : null,
                'lines' => $bill->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'discount_amount' => (float) $l->discount_amount,
                    'tax_code' => $l->taxCode ? "{$l->taxCode->code} ({$l->taxCode->rate}%)" : null,
                    'expense_account' => "{$l->expenseAccount->account_code} — {$l->expenseAccount->account_name}",
                    'line_amount' => (float) $l->line_amount,
                    'tax_amount' => (float) $l->tax_amount,
                ]),
                'debit_notes' => $bill->debitNotes->map(fn ($d) => [
                    'id' => $d->id,
                    'debit_note_no' => $d->debit_note_no,
                    'debit_date' => $d->debit_date->toDateString(),
                    'amount' => (float) $d->amount,
                    'status' => $d->status,
                ]),
            ],
            ...$this->formOptions($bill->company_id, forDebitNote: true),
        ]);
    }

    public function edit(ApBill $bill): Response
    {
        $bill->load('lines');

        return Inertia::render('Accounting/ApBills/Edit', [
            'bill' => [
                'id' => $bill->id,
                'company_id' => $bill->company_id,
                'partner_id' => $bill->partner_id,
                'bill_no' => $bill->bill_no,
                'currency_code' => $bill->currency_code,
                'issue_date' => $bill->issue_date->toDateString(),
                'due_date' => $bill->due_date->toDateString(),
                'vendor_faktur_no' => $bill->vendor_faktur_no,
                'withholding_type_id' => $bill->withholding_type_id,
                'lines' => $bill->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'discount_amount' => (float) $l->discount_amount,
                    'tax_code_id' => $l->tax_code_id,
                    'expense_account_id' => $l->expense_account_id,
                ]),
            ],
            ...$this->formOptions($bill->company_id),
        ]);
    }

    public function update(UpdateApBillRequest $request, ApBill $bill)
    {
        $data = $request->validated();
        $this->service->update(
            $bill,
            [
                'partner_id' => $data['partner_id'],
                'bill_no' => $data['bill_no'],
                'currency_code' => $data['currency_code'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'vendor_faktur_no' => $data['vendor_faktur_no'] ?? null,
                'withholding_type_id' => $data['withholding_type_id'] ?? null,
            ],
            $data['lines'],
        );

        return redirect()->route('accounting.ap-bills.show', $bill)->with('success', 'Bill updated.');
    }

    public function post(Request $request, ApBill $bill)
    {
        $this->service->post($bill, $request->user()->id);

        return redirect()->route('accounting.ap-bills.show', $bill)->with('success', 'Bill posted.');
    }

    public function destroy(ApBill $bill)
    {
        $companyId = $bill->company_id;
        $this->service->delete($bill);

        return redirect()->route('accounting.ap-bills.index', ['company_id' => $companyId])->with('success', 'Draft bill deleted.');
    }

    private function formOptions(?int $companyId, bool $forDebitNote = false): array
    {
        if (! $companyId) {
            return ['expenseAccounts' => [], 'taxCodes' => [], 'withholdingTypes' => [], 'currencies' => []];
        }

        $expenseAccounts = Account::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_control_account', false)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"]);

        if ($forDebitNote) {
            return ['expenseAccounts' => $expenseAccounts];
        }

        return [
            'expenseAccounts' => $expenseAccounts,
            'taxCodes' => TaxCode::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('tax_type', TaxCode::TYPE_INPUT)
                ->orderBy('code')
                ->get(['id', 'code', 'rate'])
                ->map(fn (TaxCode $t) => ['value' => $t->id, 'label' => "{$t->code} ({$t->rate}%)"]),
            'withholdingTypes' => WithholdingType::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'rate'])
                ->map(fn (WithholdingType $w) => ['value' => $w->id, 'label' => "{$w->code} ({$w->rate}%)"]),
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('code')->get(['code', 'name']),
        ];
    }
}
