<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Ticket;
use App\Modules\CRM\Requests\StoreTicketMessageRequest;
use App\Modules\CRM\Services\TicketService;

class TicketMessageController extends Controller
{
    public function __construct(
        protected TicketService $service,
    ) {}

    public function store(StoreTicketMessageRequest $request, Ticket $ticket)
    {
        $data = $request->validated();
        $this->service->addMessage($ticket, $data['direction'], $data['body'], senderId: auth()->id());

        return back()->with('success', 'Message sent.');
    }
}
