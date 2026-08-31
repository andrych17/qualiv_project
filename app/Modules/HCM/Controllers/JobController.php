<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Job;
use App\Modules\HCM\Requests\StoreJobRequest;
use App\Modules\HCM\Services\OrgStructureService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3C job title/catalog CRUD (HCM_SPECS.md — "hcm.jobs — job title/catalog (master),
 * independent of any specific person filling it"). OrgStructureService already had full
 * create/update/delete/paginate support for this; only the controller/route/page front door
 * was missing — this is that front door, mirroring OrgUnitController's own shape. Sidebar
 * calls this "Designations", the common HR term for a job title.
 */
class JobController extends Controller
{
    use BulkDeletable;

    public function __construct(
        protected OrgStructureService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'is_active', 'sort', 'direction', 'per_page');
        $perPage = TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15);
        $jobs = $this->service->paginateJobs($filters, $perPage);

        return Inertia::render('HCM/Jobs/Index', [
            'jobs' => $jobs,
            'filters' => $filters,
        ]);
    }

    public function store(StoreJobRequest $request): RedirectResponse
    {
        $this->service->createJob($request->validated());

        return back()->with('success', 'Designation created.');
    }

    public function update(StoreJobRequest $request, Job $job): RedirectResponse
    {
        $this->service->updateJob($job, $request->validated());

        return back()->with('success', 'Designation updated.');
    }

    public function destroy(Job $job): RedirectResponse
    {
        $this->service->deleteJob($job);

        return back()->with('success', 'Designation deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        return $this->bulkDestroyUsing($request, Job::class, fn (Job $j) => $this->service->deleteJob($j));
    }
}
