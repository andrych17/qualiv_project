<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Central\Models\CentralTenantAddon;
use App\Modules\Central\Requests\StoreAddonRequest;
use App\Modules\Central\Services\CentralTenantService;

class AddonController extends Controller
{
    public function __construct(
        protected CentralTenantService $service,
    ) {}

    public function store(StoreAddonRequest $request, Tenant $tenant)
    {
        $this->service->addAddon($tenant, $request->string('module_code')->value(), $request->float('price_override') ?: null);

        return redirect()->route('central.tenants.edit', $tenant)->with('success', 'Addon added.');
    }

    public function destroy(Tenant $tenant, CentralTenantAddon $addon)
    {
        $this->service->removeAddon($addon);

        return redirect()->route('central.tenants.edit', $tenant)->with('success', 'Addon removed.');
    }
}
