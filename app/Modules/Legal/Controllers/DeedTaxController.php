<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedTax;
use App\Modules\Legal\Requests\IssueTaxBillingCodeRequest;
use App\Modules\Legal\Requests\MarkTaxPaidRequest;
use App\Modules\Legal\Requests\UpdateDeedTaxAmountsRequest;
use App\Modules\Legal\Services\TaxService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeedTaxController extends Controller
{
    private const SORTABLE = ['status', 'tax_type', 'created_at'];

    public function __construct(
        protected TaxService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'tax_type', 'sort', 'direction', 'per_page');

        $taxes = DeedTax::query()
            ->with(['deed:id,deed_number,matter_id', 'deed.matter:id,code'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['tax_type'] ?? null, fn ($q, $type) => $q->where('tax_type', $type))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (DeedTax $t) => [
                'id' => $t->id,
                'deed_id' => $t->deed_id,
                'deed_number' => $t->deed?->deed_number,
                'matter_code' => $t->deed?->matter?->code,
                'tax_type' => $t->tax_type,
                'status' => $t->status,
                'base_amount' => $t->base_amount,
                'computed_amount' => $t->computed_amount,
                'billing_code' => $t->billing_code,
            ]);

        return Inertia::render('Legal/Taxes/Index', [
            'taxes' => $taxes,
            'filters' => $filters,
        ]);
    }

    public function generate(Deed $deed)
    {
        $this->service->generateForDeed($deed);

        return back()->with('success', 'Tax records generated.');
    }

    public function updateAmounts(UpdateDeedTaxAmountsRequest $request, Deed $deed, DeedTax $tax)
    {
        $this->service->updateAmounts($tax, $request->validated());

        return back()->with('success', 'Tax amounts updated.');
    }

    public function issueBillingCode(IssueTaxBillingCodeRequest $request, Deed $deed, DeedTax $tax)
    {
        $this->service->issueBillingCode($tax, $request->validated()['billing_code']);

        return back()->with('success', 'Billing code issued.');
    }

    public function markPaid(MarkTaxPaidRequest $request, Deed $deed, DeedTax $tax)
    {
        $this->service->markPaid($tax, $request->validated()['ntpn']);

        return back()->with('success', 'Marked paid.');
    }

    public function markValidated(Deed $deed, DeedTax $tax)
    {
        $this->service->markValidated($tax);

        return back()->with('success', 'Tax validated.');
    }
}
