<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Requests\StoreServiceCaseActivityRequest;
use App\Modules\CRM\Services\ServiceCaseService;

class ServiceCaseActivityController extends Controller
{
    public function __construct(
        protected ServiceCaseService $service,
    ) {}

    public function store(StoreServiceCaseActivityRequest $request, ServiceCase $serviceCase)
    {
        $this->service->addNote($serviceCase, $request->validated()['body']);

        return back()->with('success', 'Note added.');
    }
}
