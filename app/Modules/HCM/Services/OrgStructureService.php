<?php

namespace App\Modules\HCM\Services;

use App\Modules\HCM\Models\Job;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\HCM\Models\Position;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class OrgStructureService
{
    // --- OrgUnits ---
    public function paginateOrgUnits(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return OrgUnit::query()
            ->with(['parent', 'children'])
            ->filter($filters)
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function allOrgUnits(): Collection
    {
        return OrgUnit::query()->with('parent')->where('is_active', true)->orderBy('name')->get();
    }

    public function createOrgUnit(array $data): OrgUnit
    {
        return OrgUnit::create($data);
    }

    public function updateOrgUnit(OrgUnit $orgUnit, array $data): OrgUnit
    {
        $orgUnit->update($data);

        return $orgUnit;
    }

    public function deleteOrgUnit(OrgUnit $orgUnit): void
    {
        $orgUnit->delete();
    }

    // --- Jobs ---
    public function paginateJobs(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Job::query()
            ->filter($filters)
            ->orderBy('title')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function allJobs(): Collection
    {
        return Job::query()->where('is_active', true)->orderBy('title')->get();
    }

    public function createJob(array $data): Job
    {
        return Job::create($data);
    }

    public function updateJob(Job $job, array $data): Job
    {
        $job->update($data);

        return $job;
    }

    public function deleteJob(Job $job): void
    {
        $job->delete();
    }

    // --- Positions ---
    public function paginatePositions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Position::query()
            ->with(['job', 'orgUnit', 'reportsTo.job'])
            ->filter($filters)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function allPositions(): Collection
    {
        return Position::query()
            ->with(['job', 'orgUnit'])
            ->where('is_active', true)
            ->get();
    }

    public function createPosition(array $data): Position
    {
        return Position::create($data);
    }

    public function updatePosition(Position $position, array $data): Position
    {
        $position->update($data);

        return $position->load(['job', 'orgUnit', 'reportsTo.job']);
    }

    public function deletePosition(Position $position): void
    {
        $position->delete();
    }
}
