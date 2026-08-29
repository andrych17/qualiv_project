<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\FieldVisitType;
use App\Modules\Legal\Models\Matter;
use App\Modules\Legal\Models\PartyRoleType;
use App\Modules\Legal\Models\ProtocolBook;
use App\Modules\Legal\Services\DeedPartyService;
use App\Modules\Legal\Services\DeedService;
use App\Modules\Legal\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * LEGAL_SPECS.md §3A — unified "my work" dashboard across Matters/Deeds/Field Visits/
 * Protocol Books, mirroring CrmDashboardControllerTest-equivalent coverage CRM's own §3A got.
 */
class LegalDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_dashboard_summarizes_and_lists_assigned_work(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');

            $matter = Matter::query()->create([
                'code' => 'MATTER-DASH-1', 'title' => 'Dashboard Matter', 'status' => Matter::STATUS_OPEN,
                'assigned_to' => $userId,
            ]);

            $deedType = DeedType::query()->create([
                'code' => 'ajb_dash', 'name' => 'AJB Dashboard', 'category' => DeedType::CATEGORY_PPAT,
                'requires_tax' => true, 'requires_bpn_registration' => true, 'is_active' => true,
            ]);
            $seller = Partner::query()->create(['type' => Partner::TYPE_INDIVIDUAL, 'name' => 'Seller', 'source' => 'manual']);
            $buyer = Partner::query()->create(['type' => Partner::TYPE_INDIVIDUAL, 'name' => 'Buyer', 'source' => 'manual']);
            $pihakPertama = PartyRoleType::query()->create(['code' => 'pihak_pertama', 'name' => 'Pihak Pertama', 'is_active' => true]);
            $pihakKedua = PartyRoleType::query()->create(['code' => 'pihak_kedua', 'name' => 'Pihak Kedua', 'is_active' => true]);

            $deed = app(DeedService::class)->create([
                'deed_type_id' => $deedType->id,
                'matter_id' => $matter->id,
                'transaction_value' => 500000000,
            ]);
            $partyService = app(DeedPartyService::class);
            $partyService->add($deed, ['partner_id' => $seller->id, 'role_type_id' => $pihakPertama->id, 'identity_name' => 'Seller']);
            $partyService->add($deed, ['partner_id' => $buyer->id, 'role_type_id' => $pihakKedua->id, 'identity_name' => 'Buyer']);
            app(TaxService::class)->generateForDeed($deed);
            $deed->update(['signing_date' => now()->toDateString()]);
            $deed = app(DeedService::class)->transition($deed, Deed::STATUS_READY_FOR_SIGNING);

            BpnSubmission::query()->create([
                'deed_id' => $deed->id, 'submission_type' => BpnSubmission::TYPE_BALIK_NAMA,
                'status' => BpnSubmission::STATUS_SUBMITTED, 'submitted_at' => now()->toDateString(),
            ]);

            $visitType = FieldVisitType::query()->create([
                'code' => 'site_survey_dash', 'name' => 'Site Survey', 'is_active' => true,
            ]);
            $fieldVisit = FieldVisit::query()->create([
                'matter_id' => $matter->id, 'visit_type_id' => $visitType->id,
                'assigned_to' => $userId, 'status' => FieldVisit::STATUS_SCHEDULED,
            ]);

            $book = ProtocolBook::query()->create([
                'book_type' => ProtocolBook::TYPE_REPERTORIUM, 'year' => (int) now()->year,
                'volume' => 1, 'status' => ProtocolBook::STATUS_ACTIVE, 'opened_at' => now()->toDateString(),
                'notary_user_id' => $userId,
            ]);

            $ids = ['matter' => $matter->id, 'deed' => $deed->id, 'fieldVisit' => $fieldVisit->id, 'book' => $book->id];
        });

        $this->get('/legal/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Legal/Dashboard/Index')
                ->where('summary.open_matters', 1)
                ->where('summary.deeds_pending_signature', 1)
                ->where('summary.tax_pending_clearance', 2)
                ->where('summary.bpn_in_process', 1)
                ->where('myMatters.0.code', 'MATTER-DASH-1')
                ->where('myDeeds.0.id', $ids['deed'])
                ->where('myDeeds.0.danger', true)
                ->where('myFieldVisits.0.id', $ids['fieldVisit'])
                ->has('protocolBooks', 1));

        $this->getJson("/legal/dashboard/matter/{$ids['matter']}")
            ->assertOk()
            ->assertJsonPath('type', 'matter')
            ->assertJsonPath('record.code', 'MATTER-DASH-1')
            ->assertJsonPath('record.deed_count', 1);

        $this->getJson("/legal/dashboard/deed/{$ids['deed']}")
            ->assertOk()
            ->assertJsonPath('type', 'deed')
            ->assertJsonCount(2, 'taxes');

        $this->getJson("/legal/dashboard/field-visit/{$ids['fieldVisit']}")
            ->assertOk()
            ->assertJsonPath('type', 'fieldVisit')
            ->assertJsonPath('record.status', FieldVisit::STATUS_SCHEDULED);

        $this->getJson("/legal/dashboard/protocol-book/{$ids['book']}")
            ->assertOk()
            ->assertJsonPath('type', 'protocolBook')
            ->assertJsonPath('record.book_type', ProtocolBook::TYPE_REPERTORIUM);
    }

    public function test_dashboard_redirects_bare_legal_path_and_blocks_starter_plan(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/legal')->assertRedirect('/legal/dashboard');
        $this->get('/legal/dashboard')->assertForbidden();
    }
}
