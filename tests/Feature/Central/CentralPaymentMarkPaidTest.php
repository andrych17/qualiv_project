<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralPaymentMarkPaidTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenantDatabaseIfExists('901');
        Tenant::query()->whereKey('901')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('901');
        Tenant::query()->whereKey('901')->delete();

        parent::tearDown();
    }

    public function test_recording_a_payment_marks_the_invoice_paid(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $tenant = Tenant::create(['id' => '901', 'name' => 'Payer Co', 'plan' => 'starter']);

        $invoice = CentralInvoice::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_code' => 'starter',
            'billing_period_start' => now()->startOfMonth(),
            'billing_period_end' => now()->endOfMonth(),
            'status' => 'issued',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => now()->addDays(14),
            'issued_at' => now(),
        ]);

        $this->actingAs($admin, 'central_admin')
            ->post("/central/invoices/{$invoice->id}/payments", [
                'amount' => 500000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect(route('central.invoices.show', $invoice->id));

        $this->assertDatabaseHas('central_invoices', ['id' => $invoice->id, 'status' => 'paid']);
        $this->assertDatabaseHas('central_payments', [
            'invoice_id' => $invoice->id,
            'tenant_id' => '901',
            'amount' => 500000,
            'method' => 'bank_transfer',
        ]);
    }
}
