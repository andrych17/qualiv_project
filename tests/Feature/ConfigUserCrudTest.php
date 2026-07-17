<?php

namespace Tests\Feature;

use App\Models\TenantUserLookup;
use App\Models\User;
use App\Modules\Config\Models\ConfigGroup;
use App\Modules\Config\Models\ConfigGroupUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->post('/config/users', [
            'name' => 'New Staff',
            'email' => 'newstaff@nusaevo.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'group_ids' => [$groupId],
        ])->assertRedirect(route('config.users.index'));

        $tenant->run(function () use ($groupId) {
            $user = User::query()->where('email', 'newstaff@nusaevo.com')->first();
            $this->assertNotNull($user);
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
}
