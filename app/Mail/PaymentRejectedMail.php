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
 * Rejection notice (CENTRAL_SPECS.md §3F) — the tenant is told why their payment was
 * rejected and that they can resubmit, same minimal single-channel posture as
 * DunningReminderMail (§5).
 */
class PaymentRejectedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public CentralInvoice $invoice,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment rejected for invoice #'.$this->invoice->id.' — please resubmit',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-rejected');
    }
}
