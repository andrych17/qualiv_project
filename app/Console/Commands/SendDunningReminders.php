<?php

namespace App\Console\Commands;

use App\Mail\DunningReminderMail;
use App\Modules\Central\Models\CentralDunningLog;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Services\CentralDunningService;
use App\Modules\Central\Support\DunningScheduleCalculator;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Daily dunning reminder send (CENTRAL_SPECS.md §3G) — never sends the same offset twice. */
class SendDunningReminders extends Command
{
    protected $signature = 'central:send-dunning-reminders';

    protected $description = 'Send reminder emails for invoices approaching or past their due date, per each tenant\'s resolved dunning policy';

    public function __construct(
        protected CentralDunningService $dunning,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = today();

        CentralInvoice::query()
            ->whereIn('status', ['issued', 'overdue'])
            ->with('tenant')
            ->each(function (CentralInvoice $invoice) use ($today): void {
                $tenant = $invoice->tenant;

                if (! $tenant || ! $tenant->contact_email) {
                    return;
                }

                $policy = $this->dunning->resolvePolicyFor($tenant);

                foreach ($policy->reminder_offsets_days as $offset) {
                    if (! DunningScheduleCalculator::offsetDueToday($invoice->due_date, (int) $offset, $today)) {
                        continue;
                    }

                    $this->sendOnce($invoice, $tenant->getKey(), $tenant->contact_email, (int) $offset);
                }
            });

        return self::SUCCESS;
    }

    private function sendOnce(CentralInvoice $invoice, string $tenantId, string $email, int $offset): void
    {
        try {
            DB::transaction(function () use ($invoice, $tenantId, $email, $offset): void {
                // The unique (tenant_id, invoice_id, offset_days) constraint is the real
                // guarantee against a duplicate send — this pre-check just avoids a noisy
                // exception on the expected "already sent" path.
                $alreadySent = CentralDunningLog::query()
                    ->where('tenant_id', $tenantId)
                    ->where('invoice_id', $invoice->id)
                    ->where('offset_days', $offset)
                    ->exists();

                if ($alreadySent) {
                    return;
                }

                CentralDunningLog::query()->create([
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'offset_days' => $offset,
                    'channel' => 'email',
                    'sent_at' => now(),
                ]);

                Mail::to($email)->send(new DunningReminderMail($invoice->tenant, $invoice, $offset));
            });

            $this->info("Reminder sent: tenant={$tenantId} invoice={$invoice->id} offset={$offset}");
        } catch (UniqueConstraintViolationException) {
            // A concurrent/overlapping run already logged this exact offset — no-op, not an error.
        }
    }
}
