<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Requests\StoreApDebitNoteRequest;
use App\Modules\Accounting\Services\ApDebitNoteService;
use Illuminate\Support\Facades\DB;

/** §3E — v1 scope is bill-linked debit notes only, issued inline from the bill Show page (see ApDebitNoteService docblock). No index/show screens — the bill and the AP aging report are where debits matter. */
class ApDebitNoteController extends Controller
{
    public function __construct(private readonly ApDebitNoteService $service) {}

    public function store(StoreApDebitNoteRequest $request)
    {
        $data = $request->validated();
        $bill = ApBill::query()->findOrFail($data['ap_bill_id']);

        DB::transaction(function () use ($data, $bill, $request) {
            $note = $this->service->create([
                'company_id' => $bill->company_id,
                'partner_id' => $bill->partner_id,
                'ap_bill_id' => $bill->id,
                'debit_date' => $data['debit_date'],
                'amount' => $data['amount'],
                'reason' => $data['reason'] ?? null,
                'expense_account_id' => $data['expense_account_id'],
            ], $request->user()->id);

            $this->service->post($note, $request->user()->id);
        });

        return redirect()->route('accounting.ap-bills.show', $bill)->with('success', 'Debit note issued and posted.');
    }
}
