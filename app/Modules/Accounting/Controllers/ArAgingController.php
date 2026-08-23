<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** §3D AR Aging — current / 1-30 / 31-60 / 61-90 / 90+ by partner. Drill-in reuses the AR Invoices index (filtered by partner) rather than a dedicated screen (§5 MVP bias). */
class ArAgingController extends Controller
{
    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);
        $today = Carbon::today();

        $openInvoices = ArInvoice::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [ArInvoice::STATUS_POSTED, ArInvoice::STATUS_PARTIALLY_PAID])
            ->with('partner:id,name')
            ->get();

        $rows = [];
        foreach ($openInvoices as $invoice) {
            $open = $invoice->openBalance();
            if ($open <= 0.005) {
                continue;
            }

            $partnerId = $invoice->partner_id;
            $rows[$partnerId] ??= [
                'partner_id' => $partnerId,
                'partner_name' => $invoice->partner?->name,
                'current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_90_plus' => 0.0,
            ];

            $daysPastDue = $invoice->due_date->lt($today) ? $invoice->due_date->diffInDays($today) : 0;
            $bucket = match (true) {
                $daysPastDue <= 0 => 'current',
                $daysPastDue <= 30 => 'days_1_30',
                $daysPastDue <= 60 => 'days_31_60',
                $daysPastDue <= 90 => 'days_61_90',
                default => 'days_90_plus',
            };

            $rows[$partnerId][$bucket] += $open;
        }

        return Inertia::render('Accounting/ArAging/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'rows' => array_values($rows),
            'asOf' => $today->toDateString(),
        ]);
    }
}
