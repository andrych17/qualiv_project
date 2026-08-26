<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\PromoCode;
use App\Modules\Sales\Requests\StorePromoCodeRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PromoCodeController extends Controller
{
    public function index(): Response
    {
        $promoCodes = PromoCode::orderByDesc('valid_from')->get();

        return Inertia::render('Sales/Master/PromoCodes/Index', [
            'promoCodes' => $promoCodes,
        ]);
    }

    public function store(StorePromoCodeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['code'] = strtoupper(trim($data['code']));
        PromoCode::create($data);

        return back()->with('success', 'Promo Code created.');
    }

    public function update(StorePromoCodeRequest $request, PromoCode $promoCode): RedirectResponse
    {
        $data = $request->validated();
        $data['code'] = strtoupper(trim($data['code']));
        $promoCode->update($data);

        return back()->with('success', 'Promo Code updated.');
    }

    public function destroy(PromoCode $promoCode): RedirectResponse
    {
        $promoCode->delete();

        return back()->with('success', 'Promo Code deleted.');
    }
}
