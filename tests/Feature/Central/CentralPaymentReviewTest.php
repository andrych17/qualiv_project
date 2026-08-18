<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralPaymentReviewTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenantDatabaseIfExists('901');
        Tenant::query()->whereKey('901')->delete();

        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('901');
        Tenant::query()->whereKey('901')->delete();

        parent::tearDown();
    }

    private function makeInvoice(): CentralInvoice
    {
        $tenant = Tenant::create(['id' => '901', 'name' => 'Payer Co', 'plan' => 'starter']);

        return CentralInvoice::query()->create([
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
    }

    public function test_submitting_a_payment_moves_invoice_to_payment_submitted_and_stores_receipt(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $invoice = $this->makeInvoice();

        $this->actingAs($admin, 'central_admin')
            ->post("/central/invoices/{$invoice->id}/payments", [
                'amount' => 500000,
                'paid_at' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('central.invoices.show', $invoice->id));

        $this->assertDatabaseHas('central_invoices', ['id' => $invoice->id, 'status' => 'payment_submitted']);
        $payment = $invoice->payments()->first();
        $this->assertSame('pending_review', $payment->status);
        $this->assertNotNull($payment->receipt_object_key);
        Storage::disk('s3')->assertExists($payment->receipt_object_key);
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'payment_submitted', 'entity_id' => (string) $payment->id]);
    }

    public function test_confirming_a_payment_marks_invoice_paid_and_reactivates_tenant(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $invoice = $this->makeInvoice();
        $invoice->tenant()->update(['access_status' => 'read_only']);

        $this->actingAs($admin, 'central_admin')
            ->post("/central/invoices/{$invoice->id}/payments", ['amount' => 500000]);

        $payment = $invoice->payments()->first();

        $this->actingAs($admin, 'central_admin')
            ->post("/central/payments/{$payment->id}/confirm")
            ->assertRedirect(route('central.invoices.show', $invoice->id));

        $this->assertDatabaseHas('central_invoices', ['id' => $invoice->id, 'status' => 'paid']);
        $this->assertDatabaseHas('central_payments', ['id' => $payment->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('tenants', ['id' => '901', 'access_status' => 'active']);
    }

    public function test_rejecting_a_payment_retains_receipt_and_reverts_invoice_to_issued(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $invoice = $this->makeInvoice();

        $this->actingAs($admin, 'central_admin')
            ->post("/central/invoices/{$invoice->id}/payments", [
                'amount' => 500000,
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ]);

        $payment = $invoice->payments()->first();
        $receiptKey = $payment->receipt_object_key;

        $this->actingAs($admin, 'central_admin')
            ->post("/central/payments/{$payment->id}/reject", ['reason' => 'Amount mismatch'])
            ->assertRedirect(route('central.invoices.show', $invoice->id));

        $this->assertDatabaseHas('central_invoices', ['id' => $invoice->id, 'status' => 'issued']);
        $this->assertDatabaseHas('central_payments', ['id' => $payment->id, 'status' => 'rejected', 'rejection_reason' => 'Amount mismatch']);
        Storage::disk('s3')->assertExists($receiptKey);
    }
}
