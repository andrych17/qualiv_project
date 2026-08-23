<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Requests\StoreExchangeRateRequest;
use App\Modules\Accounting\Requests\UpdateExchangeRateRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3L exchange rates — plain company-scoped CRUD, same list-selector convention as TaxCodes/CostCenters. */
class ExchangeRateController extends Controller
{
    public function __construct(private readonly ExchangeRateService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $rates = ExchangeRate::query()->where('company_id', $companyId)->orderByDesc('effective_date')->orderBy('currency_code')->get();

        return Inertia::render('Accounting/ExchangeRates/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'baseCurrency' => Company::query()->find($companyId)?->base_currency,
            'rates' => $rates->map(fn (ExchangeRate $r) => [
                'id' => $r->id,
                'currency_code' => $r->currency_code,
                'rate_to_base' => (float) $r->rate_to_base,
                'effective_date' => $r->effective_date->toDateString(),
                'source' => $r->source,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/ExchangeRates/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('code')->get(['code', 'name']),
        ]);
    }

    public function store(StoreExchangeRateRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.exchange-rates.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Exchange rate added.');
    }

    public function edit(ExchangeRate $exchangeRate): Response
    {
        return Inertia::render('Accounting/ExchangeRates/Edit', [
            'exchangeRate' => [
                'id' => $exchangeRate->id,
                'company_id' => $exchangeRate->company_id,
                'currency_code' => $exchangeRate->currency_code,
                'rate_to_base' => (float) $exchangeRate->rate_to_base,
                'effective_date' => $exchangeRate->effective_date->toDateString(),
            ],
        ]);
    }

    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchangeRate)
    {
        $this->service->update($exchangeRate, $request->validated());

        return redirect()->route('accounting.exchange-rates.index', ['company_id' => $exchangeRate->company_id])
            ->with('success', 'Exchange rate updated.');
    }

    public function destroy(ExchangeRate $exchangeRate)
    {
        $companyId = $exchangeRate->company_id;
        $this->service->delete($exchangeRate);

        return redirect()->route('accounting.exchange-rates.index', ['company_id' => $companyId])->with('success', 'Exchange rate deleted.');
    }
}
