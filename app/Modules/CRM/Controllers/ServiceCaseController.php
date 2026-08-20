<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\TicketCategory;
use App\Modules\CRM\Requests\StoreServiceCaseRequest;
use App\Modules\CRM\Requests\UpdateServiceCaseRequest;
use App\Modules\CRM\Requests\UpdateServiceCaseStatusRequest;
use App\Modules\CRM\Services\ServiceCaseService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceCaseController extends Controller
{
    private const SORTABLE = ['subject', 'created_at', 'sla_due_at'];

    public function __construct(
        protected ServiceCaseService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'priority', 'sla_state', 'assigned_to', 'sort', 'direction', 'per_page');

        $cases = ServiceCase::query()
            ->with(['partner:id,name,type', 'category:id,name', 'assignedTo:id,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (ServiceCase $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'partner_name' => $c->partner?->name,
                'category_name' => $c->category?->name,
                'priority' => $c->priority,
                'status' => $c->status,
                'assigned_to_name' => $c->assignedTo?->name,
                'sla_due_at_formatted' => $c->sla_due_at?->format('d M Y H:i'),
                'sla_state' => $c->sla_state,
                'created_at_formatted' => $c->created_at?->format('d M Y'),
            ]);

        return Inertia::render('CRM/ServiceCases/Index', [
            'cases' => $cases,
            'filters' => $filters,
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CRM/ServiceCases/Create', $this->formProps());
    }

    public function store(StoreServiceCaseRequest $request)
    {
        $case = $this->service->create($request->validated());

        return redirect()->route('crm.serviceCases.edit', $case->id)->with('success', 'Service case opened.');
    }

    public function edit(ServiceCase $serviceCase): Response
    {
        return Inertia::render('CRM/ServiceCases/Edit', [
            ...$this->formProps(),
            'serviceCase' => [
                'id' => $serviceCase->id,
                'partner_id' => $serviceCase->partner_id,
                'partner' => $serviceCase->partner ? ['value' => $serviceCase->partner->id, 'label' => $serviceCase->partner->name] : null,
                'subject' => $serviceCase->subject,
                'category_id' => $serviceCase->category_id,
                'priority' => $serviceCase->priority,
                'status' => $serviceCase->status,
                'assigned_to' => $serviceCase->assigned_to,
                'sla_due_at' => $serviceCase->sla_due_at?->format('Y-m-d\TH:i'),
                'subject_type' => $serviceCase->subject_type,
                'subject_id' => $serviceCase->subject_id,
                'sla_state' => $serviceCase->sla_state,
                'can_reopen' => $serviceCase->canReopen(),
                'closed_at_formatted' => $serviceCase->closed_at?->format('d M Y H:i'),
                'activities' => $serviceCase->activities()->with('loggedBy:id,name')->get()->map(fn ($a) => [
                    'id' => $a->id,
                    'activity_type' => $a->activity_type,
                    'body' => $a->body,
                    'logged_by_name' => $a->loggedBy?->name,
                    'logged_at_formatted' => $a->logged_at?->format('d M Y H:i'),
                ]),
            ],
        ]);
    }

    public function update(UpdateServiceCaseRequest $request, ServiceCase $serviceCase)
    {
        $this->service->update($serviceCase, $request->validated());

        return redirect()->route('crm.serviceCases.index')->with('success', 'Service case updated.');
    }

    public function updateStatus(UpdateServiceCaseStatusRequest $request, ServiceCase $serviceCase)
    {
        $this->service->updateStatus($serviceCase, $request->validated()['status']);

        return back()->with('success', 'Case status updated.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'categories' => TicketCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
