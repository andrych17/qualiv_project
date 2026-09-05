<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpCrm;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D — Leads: pre-partner pipeline, direct-stage drag, and the Convert/Disqualify closing actions. */
class CrmLeadTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpCrm;
    use SetsUpTenant;

    public function test_admin_can_crud_a_lead_log_activities_and_move_direct_stages(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $sourceId = null;
        $tenant->run(function () use (&$sourceId) {
            $sourceId = $this->makeLeadSource()->id;
        });

        $this->get('/crm/leads')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/Leads/Index'));
        $this->get('/crm/leads/create')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/Leads/Create'));

        $this->post('/crm/leads', [
            'name' => 'Prospect A',
            'company_name' => 'Prospect Co',
            'source_id' => $sourceId,
            'estimated_value' => 5000,
            'next_action_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect(route('crm.leads.index'));

        $leadId = null;
        $tenant->run(function () use (&$leadId) {
            $lead = Lead::query()->where('name', 'Prospect A')->first();
            $this->assertSame(Lead::STAGE_NEW, $lead->stage);
            $leadId = $lead->id;
        });

        // A non-empty index page: Eloquent only resolves eager-loaded relations (source(), owner())
        // when the base result set is non-empty, so the first (pre-create) index hit above never did.
        $this->get('/crm/leads')->assertOk()->assertInertia(fn ($page) => $page->has('leads', 1));

        $this->get("/crm/leads/{$leadId}/edit")->assertOk()->assertInertia(fn ($page) => $page
            ->component('CRM/Leads/Edit')
            ->where('lead.name', 'Prospect A'));

        $this->put("/crm/leads/{$leadId}", [
            'name' => 'Prospect A (Updated)',
            'estimated_value' => 6000,
        ])->assertRedirect(route('crm.leads.index'));

        $tenant->run(function () use ($leadId) {
            $this->assertSame('Prospect A (Updated)', Lead::query()->find($leadId)->name);
        });

        $this->post("/crm/leads/{$leadId}/activities", [
            'activity_type' => 'call',
            'body' => 'Left a voicemail.',
        ])->assertRedirect();

        $tenant->run(function () use ($leadId) {
            $this->assertSame(1, LeadActivity::query()->where('lead_id', $leadId)->count());
        });

        $this->patch("/crm/leads/{$leadId}/stage", ['stage' => Lead::STAGE_CONTACTED])->assertRedirect();
        $tenant->run(function () use ($leadId) {
            $this->assertSame(Lead::STAGE_CONTACTED, Lead::query()->find($leadId)->stage);
        });
    }

    public function test_setting_a_non_direct_or_already_closed_stage_is_rejected(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $leadId = null;
        $convertedId = null;
        $tenant->run(function () use (&$leadId, &$convertedId) {
            $leadId = Lead::query()->create(['name' => 'Stage Test', 'stage' => Lead::STAGE_NEW])->id;
            $convertedId = Lead::query()->create(['name' => 'Already Converted', 'stage' => Lead::STAGE_CONVERTED])->id;
        });

        // The request layer itself rejects a non-direct stage value.
        $this->patch("/crm/leads/{$leadId}/stage", ['stage' => Lead::STAGE_CONVERTED])->assertSessionHasErrors(['stage']);

        // The service's own two guards are each unreachable via HTTP for a different reason:
        // UpdateLeadStageRequest's Rule::in already blocks a non-direct stage value (guard 1),
        // and blocks moving a lead that's already shown as closed in the same request cycle
        // (guard 2 needs a lead that's closed but still gets a direct-stage value submitted).
        $tenant->run(function () use ($leadId, $convertedId) {
            try {
                app(LeadService::class)->setStage(Lead::query()->find($leadId), Lead::STAGE_DISQUALIFIED);
                $this->fail('Expected a ValidationException for a non-direct stage.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('stage', $e->errors());
            }

            $this->expectException(ValidationException::class);
            app(LeadService::class)->setStage(Lead::query()->find($convertedId), Lead::STAGE_CONTACTED);
        });
    }

    public function test_converting_a_lead_creates_the_chosen_partner_type_and_closes_the_lead(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $roleTypeId = null;
        $leadId = null;
        $tenant->run(function () use (&$roleTypeId, &$leadId) {
            $roleTypeId = $this->makeRoleType()->id;
            $leadId = Lead::query()->create(['name' => 'Jane Prospect', 'company_name' => 'Prospect Co', 'stage' => Lead::STAGE_QUALIFIED])->id;
        });

        $this->post("/crm/leads/{$leadId}/convert", [
            'partner_type' => Partner::TYPE_ORGANIZATION,
            'role_type_id' => 999999,
        ])->assertSessionHasErrors(['role_type_id']);

        $this->post("/crm/leads/{$leadId}/convert", [
            'partner_type' => Partner::TYPE_ORGANIZATION,
            'role_type_id' => $roleTypeId,
        ])->assertRedirect();

        $tenant->run(function () use ($leadId) {
            $lead = Lead::query()->find($leadId);
            $this->assertSame(Lead::STAGE_CONVERTED, $lead->stage);
            $this->assertNotNull($lead->converted_partner_id);
            $partner = Partner::query()->find($lead->converted_partner_id);
            $this->assertSame('Prospect Co', $partner->name);
            $this->assertSame(Partner::TYPE_ORGANIZATION, $partner->type);
            $this->assertSame('lead_conversion', $partner->source);
        });

        // Converting an already-closed lead is rejected at the service layer.
        $tenant->run(function () use ($leadId, $roleTypeId) {
            $this->expectException(ValidationException::class);
            app(LeadService::class)->convert(Lead::query()->find($leadId), Partner::TYPE_INDIVIDUAL, $roleTypeId);
        });
    }

    public function test_converting_to_an_individual_uses_the_leads_own_name(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $roleTypeId = null;
        $leadId = null;
        $tenant->run(function () use (&$roleTypeId, &$leadId) {
            $roleTypeId = $this->makeRoleType()->id;
            $leadId = Lead::query()->create(['name' => 'Solo Prospect', 'stage' => Lead::STAGE_NEW])->id;
        });

        $this->post("/crm/leads/{$leadId}/convert", [
            'partner_type' => Partner::TYPE_INDIVIDUAL,
            'role_type_id' => $roleTypeId,
        ])->assertRedirect();

        $tenant->run(function () use ($leadId) {
            $partner = Partner::query()->find(Lead::query()->find($leadId)->converted_partner_id);
            $this->assertSame('Solo Prospect', $partner->name);
            $this->assertSame(Partner::TYPE_INDIVIDUAL, $partner->type);
        });
    }

    public function test_disqualifying_a_lead_records_a_reason_and_blocks_a_second_close(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $leadId = null;
        $tenant->run(function () use (&$leadId) {
            $leadId = Lead::query()->create(['name' => 'Dead End', 'stage' => Lead::STAGE_CONTACTED])->id;
        });

        $this->post("/crm/leads/{$leadId}/disqualify", ['reason' => 'No budget'])->assertRedirect();

        $tenant->run(function () use ($leadId) {
            $lead = Lead::query()->find($leadId);
            $this->assertSame(Lead::STAGE_DISQUALIFIED, $lead->stage);
            $this->assertSame('No budget', $lead->disqualify_reason);
            $this->assertSame(1, LeadActivity::query()->where('lead_id', $leadId)->count());
        });

        $tenant->run(function () use ($leadId) {
            $this->expectException(ValidationException::class);
            app(LeadService::class)->disqualify(Lead::query()->find($leadId), 'Second attempt');
        });
    }

    public function test_store_and_update_lead_validation_rejects_missing_name_and_bad_source(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $this->post('/crm/leads', [])->assertSessionHasErrors(['name']);

        $this->post('/crm/leads', [
            'name' => 'Bad Source Lead',
            'source_id' => 999999,
        ])->assertSessionHasErrors(['source_id']);

        $this->post('/crm/leads', [
            'name' => 'Bad Value Lead',
            'estimated_value' => -5,
        ])->assertSessionHasErrors(['estimated_value']);

        $leadId = null;
        $tenant->run(function () use (&$leadId) {
            $leadId = Lead::query()->create(['name' => 'Editable Lead', 'stage' => Lead::STAGE_NEW])->id;
        });

        $this->put("/crm/leads/{$leadId}", [
            'name' => 'Editable Lead',
            'source_id' => 999999,
        ])->assertSessionHasErrors(['source_id']);
    }

    /** Lead::scopeFilter exists (search/stage) but LeadController::index() doesn't call it — the Board view filters client-side instead. Exercised directly since no route reaches it. */
    public function test_lead_filter_scope_matches_by_search_and_stage(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            Lead::query()->create(['name' => 'Findable Prospect', 'stage' => Lead::STAGE_NEW]);
            Lead::query()->create(['name' => 'Other', 'company_name' => 'Findable Co', 'stage' => Lead::STAGE_QUALIFIED]);
            Lead::query()->create(['name' => 'Unrelated', 'stage' => Lead::STAGE_NEW]);

            $this->assertSame(2, Lead::query()->filter(['search' => 'Findable'])->count());
            $this->assertSame(2, Lead::query()->filter(['stage' => Lead::STAGE_NEW])->count());
        });
    }

    public function test_store_lead_activity_validation_rejects_a_bad_activity_type(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $leadId = null;
        $tenant->run(function () use (&$leadId) {
            $leadId = Lead::query()->create(['name' => 'Activity Target', 'stage' => Lead::STAGE_NEW])->id;
        });

        $this->post("/crm/leads/{$leadId}/activities", ['activity_type' => 'carrier_pigeon'])
            ->assertSessionHasErrors(['activity_type']);
    }
}
