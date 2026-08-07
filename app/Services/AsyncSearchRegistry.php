<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AsyncSearchRegistry
{
    /**
     * @var array<string, array{modelClass: class-string<Model>, searchFields: array, labelField: string|callable, descriptionField: string|callable|null, badgeField: string|callable|null, queryCallback: callable|null, filterable: array<string>, menuCode: string|null}>
     */
    protected static array $registry = [];

    /**
     * Register a searchable entity.
     *
     * @param  string  $key  Entity key (e.g. 'users', 'legal_cases', 'partners')
     * @param  class-string<Model>  $modelClass  Eloquent Model class
     * @param  array<string>  $searchFields  Columns to perform search on
     * @param  string|callable  $labelField  Column name or callback for option label
     * @param  string|callable|null  $descriptionField  Column name or callback for option description
     * @param  string|callable|null  $badgeField  Column name or callback for status badge
     * @param  callable|null  $queryCallback  Optional custom query builder callback for JOINs, scopes, or complex logic
     * @param  array<string>  $filterable  Column allowlist for `where($col, $val)` filters sent via extra query params. Empty by default — extra params are ignored unless a column is explicitly listed here.
     * @param  string|null  $menuCode  SYSCONFIG menu code gating this entity — caller needs `read` on it (see AsyncSearchController). Null means auth-only (no extra gate).
     */
    public static function register(
        string $key,
        string $modelClass,
        array $searchFields = ['name'],
        string|callable $labelField = 'name',
        string|callable|null $descriptionField = null,
        string|callable|null $badgeField = null,
        ?callable $queryCallback = null,
        array $filterable = [],
        ?string $menuCode = null
    ): void {
        static::$registry[$key] = [
            'modelClass' => $modelClass,
            'searchFields' => $searchFields,
            'labelField' => $labelField,
            'descriptionField' => $descriptionField,
            'badgeField' => $badgeField,
            'queryCallback' => $queryCallback,
            'filterable' => $filterable,
            'menuCode' => $menuCode,
        ];
    }

    /**
     * The SYSCONFIG menu code gating this entity, if any.
     */
    public static function menuCodeFor(string $key): ?string
    {
        return static::$registry[$key]['menuCode'] ?? null;
    }

    /**
     * Check if an entity is registered.
     */
    public static function has(string $key): bool
    {
        return isset(static::$registry[$key]);
    }

    /**
     * Get all registered keys.
     *
     * @return array<string>
     */
    public static function registeredKeys(): array
    {
        return array_keys(static::$registry);
    }

    /**
     * Search an entity with a strict limit of 50 items.
     */
    public static function search(string $key, string $search, int $limit = 50, array $extraFilters = []): array
    {
        $config = static::$registry[$key] ?? null;
        if (! $config) {
            return ['items' => [], 'total' => 0];
        }

        $modelClass = $config['modelClass'];
        $query = $modelClass::query();

        // Custom queryCallback for complex JOINs, scopes, or advanced logic
        if (isset($config['queryCallback']) && is_callable($config['queryCallback'])) {
            ($config['queryCallback'])($query, $search, $extraFilters);
        } else {
            // Apply extra filters — only columns explicitly allowlisted via register()'s
            // $filterable, never an arbitrary column name taken straight from the query string.
            foreach ($extraFilters as $col => $val) {
                if ($val !== null && $val !== '' && in_array($col, $config['filterable'], true)) {
                    $query->where($col, $val);
                }
            }

            // Default search query across configured searchFields using uppercase column & parameter matching
            if ($search !== '') {
                $searchUpper = mb_strtoupper($search, 'UTF-8');
                $query->where(function (Builder $q) use ($config, $searchUpper) {
                    foreach ($config['searchFields'] as $index => $field) {
                        $sql = "UPPER({$field}::text) LIKE ?";
                        $param = "%{$searchUpper}%";
                        if ($index === 0) {
                            $q->whereRaw($sql, [$param]);
                        } else {
                            $q->orWhereRaw($sql, [$param]);
                        }
                    }
                });
            }
        }

        $total = $query->count();
        $limit = min(max($limit, 1), 50);

        $results = $query->limit($limit)->get();

        $items = $results->map(function (Model $model) use ($config) {
            $label = is_callable($config['labelField'])
                ? ($config['labelField'])($model)
                : $model->getAttribute($config['labelField']) ?? (string) $model->getKey();

            $description = null;
            if ($config['descriptionField']) {
                $description = is_callable($config['descriptionField'])
                    ? ($config['descriptionField'])($model)
                    : $model->getAttribute($config['descriptionField']);
            }

            $badge = null;
            if ($config['badgeField']) {
                $badge = is_callable($config['badgeField'])
                    ? ($config['badgeField'])($model)
                    : $model->getAttribute($config['badgeField']);
            }

            return [
                'value' => $model->getKey(),
                'label' => (string) $label,
                'description' => $description ? (string) $description : null,
                'avatar' => strtoupper(substr((string) $label, 0, 1)),
                'badge' => $badge ? (string) $badge : null,
            ];
        });

        return [
            'items' => $items->values()->all(),
            'total' => $total,
        ];
    }

    /**
     * Find a single entity item by ID.
     */
    public static function find(string $key, mixed $id): ?array
    {
        $config = static::$registry[$key] ?? null;
        if (! $config) {
            return null;
        }

        $model = ($config['modelClass'])::find($id);
        if (! $model) {
            return null;
        }

        $label = is_callable($config['labelField'])
            ? ($config['labelField'])($model)
            : $model->getAttribute($config['labelField']) ?? (string) $model->getKey();

        $description = null;
        if ($config['descriptionField']) {
            $description = is_callable($config['descriptionField'])
                ? ($config['descriptionField'])($model)
                : $model->getAttribute($config['descriptionField']);
        }

        $badge = null;
        if ($config['badgeField']) {
            $badge = is_callable($config['badgeField'])
                ? ($config['badgeField'])($model)
                : $model->getAttribute($config['badgeField']);
        }

        return [
            'value' => $model->getKey(),
            'label' => (string) $label,
            'description' => $description ? (string) $description : null,
            'avatar' => strtoupper(substr((string) $label, 0, 1)),
            'badge' => $badge ? (string) $badge : null,
        ];
    }
}
