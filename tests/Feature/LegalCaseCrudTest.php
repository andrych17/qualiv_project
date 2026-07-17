<?php

namespace Tests\Feature;

use App\Modules\Legal\Models\LegalCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalCaseCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_crud_legal_case_when_plan_allows(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/legal/cases')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal/Cases/Index'));

        $this->post('/legal/cases', [
            'code' => 'CASE-001',
            'title' => 'Demo Matter',
            'status' => 'open',
            'notes' => 'hello',
        ])->assertRedirect(route('legal.cases.index'));

        $caseId = null;
        $tenant->run(function () use (&$caseId) {
            $case = LegalCase::query()->where('code', 'CASE-001')->first();
            $this->assertNotNull($case);
            $this->assertNotEmpty($case->uuid);
            $caseId = $case->id;
        });

        $this->put('/legal/cases/'.$caseId, [
            'code' => 'CASE-001',
            'title' => 'Demo Matter Updated',
            'status' => 'pending',
            'notes' => 'updated',
        ])->assertRedirect(route('legal.cases.index'));

        $this->delete('/legal/cases/'.$caseId)
            ->assertRedirect(route('legal.cases.index'));
    }

    public function test_starter_plan_blocks_legal_module(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/legal/cases')->assertForbidden();
    }
}
