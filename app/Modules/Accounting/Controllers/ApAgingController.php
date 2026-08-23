<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** §3E AP Aging — mirrors AR aging. Drill-in reuses the AP Bills index (filtered by partner) rather than a dedicated screen. */
class ApAgingController extends Controller
{
    public function __construct(private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $today = Carbon::today();

        $openBills = ApBill::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [ApBill::STATUS_POSTED, ApBill::STATUS_PARTIALLY_PAID])
            ->with('partner:id,name')
            ->get();

        $rows = [];
        foreach ($openBills as $bill) {
            $open = $bill->openBalance();
            if ($open <= 0.005) {
                continue;
            }

            $partnerId = $bill->partner_id;
            $rows[$partnerId] ??= [
                'partner_id' => $partnerId,
                'partner_name' => $bill->partner?->name,
                'current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_90_plus' => 0.0,
            ];

            $daysPastDue = $bill->due_date->lt($today) ? $bill->due_date->diffInDays($today) : 0;
            $bucket = match (true) {
                $daysPastDue <= 0 => 'current',
                $daysPastDue <= 30 => 'days_1_30',
                $daysPastDue <= 60 => 'days_31_60',
                $daysPastDue <= 90 => 'days_61_90',
                default => 'days_90_plus',
            };

            $rows[$partnerId][$bucket] += $open;
        }

        return Inertia::render('Accounting/ApAging/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'rows' => array_values($rows),
            'asOf' => $today->toDateString(),
        ]);
    }
}
