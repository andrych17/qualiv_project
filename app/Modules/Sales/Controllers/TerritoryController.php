<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Territory;
use App\Modules\Sales\Requests\StoreTerritoryRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TerritoryController extends Controller
{
    public function index(): Response
    {
        $territories = Territory::withCount('teams')->get();

        return Inertia::render('Sales/Master/Territories/Index', [
            'territories' => $territories,
        ]);
    }

    public function store(StoreTerritoryRequest $request): RedirectResponse
    {
        Territory::create($request->validated());

        return back()->with('success', 'Territory created.');
    }

    public function update(StoreTerritoryRequest $request, Territory $territory): RedirectResponse
    {
        $territory->update($request->validated());

        return back()->with('success', 'Territory updated.');
    }

    public function destroy(Territory $territory): RedirectResponse
    {
        $territory->delete();

        return back()->with('success', 'Territory deleted.');
    }
}
