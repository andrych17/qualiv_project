<?php

namespace Tests\Feature\Central;

use App\Mail\DunningReminderMail;
use App\Models\Tenant;
use App\Modules\Central\Models\CentralDunningPolicy;
use App\Modules\Central\Models\CentralInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class SendDunningRemindersCommandTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenantDatabaseIfExists('803');
        Tenant::query()->whereKey('803')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('803');
        Tenant::query()->whereKey('803')->delete();

        parent::tearDown();
    }

    public function test_it_sends_one_reminder_per_matching_offset_and_never_duplicates(): void
    {
        Mail::fake();

        $tenant = Tenant::create(['id' => '803', 'name' => 'Reminder Co', 'plan' => 'starter', 'contact_email' => 'billing@reminderco.test']);

        CentralDunningPolicy::query()->updateOrCreate(
            ['scope_type' => 'platform_default', 'scope_id' => null],
            ['reminder_offsets_days' => [-7, -3, -1, 3, 7], 'cutoff_days_after_due' => 14],
        );

        // due_date + (-3) = today  =>  due_date = today + 3
        $invoice = CentralInvoice::query()->create([
            'tenant_id' => '803',
            'plan_code' => 'starter',
            'billing_period_start' => now()->startOfMonth(),
            'billing_period_end' => now()->endOfMonth(),
            'status' => 'issued',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => today()->addDays(3),
            'issued_at' => now(),
        ]);

        Artisan::call('central:send-dunning-reminders');

        $this->assertDatabaseHas('central_dunning_log', [
            'tenant_id' => '803',
            'invoice_id' => $invoice->id,
            'offset_days' => -3,
        ]);
        Mail::assertSent(DunningReminderMail::class, 1);

        // Re-running the same day must not send a second reminder for the same offset.
        Artisan::call('central:send-dunning-reminders');

        $this->assertSame(1, DB::table('central_dunning_log')->where('invoice_id', $invoice->id)->count());
        Mail::assertSent(DunningReminderMail::class, 1);
    }
}
