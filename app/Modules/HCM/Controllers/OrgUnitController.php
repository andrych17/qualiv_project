<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\HCM\Requests\StoreOrgUnitRequest;
use App\Modules\HCM\Services\OrgStructureService;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrgUnitController extends Controller
{
    use BulkDeletable;

    public function __construct(
        protected OrgStructureService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'is_active');
        $orgUnits = $this->service->paginateOrgUnits($filters, 20);

        return Inertia::render('HCM/OrgUnits/Index', [
            'orgUnits' => $orgUnits,
            'allOrgUnits' => $this->service->allOrgUnits(),
            'filters' => $filters,
        ]);
    }

    public function store(StoreOrgUnitRequest $request): RedirectResponse
    {
        $this->service->createOrgUnit($request->validated());

        return back()->with('success', 'Organizational Unit created.');
    }

    public function update(StoreOrgUnitRequest $request, OrgUnit $orgUnit): RedirectResponse
    {
        $this->service->updateOrgUnit($orgUnit, $request->validated());

        return back()->with('success', 'Organizational Unit updated.');
    }

    public function destroy(OrgUnit $orgUnit): RedirectResponse
    {
        $this->service->deleteOrgUnit($orgUnit);

        return back()->with('success', 'Organizational Unit deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        return $this->bulkDestroyUsing($request, OrgUnit::class, fn (OrgUnit $u) => $this->service->deleteOrgUnit($u));
    }
}
