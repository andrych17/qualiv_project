<?php

namespace Tests\Feature;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalMatterCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_crud_legal_matter_when_plan_allows(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/legal/matters')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal/Matters/Index'));

        $this->post('/legal/matters', [
            'code' => 'MATTER-001',
            'title' => 'Demo Matter',
            'status' => 'open',
            'notes' => 'hello',
        ])->assertRedirect(route('legal.matters.index'));

        $matterId = null;
        $tenant->run(function () use (&$matterId) {
            $matter = Matter::query()->where('code', 'MATTER-001')->first();
            $this->assertNotNull($matter);
            $this->assertNotEmpty($matter->uuid);
            $this->assertNotNull($matter->opened_at);
            $matterId = $matter->id;
        });

        $this->put('/legal/matters/'.$matterId, [
            'code' => 'MATTER-001',
            'title' => 'Demo Matter Updated',
            'status' => 'on_hold',
            'notes' => 'updated',
        ])->assertRedirect(route('legal.matters.index'));

        $this->delete('/legal/matters/'.$matterId)
            ->assertRedirect(route('legal.matters.index'));
    }

    public function test_admin_can_open_matter_converted_from_lead(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $convertedLeadId = null;
        $partnerId = null;
        $qualifiedLeadId = null;
        $tenant->run(function () use (&$convertedLeadId, &$partnerId, &$qualifiedLeadId) {
            $partner = Partner::query()->create(['type' => Partner::TYPE_ORGANIZATION, 'name' => 'Alice Corp']);
            $partnerId = $partner->id;

            $convertedLeadId = Lead::query()->create([
                'name' => 'Alice',
                'company_name' => 'Alice Corp',
                'stage' => Lead::STAGE_CONVERTED,
                'converted_partner_id' => $partner->id,
            ])->id;

            $qualifiedLeadId = Lead::query()->create([
                'name' => 'Bob',
                'stage' => Lead::STAGE_QUALIFIED,
            ])->id;
        });

        // Convert-from-Lead picker (§3B) only lists already-converted leads, with their partner.
        $this->get('/legal/matters/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Legal/Matters/Create')
                ->where('convertedLeads.0.id', $convertedLeadId)
                ->where('convertedLeads.0.partner_id', $partnerId)
                ->has('convertedLeads', 1));

        // A not-yet-converted lead can't be used as the origin.
        $this->post('/legal/matters', [
            'title' => 'Should fail',
            'status' => 'open',
            'converted_from_lead_id' => $qualifiedLeadId,
        ])->assertSessionHasErrors('converted_from_lead_id');

        $this->post('/legal/matters', [
            'code' => 'MATTER-LEAD-001',
            'title' => 'Alice Corp Incorporation',
            'status' => 'open',
            'partner_id' => $partnerId,
            'converted_from_lead_id' => $convertedLeadId,
        ])->assertRedirect(route('legal.matters.index'));

        $tenant->run(function () use ($convertedLeadId, $partnerId) {
            $matter = Matter::query()->where('title', 'Alice Corp Incorporation')->first();
            $this->assertNotNull($matter);
            $this->assertSame($convertedLeadId, $matter->converted_from_lead_id);
            $this->assertSame($partnerId, $matter->partner_id);
        });
    }

    public function test_starter_plan_blocks_legal_module(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/legal/matters')->assertForbidden();
    }
}
