<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\Address;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerMergeLog;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\Ticket;
use App\Modules\CRM\Services\PartnerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpCrm;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3G — Partner Merge/Deduplication: a review queue over two deterministic duplicate signals, plus the merge itself. */
class CrmPartnerMergeTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpCrm;
    use SetsUpTenant;

    public function test_merge_index_surfaces_duplicate_groups_by_name_and_by_shared_contact_point(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $tenant->run(function () {
            $a = $this->makeCompany('Acme Corp');
            $b = $this->makeCompany('Acme Corp'); // exact normalized-name duplicate
            $this->makeCompany('Totally Different Co');

            $c = $this->makeContact('Bob One');
            $d = $this->makeContact('Bob Two');
            ContactPoint::query()->create(['partner_id' => $c->id, 'type' => 'email', 'value' => 'shared@example.com']);
            ContactPoint::query()->create(['partner_id' => $d->id, 'type' => 'email', 'value' => ' Shared@Example.com ']);
        });

        $response = $this->get('/crm/merge')->assertOk();
        $response->assertInertia(fn ($page) => $page->component('CRM/Merge/Index'));

        $groups = collect($response->viewData('page')['props']['groups']);
        $this->assertTrue($groups->contains(fn ($g) => $g['reason'] === 'Same name' && count($g['partners']) === 2));
        $this->assertTrue($groups->contains(fn ($g) => $g['reason'] === 'Same email' && count($g['partners']) === 2));
    }

    public function test_merging_two_partners_moves_everything_and_tombstones_the_loser(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $roleA = $this->makeRoleType('CUSTOMER', 'Customer');
            $roleB = $this->makeRoleType('VENDOR', 'Vendor');

            $survivor = $this->makeCompany('Survivor Co');
            $survivor->update(['trade_name' => 'Kept Name']);
            $loser = $this->makeCompany('Loser Co');
            $loser->update(['trade_name' => 'Discarded Name']);

            PartnerRole::query()->create(['partner_id' => $survivor->id, 'role_type_id' => $roleA->id, 'assigned_at' => now(), 'is_active' => true]);
            PartnerRole::query()->create(['partner_id' => $loser->id, 'role_type_id' => $roleA->id, 'assigned_at' => now(), 'is_active' => true]); // colliding role
            PartnerRole::query()->create(['partner_id' => $loser->id, 'role_type_id' => $roleB->id, 'assigned_at' => now(), 'is_active' => true]); // unique role

            Address::query()->create(['partner_id' => $loser->id, 'type' => 'office', 'line1' => '2 Loser St', 'is_primary' => true]);
            ContactPoint::query()->create(['partner_id' => $loser->id, 'type' => 'email', 'value' => 'loser@co.test', 'is_primary' => true]);

            $child = $this->makeContact('Loser Employee', $loser->id);
            $lead = Lead::query()->create(['name' => 'Old Lead', 'stage' => Lead::STAGE_CONVERTED, 'converted_partner_id' => $loser->id]);
            $case = ServiceCase::query()->create(['partner_id' => $loser->id, 'subject' => 'Old case', 'status' => 'open', 'priority' => 'normal']);
            $ticket = Ticket::query()->create(['partner_id' => $loser->id, 'subject' => 'Old ticket', 'status' => 'open', 'priority' => 'normal', 'channel' => 'email']);

            $ids = compact('survivor', 'loser', 'roleA', 'roleB', 'child', 'lead', 'case', 'ticket');
            $ids = array_map(fn ($m) => $m->id, $ids);
        });

        $this->post('/crm/merge', [
            'survivor_partner_id' => $ids['survivor'],
            'loser_partner_id' => $ids['loser'],
        ])->assertRedirect(route('crm.merge.index'));

        $tenant->run(function () use ($ids) {
            $loser = Partner::query()->find($ids['loser']);
            $this->assertFalse($loser->is_active);
            $this->assertSame($ids['survivor'], $loser->merged_into_partner_id);

            // Colliding role deactivated on the loser (survivor already had it active); unique role moved.
            $this->assertFalse((bool) PartnerRole::query()->where('partner_id', $ids['loser'])->where('role_type_id', $ids['roleA'])->value('is_active'));
            $this->assertSame($ids['survivor'], PartnerRole::query()->where('role_type_id', $ids['roleB'])->value('partner_id'));

            // Address/contact point moved with is_primary forced false.
            $address = Address::query()->where('line1', '2 Loser St')->first();
            $this->assertSame($ids['survivor'], $address->partner_id);
            $this->assertFalse($address->is_primary);
            $contactPoint = ContactPoint::query()->where('value', 'loser@co.test')->first();
            $this->assertSame($ids['survivor'], $contactPoint->partner_id);
            $this->assertFalse($contactPoint->is_primary);

            $this->assertSame($ids['survivor'], Partner::query()->find($ids['child'])->parent_partner_id);
            $this->assertSame($ids['survivor'], Lead::query()->find($ids['lead'])->converted_partner_id);
            $this->assertSame($ids['survivor'], ServiceCase::query()->find($ids['case'])->partner_id);
            $this->assertSame($ids['survivor'], Ticket::query()->find($ids['ticket'])->partner_id);

            $log = PartnerMergeLog::query()->where('merged_from_partner_id', $ids['loser'])->first();
            $this->assertNotNull($log);
            $this->assertSame($ids['survivor'], $log->merged_into_partner_id);
            $this->assertSame(['kept' => 'Kept Name', 'discarded' => 'Discarded Name'], $log->field_conflicts['trade_name']);
        });
    }

    public function test_merge_flattens_a_prior_tombstone_chain(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $survivor = $this->makeCompany('Chain Survivor');
            $loser = $this->makeCompany('Chain Loser');
            $alreadyMerged = $this->makeCompany('Already Merged Into Loser');
            $alreadyMerged->update(['is_active' => false, 'merged_into_partner_id' => $loser->id]);

            $ids = ['survivor' => $survivor->id, 'loser' => $loser->id, 'chain' => $alreadyMerged->id];
        });

        $this->post('/crm/merge', [
            'survivor_partner_id' => $ids['survivor'],
            'loser_partner_id' => $ids['loser'],
        ])->assertRedirect();

        $tenant->run(function () use ($ids) {
            // One hop, not a chain through the loser.
            $this->assertSame($ids['survivor'], Partner::query()->find($ids['chain'])->merged_into_partner_id);
        });
    }

    public function test_merge_validation_rejects_different_types_already_merged_and_parent_child_pairs(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $company = $this->makeCompany('Type A');
            $contact = $this->makeContact('Type B');

            $survivor = $this->makeCompany('Already Merged Survivor');
            $loser = $this->makeCompany('Already Merged Loser');
            $loser->update(['merged_into_partner_id' => $survivor->id, 'is_active' => false]);
            $freshTarget = $this->makeCompany('Fresh Target');

            // Same type (organization) as the parent, so this specifically isolates the
            // parent/child guard from the "different type" guard checked before it.
            $parent = $this->makeCompany('Parent Co');
            $child = Partner::query()->create(['type' => Partner::TYPE_ORGANIZATION, 'name' => 'Child Co', 'parent_partner_id' => $parent->id]);

            $ids = compact('company', 'contact', 'survivor', 'loser', 'freshTarget', 'parent', 'child');
            $ids = array_map(fn ($m) => $m->id, $ids);
        });

        $this->post('/crm/merge', [
            'survivor_partner_id' => $ids['company'],
            'loser_partner_id' => $ids['contact'],
        ])->assertSessionHasErrors(['loser_partner_id']);

        $this->post('/crm/merge', [
            'survivor_partner_id' => $ids['freshTarget'],
            'loser_partner_id' => $ids['loser'],
        ])->assertSessionHasErrors(['loser_partner_id']);

        $this->post('/crm/merge', [
            'survivor_partner_id' => $ids['parent'],
            'loser_partner_id' => $ids['child'],
        ])->assertSessionHasErrors(['loser_partner_id']);
    }

    public function test_merge_request_rejects_a_nonexistent_partner_and_the_same_partner_on_both_sides(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->post('/crm/merge', [
            'survivor_partner_id' => $companyId,
            'loser_partner_id' => 999999,
        ])->assertSessionHasErrors(['loser_partner_id']);

        $this->post('/crm/merge', [
            'survivor_partner_id' => $companyId,
            'loser_partner_id' => $companyId,
        ])->assertSessionHasErrors(['loser_partner_id']);
    }

    /** merge()'s own self-merge guard is unreachable via HTTP — MergePartnersRequest's `different:` rule already blocks equal ids. */
    public function test_merge_service_rejects_a_self_merge_when_called_directly(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $partner = $this->makeCompany();

            $this->expectException(ValidationException::class);
            app(PartnerMergeService::class)->merge($partner, $partner, null);
        });
    }

    /**
     * parsePgIntArray's empty-string branch is unreachable through duplicateGroups() itself —
     * both of its queries only return a group row via `HAVING count(*) > 1`, so the
     * `array_agg` result they parse can never actually be the empty-array literal "{}".
     * Invoked directly via reflection since it's a private defensive branch.
     */
    public function test_parse_pg_int_array_handles_the_empty_array_literal(): void
    {
        $method = new \ReflectionMethod(PartnerMergeService::class, 'parsePgIntArray');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(new PartnerMergeService, '{}'));
    }
}
