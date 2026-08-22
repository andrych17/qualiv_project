<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\Will;
use App\Modules\Legal\Requests\RegisterDpwRequest;
use App\Modules\Legal\Requests\StoreWillRequest;
use App\Modules\Legal\Requests\WillNoteRequest;
use App\Modules\Legal\Services\WillService;

class WillController extends Controller
{
    public function __construct(
        protected WillService $service,
    ) {}

    public function store(StoreWillRequest $request, Deed $deed)
    {
        $this->service->create($deed, $request->validated()['testator_partner_id']);

        return back()->with('success', 'Will record created.');
    }

    public function registerDpw(RegisterDpwRequest $request, Deed $deed, Will $will)
    {
        $data = $request->validated();
        $this->service->registerDpw($will, $data['dpw_reg_number'], $data['dpw_registered_at'] ?? null);

        return back()->with('success', 'Registered with DPW.');
    }

    public function activate(Deed $deed, Will $will)
    {
        $this->service->activate($will);

        return back()->with('success', 'Will activated.');
    }

    public function open(WillNoteRequest $request, Deed $deed, Will $will)
    {
        $this->service->open($will, $request->validated()['notes']);

        return back()->with('success', 'Will marked opened.');
    }

    public function revoke(WillNoteRequest $request, Deed $deed, Will $will)
    {
        $this->service->revoke($will, $request->validated()['notes']);

        return back()->with('success', 'Will revoked.');
    }
}
