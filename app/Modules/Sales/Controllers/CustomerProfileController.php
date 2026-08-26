<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\CustomerCreditProfile;
use App\Modules\Sales\Models\CustomerSalesProfile;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\SalesTeam;
use App\Modules\Sales\Requests\StoreCustomerProfileRequest;
use App\Modules\Sales\Services\CreditService;
use App\Modules\Sales\Services\PortalService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerProfileController extends Controller
{
    public function __construct(
        protected CreditService $creditService,
        protected PortalService $portalService,
    ) {}

    public function index(Request $request): Response
    {
        $perPage = TableQuery::perPage($request->integer('per_page') ?: null, 20);
        $query = Partner::query()
            ->where('is_active', true)
            ->with(['salesProfile.salesTeam', 'salesProfile.priceList', 'salesProfile.assignedRep', 'creditProfile'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"));

        TableQuery::applySort($query, $request->sort, $request->direction, ['name', 'created_at'], 'name', 'asc');

        $customers = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Sales/Master/CustomerProfiles/Index', [
            'customers' => $customers,
            'filters' => $request->only('search', 'sort', 'direction', 'per_page'),
        ]);
    }

    public function edit(Partner $partner): Response
    {
        $salesProfile = CustomerSalesProfile::firstOrCreate(['partner_id' => $partner->id]);
        $creditProfile = CustomerCreditProfile::firstOrCreate(['partner_id' => $partner->id]);

        $exposure = $this->creditService->getExposure($partner->id);

        return Inertia::render('Sales/Master/CustomerProfiles/Edit', [
            'customer' => $partner,
            'salesProfile' => $salesProfile,
            'creditProfile' => $creditProfile,
            'exposure' => $exposure,
            'teams' => SalesTeam::where('is_active', true)->get(),
            'priceLists' => PriceList::where('is_active', true)->get(),
            'users' => User::query()->select(['id', 'name'])->get(),
        ]);
    }

    public function update(StoreCustomerProfileRequest $request, Partner $partner): RedirectResponse
    {
        DB::transaction(function () use ($request, $partner) {
            CustomerSalesProfile::updateOrCreate(
                ['partner_id' => $partner->id],
                [
                    'sales_team_id' => $request->sales_team_id,
                    'price_list_id' => $request->price_list_id,
                    'assigned_rep_id' => $request->assigned_rep_id,
                ]
            );

            CustomerCreditProfile::updateOrCreate(
                ['partner_id' => $partner->id],
                [
                    'credit_limit' => $request->credit_limit ?? 0,
                    'payment_terms_days' => $request->payment_terms_days ?? 30,
                    'on_hold' => $request->boolean('on_hold'),
                ]
            );
        });

        return redirect()->route('sales.master.customers.index')
            ->with('success', 'Customer sales & credit profile updated.');
    }

    public function generatePortalToken(Partner $partner): RedirectResponse
    {
        $token = $this->portalService->generateToken($partner->id);
        $tenantKey = (string) tenant()->getTenantKey();

        return back()->with('success', "Customer portal link generated: /portal/{$tenantKey}/sales/{$token->token}");
    }
}
