<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\ResourceType;

/** Shared bootstrap for Schedule module tests — plan activation, admin login, and master-data fixtures. */
trait SetsUpSchedule
{
    protected function loginAsScheduleAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makeResourceType(string $code = 'ROOM', string $name = 'Room'): ResourceType
    {
        return ResourceType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
    }

    protected function makeResource(?ResourceType $type = null, string $name = 'Conference Room A'): Resource
    {
        $type ??= $this->makeResourceType();

        return Resource::query()->create([
            'resource_type_id' => $type->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function makeConferenceProviders(): void
    {
        ConferenceProvider::query()->firstOrCreate(
            ['code' => ConferenceProvider::CODE_MANUAL],
            ['name' => 'Manual Link', 'is_active' => true],
        );
        ConferenceProvider::query()->firstOrCreate(
            ['code' => ConferenceProvider::CODE_ZOOM],
            [
                'name' => 'Zoom',
                'is_active' => true,
                'credentials' => ['account_id' => 'acc', 'client_id' => 'cid', 'client_secret' => 'secret'],
            ],
        );
    }
}
