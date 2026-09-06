<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3O — the read-only audit trail viewer; AuditLog itself enforces append-only, so there's no edit/delete action here. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_index_lists_and_filters_logs_by_action_subject_type_actor_and_search(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;

            AuditLog::record([
                'company_id' => $companyId,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.companies',
                'subject_id' => $companyId,
                'actor_id' => $this->adminUserId(),
            ]);

            AuditLog::record([
                'company_id' => $companyId,
                'action' => AuditLog::ACTION_TAX_DOCUMENT_ISSUED,
                'subject_type' => 'accounting.tax_faktur_pajaks',
                'subject_id' => 999,
                'actor_id' => $this->adminUserId(),
            ]);
        });

        $this->get("/accounting/audit-log?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AuditLog/Index')->has('logs.data', 2));

        $this->get("/accounting/audit-log?company_id={$companyId}&action=".AuditLog::ACTION_TAX_DOCUMENT_ISSUED)->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.action', AuditLog::ACTION_TAX_DOCUMENT_ISSUED));

        $this->get("/accounting/audit-log?company_id={$companyId}&subject_type=accounting.companies")->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 1));

        $adminId = $this->adminUserId();
        $this->get("/accounting/audit-log?company_id={$companyId}&actor_id={$adminId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));

        $this->get("/accounting/audit-log?company_id={$companyId}&search=admin")->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));

        $this->get("/accounting/audit-log?company_id={$companyId}&sort=created_at&direction=asc")->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));
    }

    public function test_record_defaults_actor_and_ip_to_null_when_running_in_console(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();

            $log = AuditLog::record([
                'company_id' => $company->id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.companies',
                'subject_id' => $company->id,
            ]);

            $this->assertNull($log->actor_id);
            $this->assertNull($log->ip_address);
        });
    }

    public function test_audit_log_is_append_only(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $log = AuditLog::record([
                'company_id' => $company->id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.companies',
                'subject_id' => $company->id,
            ]);

            try {
                $log->update(['action' => AuditLog::ACTION_JOURNAL_CREATED]);
                $this->fail('Expected a LogicException on update.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }

            try {
                $log->delete();
                $this->fail('Expected a LogicException on delete.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        });
    }

    /** AuditLogController's own $filters never includes company_id (it applies its own ->where() instead) — scopeFilter's company_id arm is reachable only via a direct call like this. */
    public function test_scope_filter_can_be_called_directly_with_a_company_id_filter(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $companyA = $this->makeCompany(['legal_name' => 'A']);
            $companyB = $this->makeCompany(['legal_name' => 'B']);
            AuditLog::record(['company_id' => $companyA->id, 'action' => AuditLog::ACTION_MASTER_DATA_CHANGED, 'subject_type' => 'x', 'subject_id' => 1]);
            AuditLog::record(['company_id' => $companyB->id, 'action' => AuditLog::ACTION_MASTER_DATA_CHANGED, 'subject_type' => 'x', 'subject_id' => 2]);

            $this->assertSame(1, AuditLog::query()->filter(['company_id' => $companyA->id])->count());
        });
    }
}
