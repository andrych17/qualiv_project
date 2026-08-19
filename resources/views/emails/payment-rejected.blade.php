<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <p>Hi {{ $tenant->displayName() }},</p>

    <p>
        Your submitted payment for invoice #{{ $invoice->id }}
        (period {{ $invoice->billing_period_start->toDateString() }} –
        {{ $invoice->billing_period_end->toDateString() }}) was reviewed and
        <strong>rejected</strong>.
    </p>

    <p>Reason: {{ $reason }}</p>

    <p>
        You can resubmit a new payment receipt from your
        <a href="{{ config('app.url') }}/billing">Billing &amp; Subscription</a> screen.
        If you believe this was a mistake, please reply to this email.
    </p>

    <p>Thanks,<br>Nusaevo</p>
</body>
</html>
