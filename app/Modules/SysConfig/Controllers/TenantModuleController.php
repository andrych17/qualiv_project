<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SysConfig\Services\TenantModuleService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantModuleController extends Controller
{
    public function __construct(
        protected TenantModuleService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Config/Modules/Index', [
            'modules' => $this->service->catalog(),
        ]);
    }

    public function update(Request $request, string $module)
    {
        $data = $request->validate([
            'is_active' => 'required|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->service->toggle($module, (bool) $data['is_active'], $data['notes'] ?? null);

        return back()->with('success', 'Module visibility updated.');
    }
}
