<?php

namespace App\Modules\WNE\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** §3I: literal subject/body only — §3L (dynamic templates) doesn't exist yet. */
class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $notificationSubject, public string $notificationBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notificationSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->notificationBody);
    }
}
