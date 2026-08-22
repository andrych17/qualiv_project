<?php

namespace App\Modules\DMS\Services;

use App\Modules\DMS\Models\Folder;
use Illuminate\Validation\ValidationException;

/** §3D Folder / Category Management — tree CRUD with a non-destructive delete guard. */
class FolderService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data, int $userId): Folder
    {
        return Folder::query()->create([...$data, 'created_by' => $userId]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Folder $folder, array $data): Folder
    {
        $folder->update($data);

        return $folder->refresh();
    }

    /** §3D: "deleting a non-empty folder requires reassigning or archiving its documents first." */
    public function delete(Folder $folder): void
    {
        $documentCount = $folder->documents()->count();
        if ($documentCount > 0) {
            throw ValidationException::withMessages([
                'folder' => "This folder has {$documentCount} document(s) — reassign or archive them first.",
            ]);
        }

        $childCount = $folder->children()->count();
        if ($childCount > 0) {
            throw ValidationException::withMessages([
                'folder' => "This folder has {$childCount} subfolder(s) — delete or move them first.",
            ]);
        }

        $folder->delete();
    }
}
