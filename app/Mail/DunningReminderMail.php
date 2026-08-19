<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Dunning's own minimal, single-channel (email) send (CENTRAL_SPECS.md §5) — deliberately no
 * multi-channel driver interface or template engine, WNE's Notification Module machinery
 * doesn't apply at this layer (Central operates outside any tenant's WNE instance).
 */
class DunningReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public CentralInvoice $invoice,
        public int $offsetDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->offsetDays < 0
                ? 'Upcoming invoice due '.$this->invoice->due_date->toDateString()
                : 'Invoice past due — '.$this->invoice->due_date->toDateString(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dunning-reminder');
    }
}
