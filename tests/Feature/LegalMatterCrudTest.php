<?php

namespace Tests\Feature;

use App\Modules\Legal\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class LegalMatterCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_crud_legal_matter_when_plan_allows(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/legal/matters')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal/Matters/Index'));

        $this->post('/legal/matters', [
            'code' => 'MATTER-001',
            'title' => 'Demo Matter',
            'status' => 'open',
            'notes' => 'hello',
        ])->assertRedirect(route('legal.matters.index'));

        $matterId = null;
        $tenant->run(function () use (&$matterId) {
            $matter = Matter::query()->where('code', 'MATTER-001')->first();
            $this->assertNotNull($matter);
            $this->assertNotEmpty($matter->uuid);
            $this->assertNotNull($matter->opened_at);
            $matterId = $matter->id;
        });

        $this->put('/legal/matters/'.$matterId, [
            'code' => 'MATTER-001',
            'title' => 'Demo Matter Updated',
            'status' => 'on_hold',
            'notes' => 'updated',
        ])->assertRedirect(route('legal.matters.index'));

        $this->delete('/legal/matters/'.$matterId)
            ->assertRedirect(route('legal.matters.index'));
    }

    public function test_starter_plan_blocks_legal_module(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/legal/matters')->assertForbidden();
    }
}
