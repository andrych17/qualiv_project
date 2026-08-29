<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Models\FieldVisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * LEGAL_SPECS.md §3M mobile surface — bearer-token auth (routes/api.php,
 * App\Http\Middleware\InitializeTenancyByHeader) instead of the session-based web login every
 * other Feature test in this suite exercises.
 */
class LegalFieldVisitApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_full_mobile_lifecycle_login_to_complete(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $visitId = null;
        $tenant->run(function () use (&$visitId) {
            $type = FieldVisitType::query()->create([
                'code' => 'site_survey_api', 'name' => 'Site Survey',
                'default_checklist' => ['Verify boundary markers'],
                'is_active' => true,
            ]);

            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');

            $visitId = FieldVisit::query()->create([
                'visit_type_id' => $type->id,
                'assigned_to' => $userId,
                'status' => FieldVisit::STATUS_SCHEDULED,
            ])->id;
        });

        $tenants = $this->postJson('/api/v1/auth/tenants', ['email' => 'admin@nusaevo.com'])
            ->assertOk()
            ->json('tenants');
        $this->assertSame('001', $tenants[0]['id']);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => '001',
        ])->assertOk()->assertJsonStructure(['token', 'tenant_id', 'user' => ['id', 'name', 'email']]);

        $token = $login->json('token');
        $this->assertSame('001', $login->json('tenant_id'));

        $headers = ['Authorization' => "Bearer {$token}", 'X-Tenant-Id' => '001'];

        $this->getJson('/api/v1/legal/field-visits', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visitId)
            ->assertJsonPath('data.0.status', FieldVisit::STATUS_SCHEDULED);

        $this->postJson("/api/v1/legal/field-visits/{$visitId}/check-in", [
            'gps_lat' => -6.2,
            'gps_lng' => 106.8,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', FieldVisit::STATUS_CHECKED_IN);

        $this->postJson("/api/v1/legal/field-visits/{$visitId}/complete", [
            'checklist_result' => [['label' => 'Verify boundary markers', 'done' => true, 'note' => 'Clear']],
            'notes' => 'All good',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', FieldVisit::STATUS_COMPLETED);

        $tenant->run(function () use ($visitId) {
            $visit = FieldVisit::query()->find($visitId);
            $this->assertSame(FieldVisit::STATUS_COMPLETED, $visit->status);
            $this->assertSame('All good', $visit->notes);
        });

        // Logout revokes the token — the same one must then be rejected. Auth::forgetGuards()
        // undoes a test-harness-only artifact: RequestGuard memoizes its resolved user for the
        // life of the guard instance, and every simulated call in this test shares one
        // Application (a real deployment never does — each request is its own process), so the
        // earlier successful resolution would otherwise mask the just-revoked token.
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/v1/legal/field-visits', $headers)->assertUnauthorized();
    }

    public function test_login_rejects_wrong_password(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'wrong-password',
            'tenant_id' => '001',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_field_visits_require_tenant_header(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => '001',
        ])->assertOk();

        // login() itself calls tenancy()->initialize() — end it here so the next call starts
        // from the same tenant-less state a genuinely fresh request would (every real request
        // is its own process; this test method shares one Application across both calls).
        tenancy()->end();

        $this->getJson('/api/v1/legal/field-visits', [
            'Authorization' => 'Bearer '.$login->json('token'),
        ])->assertUnauthorized();
    }
}
