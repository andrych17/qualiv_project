<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\ServiceCase;
use App\Modules\CRM\Models\ServiceCaseActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpCrm;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E — After Sales Service: SLA-tracked cases with a closed-then-reopenable-within-grace-window lifecycle. */
class CrmServiceCaseTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpCrm;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_crud_a_service_case_add_notes_and_change_status(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $partnerId = null;
        $categoryId = null;
        $tenant->run(function () use (&$partnerId, &$categoryId) {
            $partnerId = $this->makeCompany()->id;
            $categoryId = $this->makeTicketCategory()->id;
        });

        $this->get('/crm/service-cases')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/ServiceCases/Index'));
        $this->get('/crm/service-cases/create')->assertOk()->assertInertia(fn ($page) => $page->component('CRM/ServiceCases/Create'));

        $this->post('/crm/service-cases', [
            'partner_id' => $partnerId,
            'subject' => 'Product not working',
            'category_id' => $categoryId,
            'priority' => 'high',
        ])->assertRedirect();

        $caseId = null;
        $tenant->run(function () use (&$caseId) {
            $case = ServiceCase::query()->where('subject', 'Product not working')->first();
            $this->assertSame(ServiceCase::STATUS_OPEN, $case->status);
            $this->assertSame(1, ServiceCaseActivity::query()->where('case_id', $case->id)->where('activity_type', 'note')->count());
            $caseId = $case->id;
        });

        $this->get("/crm/service-cases/{$caseId}/edit")->assertOk()->assertInertia(fn ($page) => $page
            ->component('CRM/ServiceCases/Edit')
            ->where('serviceCase.subject', 'Product not working')
            ->where('serviceCase.can_reopen', false));

        $this->put("/crm/service-cases/{$caseId}", [
            'partner_id' => $partnerId,
            'subject' => 'Product not working (escalated)',
            'priority' => 'urgent',
        ])->assertRedirect(route('crm.serviceCases.index'));

        $tenant->run(function () use ($caseId) {
            $this->assertSame('Product not working (escalated)', ServiceCase::query()->find($caseId)->subject);
        });

        $this->post("/crm/service-cases/{$caseId}/activities", ['body' => 'Called the customer back.'])->assertRedirect();
        $tenant->run(function () use ($caseId) {
            $this->assertSame(2, ServiceCaseActivity::query()->where('case_id', $caseId)->count());
        });

        $this->patch("/crm/service-cases/{$caseId}/status", ['status' => ServiceCase::STATUS_RESOLVED])->assertRedirect();
        $tenant->run(function () use ($caseId) {
            $case = ServiceCase::query()->find($caseId);
            $this->assertSame(ServiceCase::STATUS_RESOLVED, $case->status);
            $this->assertNull($case->closed_at);
        });

        $this->patch("/crm/service-cases/{$caseId}/status", ['status' => ServiceCase::STATUS_CLOSED])->assertRedirect();
        $tenant->run(function () use ($caseId) {
            $case = ServiceCase::query()->find($caseId);
            $this->assertSame(ServiceCase::STATUS_CLOSED, $case->status);
            $this->assertNotNull($case->closed_at);
            $this->assertTrue($case->canReopen());
        });

        // Reopening within the grace window clears closed_at again.
        $this->patch("/crm/service-cases/{$caseId}/status", ['status' => ServiceCase::STATUS_OPEN])->assertRedirect();
        $tenant->run(function () use ($caseId) {
            $this->assertNull(ServiceCase::query()->find($caseId)->closed_at);
        });
    }

    public function test_a_case_closed_past_the_grace_window_cannot_be_reopened(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00'));

        $tenant = $this->loginAsCrmAdmin();

        $caseId = null;
        $tenant->run(function () use (&$caseId) {
            $case = ServiceCase::query()->create([
                'partner_id' => $this->makeCompany()->id, 'subject' => 'Stale case',
                'status' => ServiceCase::STATUS_CLOSED, 'priority' => 'normal',
                'closed_at' => Carbon::parse('2026-11-20 09:00:00')->subDays(ServiceCase::REOPEN_GRACE_DAYS + 1),
            ]);
            $this->assertFalse($case->canReopen());
            $caseId = $case->id;
        });

        $this->patch("/crm/service-cases/{$caseId}/status", ['status' => ServiceCase::STATUS_OPEN])
            ->assertSessionHasErrors(['status']);
    }

    public function test_service_case_index_filters_by_sla_state_status_priority_assignee_and_sort(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $tenant = $this->loginAsCrmAdmin();

        $assigneeId = null;
        $tenant->run(function () use (&$assigneeId) {
            $assigneeId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $partnerId = $this->makeCompany()->id;

            ServiceCase::query()->create([
                'partner_id' => $partnerId, 'subject' => 'Breached case', 'status' => ServiceCase::STATUS_OPEN,
                'priority' => 'high', 'assigned_to' => $assigneeId, 'sla_due_at' => Carbon::parse('2026-11-09 09:00:00'),
            ]);
            ServiceCase::query()->create([
                'partner_id' => $partnerId, 'subject' => 'On track case', 'status' => ServiceCase::STATUS_OPEN,
                'priority' => 'low', 'sla_due_at' => Carbon::parse('2026-12-01 09:00:00'),
            ]);
        });

        $this->get('/crm/service-cases?search=Breached')->assertOk()
            ->assertInertia(fn ($page) => $page->has('cases.data', 1));

        $this->get('/crm/service-cases?sla_state=breached')->assertOk()
            ->assertInertia(fn ($page) => $page->has('cases.data', 1)->where('cases.data.0.subject', 'Breached case'));

        $this->get('/crm/service-cases?sla_state=on_track')->assertOk()
            ->assertInertia(fn ($page) => $page->has('cases.data', 1)->where('cases.data.0.subject', 'On track case'));

        $this->get('/crm/service-cases?priority=low')->assertOk()
            ->assertInertia(fn ($page) => $page->has('cases.data', 1)->where('cases.data.0.subject', 'On track case'));

        $this->get('/crm/service-cases?status='.ServiceCase::STATUS_OPEN)->assertOk()
            ->assertInertia(fn ($page) => $page->has('cases.data', 2));

        $this->get("/crm/service-cases?assigned_to={$assigneeId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('cases.data', 1));

        $this->get('/crm/service-cases?sort=subject&direction=asc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('cases.data.0.subject', 'Breached case'));
    }

    public function test_store_and_update_service_case_validation_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsCrmAdmin();

        $this->post('/crm/service-cases', [])->assertSessionHasErrors(['partner_id', 'subject']);

        $this->post('/crm/service-cases', [
            'partner_id' => 999999,
            'subject' => 'Bad refs',
            'category_id' => 999999,
        ])->assertSessionHasErrors(['partner_id', 'category_id']);

        $caseId = null;
        $tenant->run(function () use (&$caseId) {
            $caseId = ServiceCase::query()->create(['partner_id' => $this->makeCompany()->id, 'subject' => 'Editable', 'status' => 'open', 'priority' => 'normal'])->id;
        });

        $this->put("/crm/service-cases/{$caseId}", [
            'partner_id' => 999999,
            'subject' => 'Bad refs on update',
            'priority' => 'normal',
            'category_id' => 999999,
        ])->assertSessionHasErrors(['partner_id', 'category_id']);
    }
}
