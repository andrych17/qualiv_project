<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\CRM\Requests\StoreContactRequest;
use App\Modules\CRM\Requests\UpdateContactRequest;
use App\Modules\CRM\Services\PartnerService;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
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

        $contacts = Partner::query()
            ->contacts()
            ->with('parent:id,name')
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
                'title_position' => $p->title_position,
                'parent_name' => $p->parent?->name,
                'is_active' => $p->is_active,
                'created_at_formatted' => $p->created_at?->format('d M Y'),
            ]);

        return Inertia::render('CRM/Contacts/Index', [
            'contacts' => $contacts,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CRM/Contacts/Create', [
            ...$this->formProps(),
            'customFields' => $this->customFields->formPayload(PartnerService::ENTITY),
        ]);
    }

    public function store(StoreContactRequest $request)
    {
        $this->service->create($request->validated(), Partner::TYPE_INDIVIDUAL);

        return redirect()->route('crm.contacts.index')->with('success', 'Contact created.');
    }

    public function edit(Partner $contact): Response
    {
        return Inertia::render('CRM/Contacts/Edit', [
            ...$this->formProps(),
            'contact' => $this->toFormData($contact),
            'customFields' => $this->customFields->formPayload(PartnerService::ENTITY, $contact->id),
        ]);
    }

    public function update(UpdateContactRequest $request, Partner $contact)
    {
        $this->service->update($contact, $request->validated());

        return redirect()->route('crm.contacts.index')->with('success', 'Contact updated.');
    }

    public function destroy(Partner $contact)
    {
        $this->service->deactivate($contact);

        return redirect()->route('crm.contacts.index')->with('success', 'Contact deactivated.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Partner::class, fn (Partner $p) => $this->service->deactivate($p));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'roleTypes' => PartnerRoleType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(Partner $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'title_position' => $contact->title_position,
            'parent_partner_id' => $contact->parent_partner_id,
            'parent' => $contact->parent ? ['value' => $contact->parent->id, 'label' => $contact->parent->name] : null,
            'owner_id' => $contact->owner_id,
            'tags' => implode(', ', $contact->tags ?? []),
            'is_active' => $contact->is_active,
            'role_type_ids' => $contact->roles()->pluck('role_type_id'),
            'addresses' => $contact->addresses()->get(),
            'contact_points' => $contact->contactPoints()->get(),
        ];
    }
}
