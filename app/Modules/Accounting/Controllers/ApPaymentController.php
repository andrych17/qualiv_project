<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApPayment;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Requests\StoreApPaymentRequest;
use App\Modules\Accounting\Services\ApPaymentService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** §3E — record a vendor payment. create()+post() run in one DB transaction here: a human submitting this form is the review step (see ApPaymentService docblock), so either the payment posts in full or nothing is saved. */
class ApPaymentController extends Controller
{
    public function __construct(private readonly ApPaymentService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $payments = ApPayment::query()
            ->where('company_id', $companyId)
            ->with('partner:id,name')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Accounting/ApPayments/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'payments' => $payments->map(fn (ApPayment $p) => [
                'id' => $p->id,
                'partner_name' => $p->partner?->name,
                'payment_date' => $p->payment_date->toDateString(),
                'amount' => (float) $p->amount,
                'status' => $p->status,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');
        $partnerId = $request->integer('partner_id') ?: null;

        return Inertia::render('Accounting/ApPayments/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'selectedPartnerId' => $partnerId,
            'openBills' => $partnerId ? $this->openBills($companyId, $partnerId) : [],
            'cashAccounts' => $this->cashAccounts($companyId),
        ]);
    }

    /** Reloads the open-bill picklist when the vendor changes (no full page reload). */
    public function openBillsFor(Request $request)
    {
        return response()->json($this->openBills((int) $request->integer('company_id'), (int) $request->integer('partner_id')));
    }

    public function store(StoreApPaymentRequest $request)
    {
        $data = $request->validated();

        $payment = DB::transaction(function () use ($data, $request) {
            $payment = $this->service->create(
                [
                    'company_id' => $data['company_id'],
                    'partner_id' => $data['partner_id'],
                    'cash_gl_account_id' => $data['cash_gl_account_id'],
                    'currency_code' => $data['currency_code'],
                    'payment_date' => $data['payment_date'],
                    'amount' => $data['amount'],
                    'memo' => $data['memo'] ?? null,
                ],
                $data['applications'] ?? null,
                $request->user()->id,
            );

            return $this->service->post($payment, $request->user()->id);
        });

        return redirect()->route('accounting.ap-payments.index', ['company_id' => $payment->company_id])->with('success', 'Payment recorded and posted.');
    }

    private function openBills(int $companyId, int $partnerId): array
    {
        return ApBill::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->whereIn('status', [ApBill::STATUS_POSTED, ApBill::STATUS_PARTIALLY_PAID])
            ->orderBy('due_date')
            ->get()
            ->map(fn (ApBill $b) => [
                'id' => $b->id,
                'bill_no' => $b->bill_no,
                'due_date' => $b->due_date->toDateString(),
                'open_balance' => $b->openBalance(),
            ])
            ->values()
            ->all();
    }

    private function cashAccounts(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return Account::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_control_account', false)
            ->where('account_type', Account::TYPE_ASSET)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"])
            ->values()
            ->all();
    }
}
