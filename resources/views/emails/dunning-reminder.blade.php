<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <p>Hi {{ $tenant->displayName() }},</p>

    @if ($offsetDays < 0)
        <p>
            This is a reminder that your Nusaevo ERP subscription invoice
            <strong>#{{ $invoice->invoice_number }}</strong> for
            <strong>{{ number_format($invoice->total_amount, 2) }} {{ $invoice->currency }}</strong>
            is due on <strong>{{ $invoice->due_date->format('Y-m-d') }}</strong>.
        </p>

        <p>
            Please settle this invoice to prevent service interruption.
        </p>

        <p>
            Your Nusaevo ERP subscription invoice (period
            {{ $invoice->billing_period_start->toDateString() }} –
            {{ $invoice->billing_period_end->toDateString() }}) was due on
            {{ $invoice->due_date->toDateString() }} and is now past due.
        </p>
    @else
        <p>
            Your Nusaevo ERP subscription invoice (period
            {{ $invoice->billing_period_start->toDateString() }} –
            {{ $invoice->billing_period_end->toDateString() }}) was due on
            {{ $invoice->due_date->toDateString() }} and is now past due.
        </p>
    @endif

    <p>
        Amount: {{ $invoice->amount_total }} {{ $invoice->currency }}<br>
        You can submit your payment receipt from your Billing &amp; Subscription screen.
    </p>

    <p>Thanks,<br>{{ config('app.name', 'Nusaevo ERP') }}</p>
</body>
</html>
