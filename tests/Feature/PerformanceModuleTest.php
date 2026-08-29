<?php

namespace Tests\Feature;

use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Perspective;
use App\Modules\Performance\Models\Target;
use App\Modules\Performance\Services\OkrProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PerformanceModuleTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_starter_plan_blocks_performance_module(): void
    {
        $tenant = $this->provisionTenant('pf1');
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $this->get('/performance/dashboard')->assertForbidden();
    }

    public function test_admin_can_access_performance_dashboard(): void
    {
        $tenant = $this->provisionTenant('pf2');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $this->get('/performance/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Dashboard'));
    }

    public function test_perspectives_and_periods_crud(): void
    {
        $tenant = $this->provisionTenant('pf3');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        // 1. Create Perspective
        $this->post('/performance/perspectives', [
            'name' => 'Financial Growth',
            'description' => 'Financial metrics and revenue targets',
            'is_active' => true,
        ])->assertRedirect(route('performance.perspectives.index'));

        $tenant->run(function () {
            $this->assertDatabaseHas('PERF.perspectives', ['name' => 'Financial Growth']);
        });

        // 2. Create Period
        $this->post('/performance/periods', [
            'label' => 'Q1 2026',
            'period_type' => 'quarter',
            'year' => 2026,
            'quarter' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'is_active' => true,
        ])->assertRedirect(route('performance.periods.index'));

        $tenant->run(function () {
            $this->assertDatabaseHas('PERF.periods', ['label' => 'Q1 2026']);
        });
    }

    public function test_kpi_definition_target_and_actual_value_capture(): void
    {
        $tenant = $this->provisionTenant('pf4');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $perspectiveId = null;
        $periodId = null;

        $tenant->run(function () use (&$perspectiveId, &$periodId) {
            $persp = Perspective::query()->create(['name' => 'Customer', 'is_active' => true]);
            $period = Period::query()->create([
                'label' => 'FY 2026',
                'period_type' => 'year',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'is_active' => true,
            ]);

            $perspectiveId = $persp->id;
            $periodId = $period->id;
        });

        // 1. Create KPI Definition
        $this->post('/performance/kpi-definitions', [
            'name' => 'Deeds Executed per Month',
            'unit' => KpiDefinition::UNIT_NUMBER,
            'direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER,
            'perspective_id' => $perspectiveId,
            'description' => 'Target volume of signed legal deeds',
            'is_active' => true,
        ])->assertRedirect(route('performance.kpiDefinitions.index'));

        $kpiId = null;
        $tenant->run(function () use (&$kpiId) {
            $kpi = KpiDefinition::query()->where('name', 'Deeds Executed per Month')->first();
            $this->assertNotNull($kpi);
            $kpiId = $kpi->id;
        });

        // 2. Set Target for Company
        $this->post('/performance/targets', [
            'kpi_id' => $kpiId,
            'subject_type' => 'company',
            'period_id' => $periodId,
            'target_value' => 50,
            'stretch_value' => 65,
            'notes' => 'Target for Q1 notarial deed closings',
        ])->assertRedirect(route('performance.targets.index'));

        $tenant->run(function () use ($kpiId) {
            $this->assertDatabaseHas('PERF.targets', [
                'kpi_id' => $kpiId,
                'subject_type' => 'company',
                'target_value' => 50,
            ]);
        });

        // 3. Record KPI Actual Value
        $this->post('/performance/kpi-values', [
            'kpi_id' => $kpiId,
            'subject_type' => 'company',
            'period_id' => $periodId,
            'actual_value' => 55,
        ])->assertRedirect(route('performance.kpiValues.index'));

        $tenant->run(function () use ($kpiId) {
            $this->assertDatabaseHas('PERF.kpi_values', [
                'kpi_id' => $kpiId,
                'subject_type' => 'company',
                'actual_value' => 55,
            ]);
        });
    }

    public function test_okr_cycles_and_objectives_lifecycle(): void
    {
        $tenant = $this->provisionTenant('pf5');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        // 1. Create OKR Cycle
        $this->post('/performance/okr-cycles', [
            'label' => 'Q1 2026 OKR',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'is_active' => true,
        ])->assertRedirect(route('performance.okrCycles.index'));

        $cycleId = null;
        $tenant->run(function () use (&$cycleId) {
            $cycleId = OkrCycle::query()->where('label', 'Q1 2026 OKR')->value('id');
        });

        // 2. Create Objective with Key Results
        $this->post('/performance/okr-objectives', [
            'cycle_id' => $cycleId,
            'subject_type' => 'company',
            'objective_text' => 'Accelerate Notary & PPAT Digital Turnaround',
            'key_results' => [
                [
                    'description' => 'Complete 100% Tax Validations in under 24 hours',
                    'metric_type' => 'percent',
                    'start_value' => 0,
                    'current_value' => 75,
                    'target_value' => 100,
                    'weight' => 1,
                ],
                [
                    'description' => 'Zero overdue BPN submissions',
                    'metric_type' => 'boolean',
                    'start_value' => 0,
                    'current_value' => 1,
                    'target_value' => 1,
                    'weight' => 1,
                ],
            ],
        ])->assertRedirect(route('performance.okrObjectives.index'));

        $tenant->run(function () use ($cycleId) {
            $obj = OkrObjective::query()->where('cycle_id', $cycleId)->with('keyResults')->first();
            $this->assertNotNull($obj);
            $this->assertCount(2, $obj->keyResults);

            $progress = app(OkrProgressService::class)->objectiveProgress($obj);
            $this->assertNotNull($progress);
            $this->assertGreaterThan(0, $progress);
        });
    }

    public function test_budget_creation_and_approval_workflow(): void
    {
        $tenant = $this->provisionTenant('pf6');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $periodId = null;

        $tenant->run(function () use (&$periodId) {
            $period = Period::query()->create([
                'label' => '2026 Operating Period',
                'period_type' => 'year',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'is_active' => true,
            ]);
            $periodId = $period->id;
        });

        // 1. Create Budget Draft
        $this->post('/performance/budgets', [
            'name' => 'FY2026 Operational Budget',
            'subject_type' => 'company',
            'fiscal_year' => 2026,
            'lines' => [
                [
                    'category' => 'Legal Research & Software',
                    'period_id' => $periodId,
                    'amount_planned' => 15000000,
                ],
                [
                    'category' => 'Notary Office Supplies',
                    'period_id' => $periodId,
                    'amount_planned' => 5000000,
                ],
            ],
        ])->assertRedirect(route('performance.budgets.index'));

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budget = Budget::query()->where('name', 'FY2026 Operational Budget')->first();
            $this->assertNotNull($budget);
            $this->assertSame(Budget::STATUS_DRAFT, $budget->status);
            $budgetId = $budget->id;
        });

        // 2. Submit Budget
        $this->patch("/performance/budgets/{$budgetId}/submit")
            ->assertRedirect(route('performance.budgets.edit', $budgetId));

        $tenant->run(function () use ($budgetId) {
            $this->assertSame(Budget::STATUS_SUBMITTED, Budget::query()->find($budgetId)->status);
        });

        // 3. Approve Budget
        $this->patch("/performance/budgets/{$budgetId}/approve")
            ->assertRedirect(route('performance.budgets.edit', $budgetId));

        $tenant->run(function () use ($budgetId) {
            $this->assertSame(Budget::STATUS_APPROVED, Budget::query()->find($budgetId)->status);
        });

        // 4. Lock Budget
        $this->patch("/performance/budgets/{$budgetId}/lock")
            ->assertRedirect(route('performance.budgets.edit', $budgetId));

        $tenant->run(function () use ($budgetId) {
            $this->assertSame(Budget::STATUS_LOCKED, Budget::query()->find($budgetId)->status);
        });
    }

    public function test_scorecard_and_badges_management(): void
    {
        $tenant = $this->provisionTenant('pf7');
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        // 1. Create Badge Definition
        $this->post('/performance/badge-definitions', [
            'name' => 'Notary Champion',
            'trigger_type' => BadgeDefinition::TRIGGER_TARGET_HIT,
            'icon' => 'Award',
            'is_active' => true,
        ])->assertRedirect(route('performance.badgeDefinitions.index'));

        $tenant->run(function () {
            $this->assertDatabaseHas('PERF.badge_definitions', ['name' => 'Notary Champion']);
        });

        // 2. Award Achievement
        $badgeId = null;
        $tenant->run(function () use (&$badgeId) {
            $badgeId = BadgeDefinition::query()->where('name', 'Notary Champion')->value('id');
        });

        $this->post('/performance/achievements', [
            'badge_id' => $badgeId,
            'subject_type' => Achievement::SUBJECT_COMPANY,
        ])->assertRedirect(route('performance.achievements.index'));

        $tenant->run(function () use ($badgeId) {
            $this->assertDatabaseHas('PERF.achievements', [
                'badge_id' => $badgeId,
                'subject_type' => Achievement::SUBJECT_COMPANY,
            ]);
        });
    }
}
