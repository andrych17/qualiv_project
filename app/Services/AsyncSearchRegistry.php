<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AsyncSearchRegistry
{
    /**
     * @var array<string, array{modelClass: class-string<Model>, searchFields: array, labelField: string|callable, descriptionField: string|callable|null, badgeField: string|callable|null}>
     */
    protected static array $registry = [];

    /**
     * Register a searchable entity.
     *
     * @param string $key Entity key (e.g. 'users', 'legal_cases', 'partners')
     * @param class-string<Model> $modelClass Eloquent Model class
     * @param array<string> $searchFields Columns to perform search on
     * @param string|callable $labelField Column name or callback for option label
     * @param string|callable|null $descriptionField Column name or callback for option description
     * @param string|callable|null $badgeField Column name or callback for status badge
     */
    public static function register(
        string $key,
        string $modelClass,
        array $searchFields = ['name'],
        string|callable $labelField = 'name',
        string|callable|null $descriptionField = null,
        string|callable|null $badgeField = null
    ): void {
        static::$registry[$key] = [
            'modelClass' => $modelClass,
            'searchFields' => $searchFields,
            'labelField' => $labelField,
            'descriptionField' => $descriptionField,
            'badgeField' => $badgeField,
        ];
    }

    /**
     * Check if an entity is registered.
     */
    public static function has(string $key): boolean
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

        // Apply extra filters (excluding system params)
        foreach ($extraFilters as $col => $val) {
            if ($val !== null && $val !== '' && ! in_array($col, ['q', 'entity', 'limit', 'selected_id', 'page'])) {
                $query->where($col, $val);
            }
        }

        // Search query across configured searchFields
        if ($search !== '') {
            $query->where(function (Builder $q) use ($config, $search) {
                foreach ($config['searchFields'] as $index => $field) {
                    if ($index === 0) {
                        $q->where($field, 'ilike', "%{$search}%");
                    } else {
                        $q->orWhere($field, 'ilike', "%{$search}%");
                    }
                }
            });
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
