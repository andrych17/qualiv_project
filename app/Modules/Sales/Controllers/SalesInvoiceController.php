<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\ArInvoice;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3I: invoices are owned by Accounting, not Sales — this is a read-only view scoped to
 * invoices Sales itself requested (subject_type 'sales.so_hdrs' from orders, or
 * 'sales.contr_subscriptions' from recurring billing), not Accounting's full ar_invoices
 * list, so a Sales user never needs menu.perm:ACCOUNTING just to see what's owed on their
 * own orders. Detail lives on the owning Sales Order's own page (§3F already shows its
 * linked invoices) rather than duplicating Accounting's invoice show screen here.
 */
class SalesInvoiceController extends Controller
{
    private const SALES_SUBJECT_TYPES = ['sales.so_hdrs', 'sales.contr_subscriptions'];

    public function index(Request $request): Response
    {
        $perPage = TableQuery::perPage($request->integer('per_page') ?: null, 20);
        $filters = $request->only(['search', 'status', 'sort', 'direction', 'per_page']);

        if (! Schema::hasTable('ACCOUNTING.ar_invoices')) {
            return Inertia::render('Sales/Invoices/Index', [
                'invoices' => ['data' => [], 'links' => [], 'total' => 0, 'from' => null, 'to' => null, 'per_page' => $perPage],
                'statuses' => ArInvoice::STATUSES,
                'filters' => $filters,
                'accountingInstalled' => false,
            ]);
        }

        $query = ArInvoice::with('partner')
            ->whereIn('subject_type', self::SALES_SUBJECT_TYPES)
            ->when($request->search, function ($q, $s) {
                $q->where('invoice_no', 'ilike', "%{$s}%")
                    ->orWhereHas('partner', fn ($p) => $p->where('name', 'ilike', "%{$s}%"));
            })
            ->when($request->status, fn ($q, $st) => $q->where('status', $st));

        TableQuery::applySort($query, $request->sort, $request->direction, ['invoice_no', 'issue_date', 'due_date', 'total_amount', 'status'], 'issue_date', 'desc');

        $invoices = $query->paginate($perPage)->withQueryString()->through(fn (ArInvoice $inv) => [
            'id' => $inv->id,
            'invoice_no' => $inv->invoice_no,
            'invoice_type' => $inv->invoice_type,
            'status' => $inv->status,
            'issue_date' => $inv->issue_date?->format('Y-m-d'),
            'due_date' => $inv->due_date?->format('Y-m-d'),
            'total_amount' => (float) $inv->total_amount,
            'open_balance' => $inv->openBalance(),
            'currency_code' => $inv->currency_code,
            'customer' => $inv->partner ? ['id' => $inv->partner->id, 'name' => $inv->partner->name] : null,
            'subject_type' => $inv->subject_type,
            'subject_id' => $inv->subject_id,
        ]);

        return Inertia::render('Sales/Invoices/Index', [
            'invoices' => $invoices,
            'statuses' => ArInvoice::STATUSES,
            'filters' => $filters,
            'accountingInstalled' => true,
        ]);
    }
}
