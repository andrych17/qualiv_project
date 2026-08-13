<?php

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\Project;

class ProjectService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Project
    {
        return Project::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->refresh();
    }

    public function delete(Project $project): void
    {
        // Issues/comments cascade at the DB level (FK cascadeOnDelete) — no app-level fan-out needed.
        $project->delete();
    }
}
