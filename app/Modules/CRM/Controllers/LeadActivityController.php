<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Requests\StoreLeadActivityRequest;
use App\Modules\CRM\Services\LeadService;

class LeadActivityController extends Controller
{
    public function __construct(
        protected LeadService $service,
    ) {}

    public function store(StoreLeadActivityRequest $request, Lead $lead)
    {
        $data = $request->validated();
        $this->service->logActivity($lead, $data['activity_type'], $data['body'] ?? null);

        return back()->with('success', 'Activity logged.');
    }
}
