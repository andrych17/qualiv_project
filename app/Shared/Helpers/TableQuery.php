<?php

namespace App\Shared\Helpers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class TableQuery
{
    /**
     * Apply a client-requested sort, whitelisted against known columns.
     *
     * $sort comes straight from the request query string — never pass it to
     * orderBy() unchecked, since that's a SQL injection vector (column names
     * can't be parameterized like values can).
     *
     * @param  list<string>  $sortable  Column names allowed to be sorted on.
     */
    public static function applySort(
        EloquentBuilder|QueryBuilder $query,
        ?string $sort,
        ?string $direction,
        array $sortable,
        string $defaultColumn,
        string $defaultDirection = 'asc',
    ): void {
        if ($sort !== null && in_array($sort, $sortable, true)) {
            $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');

            return;
        }

        $query->orderBy($defaultColumn, $defaultDirection);
    }
}
