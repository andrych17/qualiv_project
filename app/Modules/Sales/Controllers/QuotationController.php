<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Requests\StoreQuotationRequest;
use App\Modules\Sales\Services\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    public function __construct(protected QuotationService $quotationService) {}

    public function index(Request $request): Response
    {
        $quotations = Quotation::with(['customer', 'opportunity', 'creator', 'lines'])
            ->when($request->search, function ($q, $s) {
                $q->whereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                    ->orWhere('uuid', 'ilike', "%{$s}%");
            })
            ->when($request->status, fn ($q, $st) => $q->where('status', $st))
            ->when($request->customer_id, fn ($q, $c) => $q->where('customer_id', $c))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sales/Quotations/Index', [
            'quotations' => $quotations,
            'statuses' => Quotation::STATUSES,
            'filters' => $request->only(['search', 'status', 'customer_id']),
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/Quotations/Create', [
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'opportunities' => Opportunity::query()->select(['id', 'name', 'customer_id'])->get(),
            'priceLists' => PriceList::with('lines')->where('is_active', true)->get(),
            'preselectedCustomerId' => $request->customer_id ? (int) $request->customer_id : null,
            'preselectedOpportunityId' => $request->opportunity_id ? (int) $request->opportunity_id : null,
        ]);
    }

    public function store(StoreQuotationRequest $request): RedirectResponse
    {
        $quote = $this->quotationService->create($request->validated(), $request->user()?->id);

        return redirect()->route('sales.quotations.show', $quote)
            ->with('success', 'Quotation draft created successfully.');
    }

    public function show(Quotation $quotation): Response
    {
        $quotation->load(['lines', 'customer', 'opportunity', 'priceList.lines', 'creator', 'convertedSalesOrder', 'revisions.creator']);

        return Inertia::render('Sales/Quotations/Show', [
            'quotation' => $quotation,
            'subtotal' => $quotation->subtotal,
            'totalDiscount' => $quotation->total_discount,
            'totalTax' => $quotation->total_tax,
            'totalAmount' => $quotation->total_amount,
        ]);
    }

    public function edit(Quotation $quotation): Response
    {
        $quotation->load(['lines', 'customer', 'opportunity', 'priceList.lines']);

        return Inertia::render('Sales/Quotations/Edit', [
            'quotation' => $quotation,
            'customers' => Partner::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')->get(),
            'opportunities' => Opportunity::query()->select(['id', 'name', 'customer_id'])->get(),
            'priceLists' => PriceList::with('lines')->where('is_active', true)->get(),
        ]);
    }

    public function update(StoreQuotationRequest $request, Quotation $quotation): RedirectResponse
    {
        $updatedQuote = $this->quotationService->updateOrRevise($quotation, $request->validated(), $request->user()?->id);

        return redirect()->route('sales.quotations.show', $updatedQuote)
            ->with('success', 'Quotation updated successfully.');
    }

    public function send(Quotation $quotation): RedirectResponse
    {
        $this->quotationService->send($quotation);

        return back()->with('success', 'Quotation marked as sent to customer.');
    }

    public function convertToOrder(Quotation $quotation, Request $request): RedirectResponse
    {
        $order = $this->quotationService->convertToOrder($quotation, $request->user()?->id);

        return redirect()->route('sales.orders.show', $order)
            ->with('success', "Quotation successfully converted to Sales Order #{$order->so_number}.");
    }

    public function cloneExpired(Quotation $quotation, Request $request): RedirectResponse
    {
        $newQuote = $this->quotationService->cloneExpired($quotation, $request->user()?->id);

        return redirect()->route('sales.quotations.show', $newQuote)
            ->with('success', 'Expired quotation cloned into a new draft quotation.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        if ($quotation->status !== Quotation::STATUS_DRAFT) {
            return back()->withErrors(['status' => 'Only draft quotations can be deleted.']);
        }

        $quotation->delete();

        return redirect()->route('sales.quotations.index')
            ->with('success', 'Quotation draft deleted.');
    }
}
