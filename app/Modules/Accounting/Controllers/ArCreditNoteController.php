<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Requests\StoreArCreditNoteRequest;
use App\Modules\Accounting\Services\ArCreditNoteService;
use Illuminate\Support\Facades\DB;

/** §3D — v1 scope is invoice-linked credit notes only, issued inline from the invoice Show page (see ArCreditNoteService docblock). No index/show screens — the invoice and the AR aging report are where credits matter. */
class ArCreditNoteController extends Controller
{
    public function __construct(private readonly ArCreditNoteService $service) {}

    public function store(StoreArCreditNoteRequest $request)
    {
        $data = $request->validated();
        $invoice = ArInvoice::query()->findOrFail($data['ar_invoice_id']);

        DB::transaction(function () use ($data, $invoice, $request) {
            $note = $this->service->create([
                'company_id' => $invoice->company_id,
                'partner_id' => $invoice->partner_id,
                'ar_invoice_id' => $invoice->id,
                'credit_date' => $data['credit_date'],
                'amount' => $data['amount'],
                'reason' => $data['reason'] ?? null,
                'revenue_account_id' => $data['revenue_account_id'],
            ], $request->user()->id);

            $this->service->post($note, $request->user()->id);
        });

        return redirect()->route('accounting.ar-invoices.show', $invoice)->with('success', 'Credit note issued and posted.');
    }
}
