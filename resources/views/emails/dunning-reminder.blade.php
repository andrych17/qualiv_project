<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <p>Hi {{ $tenant->displayName() }},</p>

    @if ($offsetDays < 0)
        <p>
            This is a reminder that your Nusaevo subscription invoice
            (period {{ $invoice->billing_period_start->toDateString() }} –
            {{ $invoice->billing_period_end->toDateString() }}) is due on
            {{ $invoice->due_date->toDateString() }}.
        </p>
    @else
        <p>
            Your Nusaevo subscription invoice (period
            {{ $invoice->billing_period_start->toDateString() }} –
            {{ $invoice->billing_period_end->toDateString() }}) was due on
            {{ $invoice->due_date->toDateString() }} and is now past due.
        </p>
    @endif

    <p>
        Amount: {{ $invoice->amount_total }} {{ $invoice->currency }}<br>
        You can submit your payment receipt from your Billing &amp; Subscription screen.
    </p>

    <p>Thanks,<br>Nusaevo</p>
</body>
</html>
