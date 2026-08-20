<?php

namespace App\Modules\CustomFields\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Models\FieldDef;
use App\Modules\CustomFields\Requests\StoreFieldDefRequest;
use App\Modules\CustomFields\Requests\UpdateFieldDefRequest;
use App\Modules\CustomFields\Services\FieldDefService;
use App\Modules\SysConfig\Models\TenantModule;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FieldDefController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['module_code', 'entity_type', 'code', 'label', 'field_type', 'seq', 'status'];

    public function __construct(
        protected FieldDefService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'module_code', 'entity_type', 'show_inactive', 'sort', 'direction', 'per_page');

        $defs = FieldDef::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'entity_type'),
                fn ($query) => $query->orderBy('module_code')->orderBy('entity_type')->orderBy('seq'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (FieldDef $d) => [
                'id' => $d->id,
                'module_code' => $d->module_code,
                'entity_type' => $d->entity_type,
                'code' => $d->code,
                'label' => $d->label,
                'field_type' => $d->field_type,
                'is_required' => $d->is_required,
                'seq' => $d->seq,
                'status' => $d->status,
            ]);

        $entityTypes = FieldDef::query()
            ->select('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->map(fn ($t) => ['label' => $t, 'value' => $t])
            ->values()
            ->all();

        $modules = collect(TenantModule::TOGGLEABLE)
            ->map(fn ($c) => ['label' => $c, 'value' => $c])
            ->values()
            ->all();

        return Inertia::render('Config/Fields/Index', [
            'defs' => $defs,
            'filters' => $filters,
            'entityTypes' => $entityTypes,
            'modules' => $modules,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Config/Fields/Create', $this->formMeta());
    }

    public function store(StoreFieldDefRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('config.fields.index')
            ->with('success', 'Field definition created.');
    }

    public function edit(FieldDef $fieldDef): Response
    {
        return Inertia::render('Config/Fields/Edit', [
            'def' => [
                'id' => $fieldDef->id,
                'entity_type' => $fieldDef->entity_type,
                'module_code' => $fieldDef->module_code,
                'code' => $fieldDef->code,
                'label' => $fieldDef->label,
                'field_type' => $fieldDef->field_type,
                'options' => $fieldDef->options,
                'is_required' => $fieldDef->is_required,
                'seq' => $fieldDef->seq,
                'status' => $fieldDef->status,
            ],
            ...$this->formMeta(),
        ]);
    }

    public function update(UpdateFieldDefRequest $request, FieldDef $fieldDef)
    {
        $this->service->update($fieldDef, $request->validated());

        return redirect()->route('config.fields.index')
            ->with('success', 'Field definition updated.');
    }

    public function destroy(FieldDef $fieldDef)
    {
        $this->service->delete($fieldDef);

        return redirect()->route('config.fields.index')
            ->with('success', 'Field definition deactivated.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, FieldDef::class, fn (FieldDef $def) => $this->service->delete($def));
    }

    /** @return array{entityTypes: list<array{label: string, value: string}>, modules: list<array{label: string, value: string}>} */
    private function formMeta(): array
    {
        return [
            'entityTypes' => FieldDef::query()
                ->select('entity_type')
                ->distinct()
                ->orderBy('entity_type')
                ->pluck('entity_type')
                ->map(fn ($t) => ['label' => $t, 'value' => $t])
                ->values()
                ->all(),
            'modules' => collect(TenantModule::TOGGLEABLE)
                ->map(fn ($c) => ['label' => $c, 'value' => $c])
                ->values()
                ->all(),
        ];
    }
}
