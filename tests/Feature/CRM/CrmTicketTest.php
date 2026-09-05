<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\Ticket;
use App\Modules\CRM\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpCrm;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F — Helpdesk: partner-optional, conversation-first tickets. */
class CrmTicketTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpCrm;
    use SetsUpTenant;

    public function test_admin_can_crud_a_partner_linked_ticket_change_status_and_message(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $partnerId = null;
        $categoryId = null;
        $tenant->run(function () use (&$partnerId, &$categoryId) {
            $partnerId = $this->makeCompany()->id;
            $categoryId = $this->makeTicketCategory()->id;
        });

        $this->get('/crm/tickets')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/Tickets/Index'));
        $this->get('/crm/tickets/create')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/Tickets/Create'));

        $this->post('/crm/tickets', [
            'partner_id' => $partnerId,
            'subject' => 'Login broken',
            'message' => "I can't log in.",
            'category_id' => $categoryId,
            'priority' => 'high',
            'channel' => 'web_form',
        ])->assertRedirect();

        $ticketId = null;
        $tenant->run(function () use (&$ticketId) {
            $ticket = Ticket::query()->where('subject', 'Login broken')->first();
            $this->assertSame(Ticket::STATUS_OPEN, $ticket->status);
            $first = TicketMessage::query()->where('ticket_id', $ticket->id)->first();
            $this->assertSame(TicketMessage::DIRECTION_INBOUND, $first->direction);
            $this->assertSame("I can't log in.", $first->body);
            $ticketId = $ticket->id;
        });

        $this->get("/crm/tickets/{$ticketId}/edit")->assertOk()->assertInertia(fn ($page) => $page
            ->component('CRM/Tickets/Edit')
            ->where('ticket.subject', 'Login broken')
            ->has('ticket.messages', 1));

        $this->put("/crm/tickets/{$ticketId}", [
            'partner_id' => $partnerId,
            'subject' => 'Login broken (P1)',
            'priority' => 'urgent',
            'channel' => 'web_form',
        ])->assertRedirect(route('crm.tickets.index'));

        $tenant->run(function () use ($ticketId) {
            $this->assertSame('Login broken (P1)', Ticket::query()->find($ticketId)->subject);
        });

        $this->post("/crm/tickets/{$ticketId}/messages", [
            'direction' => 'outbound',
            'body' => 'Please try resetting your password.',
        ])->assertRedirect();

        $tenant->run(function () use ($ticketId) {
            $this->assertSame(2, TicketMessage::query()->where('ticket_id', $ticketId)->count());
        });

        $this->patch("/crm/tickets/{$ticketId}/status", ['status' => Ticket::STATUS_RESOLVED])->assertRedirect();
        $tenant->run(function () use ($ticketId) {
            $ticket = Ticket::query()->find($ticketId);
            $this->assertSame(Ticket::STATUS_RESOLVED, $ticket->status);
            // updateStatus logs an internal note recording the transition.
            $this->assertSame(3, TicketMessage::query()->where('ticket_id', $ticketId)->count());
        });

        $newPartnerId = null;
        $tenant->run(function () use (&$newPartnerId) {
            $newPartnerId = $this->makeCompany('Reassigned Co')->id;
        });
        $this->post("/crm/tickets/{$ticketId}/relink", ['partner_id' => $newPartnerId])->assertRedirect();
        $tenant->run(function () use ($ticketId, $newPartnerId) {
            $this->assertSame($newPartnerId, Ticket::query()->find($ticketId)->partner_id);
        });
    }

    public function test_a_ticket_can_be_opened_with_only_a_free_text_requester_and_defaults_apply(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $this->post('/crm/tickets', [
            'requester_name' => 'Walk-in Caller',
            'subject' => 'Product question',
            'message' => 'Does this come in blue?',
        ])->assertRedirect();

        $tenant->run(function () {
            $ticket = Ticket::query()->where('subject', 'Product question')->first();
            $this->assertNull($ticket->partner_id);
            $this->assertSame('Walk-in Caller', $ticket->requester_name);
            $this->assertSame('normal', $ticket->priority);
            $this->assertSame('email', $ticket->channel);
            $this->assertSame('Walk-in Caller', $ticket->requesterLabel());
        });
    }

    public function test_requester_label_falls_back_to_unknown_when_neither_partner_nor_name_is_set(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $ticket = Ticket::query()->create(['subject' => 'Orphan ticket', 'status' => Ticket::STATUS_OPEN, 'priority' => 'normal', 'channel' => 'email']);

            $this->assertSame('Unknown', $ticket->requesterLabel());
        });
    }

    public function test_ticket_index_filters_by_sla_state_status_channel_priority_assignee_and_sort(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $assigneeId = null;
        $tenant->run(function () use (&$assigneeId) {
            $assigneeId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $partnerId = $this->makeCompany()->id;
            Ticket::query()->create([
                'partner_id' => $partnerId, 'subject' => 'Alpha ticket', 'status' => Ticket::STATUS_OPEN,
                'priority' => 'high', 'channel' => 'phone', 'assigned_to' => $assigneeId,
            ]);
            Ticket::query()->create([
                'partner_id' => $partnerId, 'subject' => 'Beta ticket', 'status' => Ticket::STATUS_CLOSED,
                'priority' => 'normal', 'channel' => 'in_app',
            ]);
        });

        $this->get('/crm/tickets?search=Alpha')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1));

        $this->get('/crm/tickets?status=closed')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1)->where('tickets.data.0.subject', 'Beta ticket'));

        $this->get('/crm/tickets?channel=phone')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1)->where('tickets.data.0.subject', 'Alpha ticket'));

        $this->get('/crm/tickets?priority=high')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1)->where('tickets.data.0.subject', 'Alpha ticket'));

        $this->get("/crm/tickets?assigned_to={$assigneeId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1)->where('tickets.data.0.subject', 'Alpha ticket'));

        $this->get('/crm/tickets?sla_state=on_track')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 2));

        $this->get('/crm/tickets?sort=subject&direction=desc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('tickets.data.0.subject', 'Beta ticket'));
    }

    public function test_store_and_update_ticket_validation_rejects_invalid_references_and_missing_requester(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $this->post('/crm/tickets', [])->assertSessionHasErrors(['subject', 'message', 'requester_name']);

        $this->post('/crm/tickets', [
            'subject' => 'Bad refs',
            'message' => 'body',
            'requester_name' => 'Someone',
            'partner_id' => 999999,
            'category_id' => 999999,
        ])->assertSessionHasErrors(['partner_id', 'category_id']);

        $ticketId = null;
        $tenant->run(function () use (&$ticketId) {
            $ticketId = Ticket::query()->create(['requester_name' => 'Someone', 'subject' => 'Editable', 'status' => 'open', 'priority' => 'normal', 'channel' => 'email'])->id;
        });

        $this->put("/crm/tickets/{$ticketId}", [])->assertSessionHasErrors(['subject', 'priority', 'channel', 'requester_name']);

        $this->put("/crm/tickets/{$ticketId}", [
            'subject' => 'Bad refs on update',
            'priority' => 'normal',
            'channel' => 'email',
            'requester_name' => 'Someone',
            'partner_id' => 999999,
            'category_id' => 999999,
        ])->assertSessionHasErrors(['partner_id', 'category_id']);
    }

    public function test_relink_ticket_validation_rejects_an_invalid_partner(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $ticketId = null;
        $tenant->run(function () use (&$ticketId) {
            $ticketId = Ticket::query()->create(['requester_name' => 'X', 'subject' => 'Y', 'status' => Ticket::STATUS_OPEN, 'priority' => 'normal', 'channel' => 'email'])->id;
        });

        $this->post("/crm/tickets/{$ticketId}/relink", ['partner_id' => 999999])->assertSessionHasErrors(['partner_id']);
    }

    public function test_store_ticket_message_validation_rejects_an_invalid_direction(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $ticketId = null;
        $tenant->run(function () use (&$ticketId) {
            $ticketId = Ticket::query()->create(['requester_name' => 'X', 'subject' => 'Y', 'status' => Ticket::STATUS_OPEN, 'priority' => 'normal', 'channel' => 'email'])->id;
        });

        // 'inbound' isn't allowed here — only the requester's own reply-by-email path creates one (TicketService::create).
        $this->post("/crm/tickets/{$ticketId}/messages", ['direction' => 'inbound', 'body' => 'x'])
            ->assertSessionHasErrors(['direction']);
    }
}
