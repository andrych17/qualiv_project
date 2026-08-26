<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\Territory;
use App\Modules\Sales\Requests\StorePriceListRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(): Response
    {
        $priceLists = PriceList::with(['territory', 'lines'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Sales/Master/PriceLists/Index', [
            'priceLists' => $priceLists,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Master/PriceLists/Create', [
            'territories' => Territory::where('is_active', true)->get(),
        ]);
    }

    public function store(StorePriceListRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $data = $request->validated();
            $priceList = PriceList::create([
                'name' => $data['name'],
                'currency' => $data['currency'] ?? 'IDR',
                'territory_id' => $data['territory_id'] ?? null,
                'customer_segment' => $data['customer_segment'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'is_tenant_default' => $data['is_tenant_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['lines'])) {
                foreach ($data['lines'] as $line) {
                    $priceList->lines()->create([
                        'item_type' => $line['item_type'],
                        'product_id' => $line['product_id'] ?? null,
                        'description' => $line['description'],
                        'unit_price' => $line['unit_price'],
                    ]);
                }
            }
        });

        return redirect()->route('sales.master.price-lists.index')
            ->with('success', 'Price List created.');
    }

    public function edit(PriceList $priceList): Response
    {
        $priceList->load(['lines', 'territory']);

        return Inertia::render('Sales/Master/PriceLists/Edit', [
            'priceList' => $priceList,
            'territories' => Territory::where('is_active', true)->get(),
        ]);
    }

    public function update(StorePriceListRequest $request, PriceList $priceList): RedirectResponse
    {
        DB::transaction(function () use ($request, $priceList) {
            $data = $request->validated();
            $priceList->update([
                'name' => $data['name'],
                'currency' => $data['currency'] ?? 'IDR',
                'territory_id' => $data['territory_id'] ?? null,
                'customer_segment' => $data['customer_segment'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'is_tenant_default' => $data['is_tenant_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (isset($data['lines'])) {
                $priceList->lines()->delete();
                foreach ($data['lines'] as $line) {
                    $priceList->lines()->create([
                        'item_type' => $line['item_type'],
                        'product_id' => $line['product_id'] ?? null,
                        'description' => $line['description'],
                        'unit_price' => $line['unit_price'],
                    ]);
                }
            }
        });

        return redirect()->route('sales.master.price-lists.index')
            ->with('success', 'Price List updated.');
    }

    public function destroy(PriceList $priceList): RedirectResponse
    {
        $priceList->delete();

        return redirect()->route('sales.master.price-lists.index')
            ->with('success', 'Price List deleted.');
    }
}
