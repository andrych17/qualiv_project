<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\Address;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerMergeLog;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpCrm;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Inverse relations (child -> parent) that no controller/service ever navigates,
 * plus full branch coverage of the shared HasSlaState trait (App\Modules\CRM\Concerns\HasSlaState)
 * across both its SQL scope and its PHP accessor — see ScheduleModelRelationsTest for the
 * same pattern on the Schedule module.
 */
class CrmModelRelationsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpCrm;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_inverse_relations_resolve_to_their_owning_records(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $owner = User::query()->first();
            $partner = Partner::query()->create(['type' => Partner::TYPE_ORGANIZATION, 'name' => 'Relations Co', 'owner_id' => $owner->id]);
            $roleType = $this->makeRoleType();
            $role = PartnerRole::query()->create(['partner_id' => $partner->id, 'role_type_id' => $roleType->id, 'assigned_at' => now(), 'is_active' => true]);
            $address = Address::query()->create(['partner_id' => $partner->id, 'type' => 'office', 'line1' => 'X']);
            $contactPoint = ContactPoint::query()->create(['partner_id' => $partner->id, 'type' => 'email', 'value' => 'x@x.test']);

            $lead = Lead::query()->create(['name' => 'Relations Lead', 'stage' => Lead::STAGE_NEW]);
            $activity = LeadActivity::query()->create(['lead_id' => $lead->id, 'activity_type' => 'note', 'body' => 'x', 'logged_at' => now()]);

            $survivor = Partner::query()->create(['type' => Partner::TYPE_ORGANIZATION, 'name' => 'Survivor']);
            $mergeLog = PartnerMergeLog::query()->create([
                'merged_from_partner_id' => $partner->id, 'merged_into_partner_id' => $survivor->id,
                'merged_by' => $owner->id, 'merged_at' => now(),
            ]);

            $this->assertSame($partner->id, $role->partner->id);
            $this->assertSame($roleType->id, $role->roleType->id);
            $this->assertSame($partner->id, $address->partner->id);
            $this->assertSame($partner->id, $contactPoint->partner->id);
            $this->assertSame($lead->id, $activity->lead->id);
            $this->assertSame($owner->id, $partner->owner->id);
            $this->assertSame($partner->id, $mergeLog->mergedFrom->id);
            $this->assertSame($survivor->id, $mergeLog->mergedInto->id);
            $this->assertSame($owner->id, $mergeLog->mergedBy->id);
        });
    }

    public function test_has_sla_state_accessor_covers_every_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $partnerId = $this->makeCompany()->id;

            $breached = ServiceCase::query()->create(['partner_id' => $partnerId, 'subject' => 'A', 'status' => 'open', 'priority' => 'normal', 'sla_due_at' => Carbon::parse('2026-11-09 09:00:00')]);
            $dueSoon = ServiceCase::query()->create(['partner_id' => $partnerId, 'subject' => 'B', 'status' => 'open', 'priority' => 'normal', 'sla_due_at' => Carbon::parse('2026-11-10 18:00:00')]);
            $noDueDate = ServiceCase::query()->create(['partner_id' => $partnerId, 'subject' => 'C', 'status' => 'open', 'priority' => 'normal']);
            $farFuture = ServiceCase::query()->create(['partner_id' => $partnerId, 'subject' => 'D', 'status' => 'open', 'priority' => 'normal', 'sla_due_at' => Carbon::parse('2026-12-01 09:00:00')]);
            $closedButOverdue = ServiceCase::query()->create(['partner_id' => $partnerId, 'subject' => 'E', 'status' => 'resolved', 'priority' => 'normal', 'sla_due_at' => Carbon::parse('2026-01-01 09:00:00')]);

            $this->assertSame('breached', $breached->sla_state);
            $this->assertSame('due_soon', $dueSoon->sla_state);
            $this->assertSame('on_track', $noDueDate->sla_state);
            $this->assertSame('on_track', $farFuture->sla_state);
            $this->assertSame('on_track', $closedButOverdue->sla_state);
        });
    }

    public function test_filter_sla_state_scope_covers_the_default_and_due_soon_branches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $tenant = $this->loginAsCrmAdmin();

        $tenant->run(function () {
            $partnerId = $this->makeCompany()->id;
            Ticket::query()->create(['partner_id' => $partnerId, 'subject' => 'Due soon ticket', 'status' => 'open', 'priority' => 'normal', 'channel' => 'email', 'sla_due_at' => Carbon::parse('2026-11-10 18:00:00')]);
            Ticket::query()->create(['partner_id' => $partnerId, 'subject' => 'Far future ticket', 'status' => 'open', 'priority' => 'normal', 'channel' => 'email', 'sla_due_at' => Carbon::parse('2026-12-01 09:00:00')]);
        });

        $this->get('/crm/tickets?sla_state=due_soon')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1)->where('tickets.data.0.subject', 'Due soon ticket'));

        // An unrecognized sla_state value hits the scope's `default => null` no-op arm — no filtering applied.
        $this->get('/crm/tickets?sla_state=not_a_real_state')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 2));
    }
}
