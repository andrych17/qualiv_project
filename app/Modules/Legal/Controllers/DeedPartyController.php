<?php

namespace App\Modules\Legal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedParty;
use App\Modules\Legal\Requests\StoreDeedPartyRequest;
use App\Modules\Legal\Requests\UpdateDeedPartyRequest;
use App\Modules\Legal\Services\DeedPartyService;

class DeedPartyController extends Controller
{
    public function __construct(
        protected DeedPartyService $service,
    ) {}

    public function store(StoreDeedPartyRequest $request, Deed $deed)
    {
        $this->service->add($deed, $request->validated());

        return back()->with('success', 'Party added.');
    }

    public function update(UpdateDeedPartyRequest $request, Deed $deed, DeedParty $party)
    {
        $this->service->update($party, $request->validated());

        return back()->with('success', 'Party updated.');
    }

    public function destroy(Deed $deed, DeedParty $party)
    {
        $this->service->remove($party);

        return back()->with('success', 'Party removed.');
    }
}
