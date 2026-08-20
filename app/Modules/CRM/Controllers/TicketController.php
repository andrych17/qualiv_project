<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CRM\Models\Ticket;
use App\Modules\CRM\Models\TicketCategory;
use App\Modules\CRM\Requests\RelinkTicketRequest;
use App\Modules\CRM\Requests\StoreTicketRequest;
use App\Modules\CRM\Requests\UpdateTicketRequest;
use App\Modules\CRM\Requests\UpdateTicketStatusRequest;
use App\Modules\CRM\Services\TicketService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    private const SORTABLE = ['subject', 'created_at', 'sla_due_at'];

    public function __construct(
        protected TicketService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'priority', 'sla_state', 'assigned_to', 'channel', 'sort', 'direction', 'per_page');

        $tickets = Ticket::query()
            ->with(['partner:id,name', 'category:id,name', 'assignedTo:id,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Ticket $t) => [
                'id' => $t->id,
                'subject' => $t->subject,
                'requester_name' => $t->requesterLabel(),
                'category_name' => $t->category?->name,
                'priority' => $t->priority,
                'status' => $t->status,
                'channel' => $t->channel,
                'assigned_to_name' => $t->assignedTo?->name,
                'sla_due_at_formatted' => $t->sla_due_at?->format('d M Y H:i'),
                'sla_state' => $t->sla_state,
                'created_at_formatted' => $t->created_at?->format('d M Y'),
            ]);

        return Inertia::render('CRM/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CRM/Tickets/Create', $this->formProps());
    }

    public function store(StoreTicketRequest $request)
    {
        $ticket = $this->service->create($request->validated());

        return redirect()->route('crm.tickets.edit', $ticket->id)->with('success', 'Ticket opened.');
    }

    public function edit(Ticket $ticket): Response
    {
        return Inertia::render('CRM/Tickets/Edit', [
            ...$this->formProps(),
            'ticket' => [
                'id' => $ticket->id,
                'partner_id' => $ticket->partner_id,
                'partner' => $ticket->partner ? ['value' => $ticket->partner->id, 'label' => $ticket->partner->name] : null,
                'requester_name' => $ticket->requester_name,
                'requester_contact' => $ticket->requester_contact,
                'subject' => $ticket->subject,
                'category_id' => $ticket->category_id,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'assigned_to' => $ticket->assigned_to,
                'sla_due_at' => $ticket->sla_due_at?->format('Y-m-d\TH:i'),
                'channel' => $ticket->channel,
                'sla_state' => $ticket->sla_state,
                'messages' => $ticket->messages()->with('sender:id,name')->get()->map(fn ($m) => [
                    'id' => $m->id,
                    'direction' => $m->direction,
                    'body' => $m->body,
                    'sender_name' => $m->sender?->name ?? $m->sender_name,
                    'sent_at_formatted' => $m->sent_at?->format('d M Y H:i'),
                ]),
            ],
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $this->service->update($ticket, $request->validated());

        return redirect()->route('crm.tickets.index')->with('success', 'Ticket updated.');
    }

    public function updateStatus(UpdateTicketStatusRequest $request, Ticket $ticket)
    {
        $this->service->updateStatus($ticket, $request->validated()['status']);

        return back()->with('success', 'Ticket status updated.');
    }

    public function relink(RelinkTicketRequest $request, Ticket $ticket)
    {
        $this->service->relinkPartner($ticket, (int) $request->validated()['partner_id']);

        return back()->with('success', 'Ticket re-linked to partner.');
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
