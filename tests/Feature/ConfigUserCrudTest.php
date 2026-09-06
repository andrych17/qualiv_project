<?php

namespace Tests\Feature;

use App\Models\TenantUserLookup;
use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ConfigUserCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_create_user_with_group_and_lookup(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $groupId = null;
        $tenant->run(function () use (&$groupId) {
            $groupId = ConfigGroup::query()->where('code', 'STAFF')->value('id');
        });

        $this->get('/config/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Config/Users/Index'));

        $response = $this->post('/config/users', [
            'name' => 'New Staff',
            'email' => 'newstaff@nusaevo.com',
            'group_ids' => [$groupId],
        ]);
        $response->assertRedirect(route('config.users.index'));
        $generatedPassword = $response->getSession()->get('generated_credentials')['password'];
        $this->assertNotEmpty($generatedPassword);

        $tenant->run(function () use ($groupId, $generatedPassword) {
            $user = User::query()->where('email', 'newstaff@nusaevo.com')->first();
            $this->assertNotNull($user);
            $this->assertTrue(Hash::check($generatedPassword, $user->password));
            $this->assertTrue(
                ConfigGroupUser::query()
                    ->where('user_id', $user->id)
                    ->where('group_id', $groupId)
                    ->exists()
            );
        });

        $this->assertTrue(
            TenantUserLookup::query()
                ->where('email', 'newstaff@nusaevo.com')
                ->where('tenant_id', '001')
                ->exists()
        );
    }

    public function test_deactivated_user_cannot_login_and_reactivation_restores_access(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            User::factory()->create([
                'name' => 'Staff User',
                'email' => 'staff@nusaevo.com',
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
        });
        TenantUserLookup::query()->updateOrCreate(
            ['email' => 'staff@nusaevo.com', 'tenant_id' => '001'],
            [],
        );

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $userId = null;
        $tenant->run(function () use (&$userId) {
            $userId = User::query()->where('email', 'staff@nusaevo.com')->value('id');
        });

        $this->delete("/config/users/{$userId}")
            ->assertRedirect(route('config.users.index'));

        $tenant->run(function () use ($userId) {
            $this->assertFalse(User::query()->find($userId)->is_active);
        });

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'staff@nusaevo.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);
        $this->patch("/config/users/{$userId}/activate")
            ->assertRedirect(route('config.users.index'));
        $this->post('/logout');

        $this->post('/login', [
            'email' => 'staff@nusaevo.com',
            'password' => 'password',
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_admin_reset_password_generates_new_password_and_invalidates_old_one(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            User::factory()->create([
                'name' => 'Staff User',
                'email' => 'staff@nusaevo.com',
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
        });
        TenantUserLookup::query()->updateOrCreate(
            ['email' => 'staff@nusaevo.com', 'tenant_id' => '001'],
            [],
        );

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $userId = null;
        $tenant->run(function () use (&$userId) {
            $userId = User::query()->where('email', 'staff@nusaevo.com')->value('id');
        });

        $response = $this->patch("/config/users/{$userId}/reset-password");
        $response->assertRedirect(route('config.users.index'));
        $newPassword = $response->getSession()->get('generated_credentials')['password'];
        $this->assertNotEmpty($newPassword);
        $this->post('/logout');

        $this->post('/login', [
            'email' => 'staff@nusaevo.com',
            'password' => 'password',
        ])->assertSessionHasErrors('password');

        $this->post('/login', [
            'email' => 'staff@nusaevo.com',
            'password' => $newPassword,
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_admin_provisioned_user_must_change_password_before_using_the_app(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $response = $this->post('/config/users', [
            'name' => 'New Staff',
            'email' => 'newstaff@nusaevo.com',
        ]);
        $tempPassword = $response->getSession()->get('generated_credentials')['password'];
        $this->post('/logout');

        $this->post('/login', [
            'email' => 'newstaff@nusaevo.com',
            'password' => $tempPassword,
        ]);

        // Locked out of the rest of the app until the password is changed.
        $this->get('/dashboard')->assertRedirect(route('profile.edit'));

        // The change-password page itself stays reachable.
        $this->get('/profile')->assertOk();

        $this->put('/password', [
            'current_password' => $tempPassword,
            'password' => 'a-new-permanent-password',
            'password_confirmation' => 'a-new-permanent-password',
        ])->assertSessionDoesntHaveErrors();

        $tenant->run(function () {
            $user = User::query()->where('email', 'newstaff@nusaevo.com')->first();
            $this->assertFalse($user->must_change_password);
        });

        // No longer blocked.
        $this->get('/dashboard')->assertOk();
    }
}
