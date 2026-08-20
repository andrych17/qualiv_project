<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\Industry;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\CRM\Requests\StoreCompanyRequest;
use App\Modules\CRM\Requests\UpdateCompanyRequest;
use App\Modules\CRM\Services\PartnerService;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['name', 'created_at'];

    public function __construct(
        protected PartnerService $service,
        protected CustomFieldService $customFields,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $companies = Partner::query()
            ->companies()
            ->with('industry:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Partner $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'name' => $p->name,
                'trade_name' => $p->trade_name,
                'industry_name' => $p->industry?->name,
                'is_active' => $p->is_active,
                'created_at_formatted' => $p->created_at?->format('d M Y'),
            ]);

        return Inertia::render('CRM/Companies/Index', [
            'companies' => $companies,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CRM/Companies/Create', [
            ...$this->formProps(),
            'customFields' => $this->customFields->formPayload(PartnerService::ENTITY),
        ]);
    }

    public function store(StoreCompanyRequest $request)
    {
        $this->service->create($request->validated(), Partner::TYPE_ORGANIZATION);

        return redirect()->route('crm.companies.index')->with('success', 'Company created.');
    }

    public function edit(Partner $company): Response
    {
        return Inertia::render('CRM/Companies/Edit', [
            ...$this->formProps(),
            'company' => $this->toFormData($company),
            'customFields' => $this->customFields->formPayload(PartnerService::ENTITY, $company->id),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Partner $company)
    {
        $this->service->update($company, $request->validated());

        return redirect()->route('crm.companies.index')->with('success', 'Company updated.');
    }

    public function destroy(Partner $company)
    {
        $this->service->deactivate($company);

        return redirect()->route('crm.companies.index')->with('success', 'Company deactivated.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Partner::class, fn (Partner $p) => $this->service->deactivate($p));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'industries' => Industry::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roleTypes' => PartnerRoleType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(Partner $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'trade_name' => $company->trade_name,
            'registration_tax_id' => $company->registration_tax_id,
            'industry_id' => $company->industry_id,
            'parent_partner_id' => $company->parent_partner_id,
            'parent' => $company->parent ? ['value' => $company->parent->id, 'label' => $company->parent->name] : null,
            'owner_id' => $company->owner_id,
            'tags' => implode(', ', $company->tags ?? []),
            'is_active' => $company->is_active,
            'role_type_ids' => $company->roles()->pluck('role_type_id'),
            'addresses' => $company->addresses()->get(),
            'contact_points' => $company->contactPoints()->get(),
            'contacts' => $company->children()->contacts()->get(['id', 'name', 'title_position']),
        ];
    }
}
