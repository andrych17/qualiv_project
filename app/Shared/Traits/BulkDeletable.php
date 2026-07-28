<?php

namespace App\Shared\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait BulkDeletable
{
    /**
     * Validate a list of ids and run $delete against each matching model,
     * reusing the resource's normal single-delete Service method so cascades
     * and audit logic stay in one place instead of duplicating them here.
     *
     * @param  class-string<Model>  $modelClass
     * @param  callable(Model): void  $delete
     */
    protected function bulkDestroyUsing(Request $request, string $modelClass, callable $delete): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        $count = DB::transaction(function () use ($modelClass, $ids, $delete) {
            $models = $modelClass::query()->whereIn('id', $ids)->get();
            $models->each($delete);

            return $models->count();
        });

        return back()->with('success', "{$count} item(s) deleted.");
    }
}
