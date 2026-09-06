<?php

namespace Tests\Feature;

use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Services\ProcessPhaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3F — Process Phases & Parameters: one recipe's whole phase set at a time, no MES-owned header row (PP.pp_recipes owns it). */
class MesProcessPhaseTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_admin_can_create_edit_update_and_delete_a_phase_set(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $recipeId = null;
        $recipeSku = null;
        $workCenterId = null;
        $tenant->run(function () use (&$recipeId, &$recipeSku, &$workCenterId) {
            $product = $this->makeProduct('PH-1');
            $recipeSku = $product->sku;
            $recipeId = $this->makeRecipe($product->id)->id;
            $workCenterId = $this->makeWorkCenter()->id;
        });

        $this->get('/mes/process-phases')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ProcessPhases/Index'));
        $this->get('/mes/process-phases/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ProcessPhases/Create'));

        $this->post('/mes/process-phases', [
            'recipe_id' => $recipeId,
            'phases' => [
                [
                    'phase_name' => 'Mix', 'work_center_id' => $workCenterId, 'standard_duration_minutes' => 30,
                    'parameters' => [
                        ['parameter_code' => 'TEMP', 'target_value' => 15, 'min_value' => 10, 'max_value' => 20, 'uom_code' => 'C'],
                    ],
                ],
                ['phase_name' => 'Cool', 'work_center_id' => $workCenterId, 'standard_duration_minutes' => 15],
            ],
        ])->assertRedirect(route('mes.processPhases.index'));

        $tenant->run(function () use ($recipeId) {
            $this->assertSame(2, ProcessPhase::query()->where('recipe_id', $recipeId)->count());
            $mix = ProcessPhase::query()->where('recipe_id', $recipeId)->where('phase_name', 'Mix')->firstOrFail();
            $this->assertSame(1, $mix->parameters()->count());
        });

        $this->get('/mes/process-phases?sort=recipe_id&direction=desc')->assertOk()
            ->assertInertia(fn ($page) => $page->has('phaseSets.data', 1));

        $this->get("/mes/process-phases/{$recipeId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ProcessPhases/Edit')
                ->has('phases', 2)
                ->where('recipe.id', $recipeId));

        $this->put("/mes/process-phases/{$recipeId}", [
            'phases' => [
                ['phase_name' => 'Mix Renamed', 'work_center_id' => $workCenterId, 'standard_duration_minutes' => 45],
            ],
        ])->assertRedirect(route('mes.processPhases.index'));

        $tenant->run(function () use ($recipeId) {
            $this->assertSame(1, ProcessPhase::query()->where('recipe_id', $recipeId)->count());
            $this->assertSame('Mix Renamed', ProcessPhase::query()->where('recipe_id', $recipeId)->first()->phase_name);
        });

        $this->delete("/mes/process-phases/{$recipeId}")->assertRedirect(route('mes.processPhases.index'));
        $tenant->run(function () use ($recipeId) {
            $this->assertSame(0, ProcessPhase::query()->where('recipe_id', $recipeId)->count());
        });
    }

    public function test_store_rejects_an_invalid_recipe_a_recipe_that_already_has_a_phase_set_and_an_invalid_work_center(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $recipeId = null;
        $tenant->run(function () use (&$recipeId) {
            $product = $this->makeProduct('PH-2');
            $recipeId = $this->makeRecipe($product->id)->id;
        });

        $this->post('/mes/process-phases', [
            'recipe_id' => 999999,
            'phases' => [['phase_name' => 'X', 'work_center_id' => 999999]],
        ])->assertSessionHasErrors(['recipe_id', 'phases.0.work_center_id']);

        $this->post('/mes/process-phases', ['recipe_id' => $recipeId, 'phases' => []])
            ->assertSessionHasErrors(['phases']);

        $this->post('/mes/process-phases', [
            'recipe_id' => $recipeId,
            'phases' => [['phase_name' => 'Mix']],
        ])->assertRedirect(route('mes.processPhases.index'));

        // Now the recipe already has a phase set — a second store for the same recipe is rejected.
        $this->post('/mes/process-phases', [
            'recipe_id' => $recipeId,
            'phases' => [['phase_name' => 'Again']],
        ])->assertSessionHasErrors(['recipe_id']);
    }

    public function test_update_rejects_an_invalid_work_center(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $recipeId = null;
        $tenant->run(function () use (&$recipeId) {
            $product = $this->makeProduct('PH-3');
            $recipeId = $this->makeRecipe($product->id)->id;
        });

        $this->put("/mes/process-phases/{$recipeId}", [
            'phases' => [['phase_name' => 'X', 'work_center_id' => 999999]],
        ])->assertSessionHasErrors(['phases.0.work_center_id']);
    }

    /** §3U example: a process-parameter target changed on an active recipe is logged as a before/after phase-set snapshot. */
    public function test_update_writes_a_mes_audit_log_snapshot(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $recipeId = null;
        $workCenterId = null;
        $tenant->run(function () use (&$recipeId, &$workCenterId) {
            $product = $this->makeProduct('PH-4');
            $recipeId = $this->makeRecipe($product->id)->id;
            $workCenterId = $this->makeWorkCenter()->id;
        });

        $this->post('/mes/process-phases', [
            'recipe_id' => $recipeId,
            'phases' => [['phase_name' => 'Mix', 'work_center_id' => $workCenterId, 'standard_duration_minutes' => 30]],
        ])->assertRedirect(route('mes.processPhases.index'));

        $this->put("/mes/process-phases/{$recipeId}", [
            'phases' => [['phase_name' => 'Mix', 'work_center_id' => $workCenterId, 'standard_duration_minutes' => 60]],
        ])->assertRedirect(route('mes.processPhases.index'));

        $this->get('/mes/audit-logs')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/AuditLogs/Index')->has('logs.data', 1));
    }

    public function test_skips_a_phase_with_a_blank_name_and_a_parameter_with_a_blank_code_when_called_directly(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('PH-5');
            $recipe = $this->makeRecipe($product->id);

            $result = app(ProcessPhaseService::class)->create([
                'recipe_id' => $recipe->id,
                'phases' => [
                    ['phase_name' => ''],
                    ['phase_name' => 'Real Phase', 'parameters' => [
                        ['parameter_code' => ''],
                        ['parameter_code' => 'TEMP', 'target_value' => 10],
                    ]],
                ],
            ]);

            $this->assertSame(1, $result->count());
            $this->assertSame('Real Phase', $result->first()->phase_name);
            $this->assertSame(1, $result->first()->parameters->count());
        });
    }
}
