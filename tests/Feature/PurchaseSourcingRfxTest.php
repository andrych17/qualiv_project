<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurRfxHdr;
use App\Modules\Purchase\Models\PurRfxInvitation;
use App\Modules\Purchase\Services\SourcingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseSourcingRfxTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_view_sourcing_index_and_create_page(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/purchase/sourcing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Sourcing/Index'));

        $this->get('/purchase/sourcing/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Sourcing/Create'));
    }

    public function test_admin_can_create_rfq_record_quotes_and_award_generating_pos(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $vendor1Id = null;
        $vendor2Id = null;

        $tenant->run(function () use (&$vendor1Id, &$vendor2Id) {
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);

            $v1 = Partner::create(['name' => 'Supplier Alpha', 'type' => 'company', 'is_active' => true]);
            $v1->roles()->create(['role_type_id' => $roleType->id]);
            $vendor1Id = $v1->id;

            $v2 = Partner::create(['name' => 'Supplier Beta', 'type' => 'company', 'is_active' => true]);
            $v2->roles()->create(['role_type_id' => $roleType->id]);
            $vendor2Id = $v2->id;
        });

        // 1. Create RFQ
        $response = $this->post('/purchase/sourcing', [
            'type' => 'rfq',
            'due_date' => now()->addDays(14)->toDateString(),
            'suppliers' => [$vendor1Id, $vendor2Id],
            'lines' => [
                ['description' => 'Industrial Generator 50kVA', 'qty' => 1],
                ['description' => 'Backup Fuel Tank 500L', 'qty' => 2],
            ],
        ]);

        $rfxId = null;

        $tenant->run(function () use (&$rfxId) {
            $rfx = PurRfxHdr::with(['lines', 'invitations'])->first();
            $this->assertNotNull($rfx);
            $this->assertSame(PurRfxHdr::STATUS_DRAFT, $rfx->status);
            $this->assertCount(2, $rfx->lines);
            $this->assertCount(2, $rfx->invitations);

            $rfxId = $rfx->id;
        });

        // 2. Dispatch to suppliers
        $this->post("/purchase/sourcing/{$rfxId}/send")
            ->assertRedirect();

        $tenant->run(function () use ($rfxId) {
            $rfx = PurRfxHdr::find($rfxId);
            $this->assertSame(PurRfxHdr::STATUS_RESPONSES_OPEN, $rfx->status);
        });

        // 3. Record response from Supplier Alpha
        $invitation1Id = null;
        $invitation2Id = null;
        $line1Id = null;
        $line2Id = null;

        $tenant->run(function () use ($rfxId, &$invitation1Id, &$invitation2Id, &$line1Id, &$line2Id) {
            $rfx = PurRfxHdr::with(['lines', 'invitations'])->find($rfxId);
            $invitation1Id = $rfx->invitations->firstWhere('supplier_id', 1)->id ?? $rfx->invitations[0]->id;
            $invitation2Id = $rfx->invitations->firstWhere('supplier_id', 2)->id ?? $rfx->invitations[1]->id;

            $line1Id = $rfx->lines[0]->id;
            $line2Id = $rfx->lines[1]->id;
        });

        $this->post("/purchase/sourcing/{$rfxId}/response", [
            'invitation_id' => $invitation1Id,
            'notes' => 'Quotes valid for 30 days',
            'quotes' => [
                ['rfx_line_id' => $line1Id, 'price' => 120000000, 'lead_time_days' => 5],
                ['rfx_line_id' => $line2Id, 'price' => 18000000, 'lead_time_days' => 5],
            ],
        ])->assertRedirect();

        // 4. Record response from Supplier Beta (cheaper on fuel tanks)
        $this->post("/purchase/sourcing/{$rfxId}/response", [
            'invitation_id' => $invitation2Id,
            'notes' => 'Direct from manufacturer',
            'quotes' => [
                ['rfx_line_id' => $line1Id, 'price' => 135000000, 'lead_time_days' => 7],
                ['rfx_line_id' => $line2Id, 'price' => 14000000, 'lead_time_days' => 3], // Cheaper!
            ],
        ])->assertRedirect();

        // 5. View Comparison Matrix
        $this->get("/purchase/sourcing/{$rfxId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Sourcing/Show')
                ->has('rfx')
                ->has('suppliers')
                ->has('comparisonLines'));

        // 6. Award Split Lines: Line 1 to Supplier 1, Line 2 to Supplier 2
        $this->post("/purchase/sourcing/{$rfxId}/award", [
            'awards' => [
                $line1Id => $vendor1Id,
                $line2Id => $vendor2Id,
            ],
        ])->assertRedirect();

        // Verify RFQ is awarded and 2 POs were generated
        $tenant->run(function () use ($rfxId, $vendor1Id, $vendor2Id) {
            $rfx = PurRfxHdr::find($rfxId);
            $this->assertSame(PurRfxHdr::STATUS_AWARDED, $rfx->status);

            $po1 = PurOrderHdr::where('supplier_id', $vendor1Id)->first();
            $this->assertNotNull($po1);
            $this->assertEquals(120000000, (float) $po1->total_amount);

            $po2 = PurOrderHdr::where('supplier_id', $vendor2Id)->first();
            $this->assertNotNull($po2);
            $this->assertEquals(28000000, (float) $po2->total_amount); // 2 * 14M = 28M
        });
    }
}
