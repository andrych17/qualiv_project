<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Ticket;
use App\Modules\CRM\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class TicketService
{
    /**
     * @param  array<string, mixed>  $data
     *                                      $data must include 'message' (the requester's initial description) — it becomes
     *                                      the ticket's first inbound message, not a separate free-text field on the header.
     */
    public function create(array $data): Ticket
    {
        $message = $data['message'];
        unset($data['message']);
        $data['priority'] ??= 'normal';
        $data['channel'] ??= 'email';

        return DB::transaction(function () use ($data, $message) {
            $ticket = Ticket::query()->create([...$data, 'status' => Ticket::STATUS_OPEN]);

            $this->addMessage(
                $ticket,
                TicketMessage::DIRECTION_INBOUND,
                $message,
                senderName: $ticket->requesterLabel(),
            );

            return $ticket;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Ticket $ticket, array $data): Ticket
    {
        $ticket->update($data);

        return $ticket->refresh();
    }

    public function updateStatus(Ticket $ticket, string $newStatus): Ticket
    {
        $oldStatus = $ticket->status;
        $ticket->update(['status' => $newStatus]);

        $this->addMessage(
            $ticket,
            TicketMessage::DIRECTION_INTERNAL_NOTE,
            "Status changed: {$oldStatus} → {$newStatus}",
            senderId: auth()->id(),
        );

        return $ticket;
    }

    /** §3F: re-link a ticket's requester without losing the message thread — messages are keyed by ticket_id, untouched here. */
    public function relinkPartner(Ticket $ticket, int $partnerId): Ticket
    {
        $ticket->update(['partner_id' => $partnerId]);

        return $ticket->refresh();
    }

    public function addMessage(
        Ticket $ticket,
        string $direction,
        string $body,
        ?int $senderId = null,
        ?string $senderName = null,
    ): TicketMessage {
        return TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'direction' => $direction,
            'body' => $body,
            'sender_id' => $senderId,
            'sender_name' => $senderName,
            'sent_at' => now(),
        ]);
    }
}
