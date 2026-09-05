<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\Ticket;
use App\Modules\CRM\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpCrm;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3A — CRM's landing page: summary cards, SLA-first "my work" queues, and the four record drawers. */
class CrmDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpCrm;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_summary_counts_and_sla_first_ordering(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $tenant = $this->loginAsCrmAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $partner = $this->makeCompany();

            Lead::query()->create(['name' => 'Open lead', 'stage' => Lead::STAGE_NEW, 'owner_id' => $adminId]);
            Lead::query()->create(['name' => 'Closed lead', 'stage' => Lead::STAGE_CONVERTED, 'owner_id' => $adminId]);

            // On-track ticket created first, breached ticket second — SLA-first sort must still put breached on top.
            Ticket::query()->create([
                'partner_id' => $partner->id, 'subject' => 'On track ticket', 'status' => 'open',
                'priority' => 'normal', 'channel' => 'email', 'assigned_to' => $adminId,
                'sla_due_at' => Carbon::parse('2026-12-01 09:00:00'),
            ]);
            Ticket::query()->create([
                'partner_id' => $partner->id, 'subject' => 'Breached ticket', 'status' => 'open',
                'priority' => 'high', 'channel' => 'email', 'assigned_to' => $adminId,
                'sla_due_at' => Carbon::parse('2026-11-09 09:00:00'),
            ]);

            ServiceCase::query()->create([
                'partner_id' => $partner->id, 'subject' => 'My case', 'status' => 'open',
                'priority' => 'normal', 'assigned_to' => $adminId,
            ]);
        });

        $response = $this->get('/crm/dashboard')->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('CRM/Dashboard/Index')
            ->where('summary.open_leads', 1)
            ->where('summary.open_tickets', 2)
            ->where('summary.open_service_cases', 1)
            ->where('summary.partners_added_30d', 1)
            ->where('canUpdate', true)
            ->has('myLeads', 1)
            ->has('myServiceCases', 1));

        $tickets = collect($response->viewData('page')['props']['myTickets']);
        $this->assertSame('Breached ticket', $tickets->first()['subject']);
        $this->assertSame('breached', $tickets->first()['sla_state']);
    }

    public function test_lead_ticket_case_and_partner_drawers_return_the_expected_shapes(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $lead = Lead::query()->create(['name' => 'Drawer lead', 'stage' => Lead::STAGE_NEW]);
            $lead->activities()->create(['activity_type' => 'note', 'body' => 'hi', 'logged_at' => now()]);

            $company = $this->makeCompany('Drawer Co');
            $roleType = $this->makeRoleType();
            $company->roles()->create(['role_type_id' => $roleType->id, 'assigned_at' => now(), 'is_active' => true]);

            $ticket = Ticket::query()->create(['partner_id' => $company->id, 'subject' => 'Drawer ticket', 'status' => 'open', 'priority' => 'normal', 'channel' => 'email']);
            TicketMessage::query()->create(['ticket_id' => $ticket->id, 'direction' => TicketMessage::DIRECTION_OUTBOUND, 'body' => 'hello', 'sent_at' => now()]);

            $case = ServiceCase::query()->create(['partner_id' => $company->id, 'subject' => 'Drawer case', 'status' => 'open', 'priority' => 'normal']);
            $case->activities()->create(['activity_type' => 'note', 'body' => 'note', 'logged_at' => now()]);

            $individual = $this->makeContact('Drawer Contact');

            $ids = ['lead' => $lead->id, 'company' => $company->id, 'ticket' => $ticket->id, 'case' => $case->id, 'individual' => $individual->id];
        });

        $lead = $this->get("/crm/dashboard/lead/{$ids['lead']}")->assertOk()->json();
        $this->assertSame('lead', $lead['type']);
        $this->assertSame('Drawer lead', $lead['record']['name']);
        $this->assertCount(1, $lead['activities']);

        $ticket = $this->get("/crm/dashboard/ticket/{$ids['ticket']}")->assertOk()->json();
        $this->assertSame('ticket', $ticket['type']);
        $this->assertCount(1, $ticket['activities']);

        $case = $this->get("/crm/dashboard/case/{$ids['case']}")->assertOk()->json();
        $this->assertSame('case', $case['type']);
        $this->assertCount(1, $case['activities']);

        $companyDrawer = $this->get("/crm/dashboard/partner/{$ids['company']}")->assertOk()->json();
        $this->assertSame('partner', $companyDrawer['type']);
        $this->assertSame(route('crm.companies.edit', $ids['company']), $companyDrawer['record']['edit_url']);
        $this->assertSame(1, $companyDrawer['references']['service_cases']);
        $this->assertSame(1, $companyDrawer['references']['tickets']);
        $this->assertSame(1, $companyDrawer['references']['roles']);

        $contactDrawer = $this->get("/crm/dashboard/partner/{$ids['individual']}")->assertOk()->json();
        $this->assertSame(route('crm.contacts.edit', $ids['individual']), $contactDrawer['record']['edit_url']);
    }
}
