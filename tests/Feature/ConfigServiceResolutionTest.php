<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigAuditLog;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ConfigServiceResolutionTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_get_resolves_user_then_group_then_module_then_platform(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $groupId = (int) ConfigGroup::query()->where('code', 'ADMIN')->value('id');
            $userId = (int) User::query()->where('email', 'admin@nusaevo.com')->value('id');

            ConfigConst::query()->create([
                'const_group' => 'LEGAL',
                'group_code' => 'SIGNING_REMINDER_DAYS',
                'value' => '9',
                'value_type' => 'number',
                'seq' => 1,
                'is_active' => true,
            ]);
            ConfigConst::query()->create([
                'appl_id' => 'LEGAL',
                'const_group' => 'LEGAL',
                'group_code' => 'SIGNING_REMINDER_DAYS',
                'value' => '7',
                'value_type' => 'number',
                'seq' => 1,
                'is_active' => true,
            ]);
            ConfigConst::query()->create([
                'appl_id' => 'LEGAL',
                'group_id' => $groupId,
                'const_group' => 'LEGAL',
                'group_code' => 'SIGNING_REMINDER_DAYS',
                'value' => '3',
                'value_type' => 'number',
                'seq' => 1,
                'is_active' => true,
            ]);
            ConfigConst::query()->create([
                'appl_id' => 'LEGAL',
                'user_id' => $userId,
                'const_group' => 'LEGAL',
                'group_code' => 'SIGNING_REMINDER_DAYS',
                'value' => '1',
                'value_type' => 'number',
                'seq' => 1,
                'is_active' => true,
            ]);

            $svc = app(ConfigService::class);

            $this->assertSame(1, $svc->get('LEGAL', 'SIGNING_REMINDER_DAYS', 'LEGAL', $groupId, $userId));
            $this->assertSame(3, $svc->get('LEGAL', 'SIGNING_REMINDER_DAYS', 'LEGAL', $groupId, null));
            $this->assertSame(7, $svc->get('LEGAL', 'SIGNING_REMINDER_DAYS', 'LEGAL'));
            $this->assertSame(9, $svc->get('LEGAL', 'SIGNING_REMINDER_DAYS'));
        });
    }

    public function test_set_writes_value_and_audit_log(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $svc = app(ConfigService::class);
            $row = $svc->set('LEGAL', 'CASE_PREFIX', 'A', 'LEGAL', null, null, 'text');

            $this->assertSame('A', $svc->get('LEGAL', 'CASE_PREFIX', 'LEGAL'));
            $this->assertSame('A', $row->value);
            $this->assertTrue(
                ConfigAuditLog::query()
                    ->where('table_name', 'config_consts')
                    ->where('record_id', $row->id)
                    ->where('action', 'created')
                    ->exists()
            );
        });
    }

    public function test_get_group_returns_enum_members_by_seq(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $members = app(ConfigService::class)->getGroup('STATUS');
            $this->assertGreaterThanOrEqual(2, $members->count());
            $this->assertSame('ACTIVE', $members->first()->group_code);
        });
    }
}
