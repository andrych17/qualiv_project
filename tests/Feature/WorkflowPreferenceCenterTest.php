<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Services\ConfigService;
use App\Modules\WNE\Jobs\SendNotificationDeliveryJob;
use App\Modules\WNE\Models\MsgCategory;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Models\MsgUserQuietHours;
use App\Modules\WNE\Services\MessagingService;
use App\Modules\WNE\Services\PreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3J — User Preference Center. Covers channel/opt-out resolution, the
 * mandatory-category guard, quiet-hours window math (same-day, overnight, urgent bypass,
 * tenant timezone), and the self-service HTTP flow.
 */
class WorkflowPreferenceCenterTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        // ConfigService caches per (tenant, const_group, version) — the array cache driver
        // persists for the life of this test *process*, not per test, so a const written by
        // one test can otherwise leak into the next even though the tenant DB itself is
        // recreated. Guarantee a cold cache for every test in this file.
        Cache::flush();
    }

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    public function test_a_user_with_no_preference_gets_the_categorys_default_channels(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            MsgCategory::query()->create(['code' => 'demo.pref', 'name' => 'Demo', 'default_channels' => ['email', 'in_app']]);

            $channels = app(PreferenceService::class)->resolveChannelsFor($this->adminId(), 'demo.pref', ['email', 'in_app']);

            $this->assertSame(['email', 'in_app'], $channels);
        });
    }

    public function test_an_explicit_channel_preference_overrides_the_category_default(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgCategory::query()->create(['code' => 'demo.pref', 'name' => 'Demo', 'default_channels' => ['email', 'in_app']]);

            app(PreferenceService::class)->setPreference($admin, 'demo.pref', ['sms'], false);

            $this->assertSame(['sms'], app(PreferenceService::class)->resolveChannelsFor($admin, 'demo.pref', ['email', 'in_app']));
        });
    }

    public function test_opting_out_of_a_non_mandatory_category_resolves_to_no_channels_and_notify_records_it(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgCategory::query()->create(['code' => 'demo.pref', 'name' => 'Demo', 'is_mandatory' => false, 'default_channels' => ['in_app']]);
            app(PreferenceService::class)->setPreference($admin, 'demo.pref', null, true);

            $header = app(MessagingService::class)->notify(
                category: 'demo.pref',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hi',
                body: 'Hi',
            )->first();

            $this->assertSame(MsgNotification::STATUS_FAILED, $header->status);
            $this->assertSame(0, MsgNotificationDelivery::query()->where('notification_id', $header->id)->count());
        });
    }

    public function test_opting_out_of_a_mandatory_category_is_rejected(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            MsgCategory::query()->create(['code' => 'demo.mandatory', 'name' => 'Demo', 'is_mandatory' => true]);

            $this->expectException(ValidationException::class);
            app(PreferenceService::class)->setPreference($this->adminId(), 'demo.mandatory', null, true);
        });
    }

    public function test_clearing_channels_to_empty_on_a_mandatory_category_is_rejected(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            MsgCategory::query()->create(['code' => 'demo.mandatory', 'name' => 'Demo', 'is_mandatory' => true]);

            $this->expectException(ValidationException::class);
            app(PreferenceService::class)->setPreference($this->adminId(), 'demo.mandatory', [], false);
        });
    }

    public function test_quiet_hours_same_day_window_defers_to_the_windows_end(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgCategory::query()->create(['code' => 'demo.pref', 'name' => 'Demo']);
            app(PreferenceService::class)->setQuietHours($admin, 'in_app', '09:00', '17:00');

            $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));
            $delay = app(PreferenceService::class)->quietHoursDelayFor($admin, 'in_app', 'demo.pref');

            $this->assertNotNull($delay);
            $this->assertTrue($delay->eq(Carbon::parse('2026-01-01 17:00:00', 'UTC')));
        });
    }

    public function test_outside_quiet_hours_a_notification_is_not_delayed(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgCategory::query()->create(['code' => 'demo.pref', 'name' => 'Demo']);
            app(PreferenceService::class)->setQuietHours($admin, 'in_app', '09:00', '17:00');

            $this->travelTo(Carbon::parse('2026-01-01 20:00:00', 'UTC'));

            $this->assertNull(app(PreferenceService::class)->quietHoursDelayFor($admin, 'in_app', 'demo.pref'));
        });
    }

    public function test_overnight_quiet_hours_window_defers_correctly_before_and_after_midnight(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            app(PreferenceService::class)->setQuietHours($admin, 'push', '22:00', '06:00');

            $this->travelTo(Carbon::parse('2026-01-01 23:00:00', 'UTC'));
            $beforeMidnight = app(PreferenceService::class)->quietHoursDelayFor($admin, 'push', 'demo.pref');
            $this->assertNotNull($beforeMidnight);
            $this->assertTrue($beforeMidnight->eq(Carbon::parse('2026-01-02 06:00:00', 'UTC')));

            $this->travelTo(Carbon::parse('2026-01-02 02:00:00', 'UTC'));
            $afterMidnight = app(PreferenceService::class)->quietHoursDelayFor($admin, 'push', 'demo.pref');
            $this->assertNotNull($afterMidnight);
            $this->assertTrue($afterMidnight->eq(Carbon::parse('2026-01-02 06:00:00', 'UTC')));

            $this->travelTo(Carbon::parse('2026-01-02 10:00:00', 'UTC'));
            $this->assertNull(app(PreferenceService::class)->quietHoursDelayFor($admin, 'push', 'demo.pref'));
        });
    }

    public function test_an_urgent_category_bypasses_quiet_hours_entirely(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgCategory::query()->create(['code' => 'demo.urgent', 'name' => 'Demo', 'is_urgent' => true]);
            app(PreferenceService::class)->setQuietHours($admin, 'in_app', '00:00', '23:59');

            $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

            $this->assertNull(app(PreferenceService::class)->quietHoursDelayFor($admin, 'in_app', 'demo.urgent'));
        });
    }

    public function test_the_seeded_sla_breach_category_is_urgent_so_escalations_never_wait_for_quiet_hours(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $this->assertTrue(MsgCategory::query()->where('code', 'wne.sla_breach')->value('is_urgent'));
        });
    }

    public function test_setting_quiet_hours_to_null_clears_an_existing_window(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            app(PreferenceService::class)->setQuietHours($admin, 'email', '09:00', '17:00');
            $this->assertSame(1, MsgUserQuietHours::query()->where('user_id', $admin)->count());

            app(PreferenceService::class)->setQuietHours($admin, 'email', null, null);

            $this->assertSame(0, MsgUserQuietHours::query()->where('user_id', $admin)->count());
        });
    }

    public function test_the_tenant_timezone_setting_shifts_the_quiet_hours_window(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            app(ConfigService::class)->set('GENERAL', 'TIMEZONE', 'Asia/Jakarta');
            app(PreferenceService::class)->setQuietHours($admin, 'in_app', '22:00', '06:00');

            // 2026-01-01 15:00 UTC = 2026-01-01 22:00 in Asia/Jakarta (UTC+7) — right at the
            // window's start in the *tenant's* clock, even though it's mid-afternoon UTC.
            $this->travelTo(Carbon::parse('2026-01-01 15:00:00', 'UTC'));

            $delay = app(PreferenceService::class)->quietHoursDelayFor($admin, 'in_app', 'demo.pref');

            $this->assertNotNull($delay);
            $this->assertTrue($delay->eq(Carbon::parse('2026-01-02 06:00:00', 'Asia/Jakarta')));
        });
    }

    public function test_notify_delays_the_delivery_job_until_quiet_hours_end(): void
    {
        Queue::fake();
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgCategory::query()->create(['code' => 'demo.pref', 'name' => 'Demo', 'default_channels' => ['in_app']]);
            app(PreferenceService::class)->setQuietHours($admin, 'in_app', '09:00', '17:00');

            $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

            app(MessagingService::class)->notify(
                category: 'demo.pref',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hi',
                body: 'Hi',
            );

            Queue::assertPushed(SendNotificationDeliveryJob::class, fn ($job) => $job->delay !== null
                && Carbon::instance($job->delay)->eq(Carbon::parse('2026-01-01 17:00:00', 'UTC')));
        });
    }

    public function test_http_preferences_index_renders_and_update_persists_choices(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);
        $admin = null;

        $tenant->run(function () use (&$admin) {
            $admin = $this->adminId();
            MsgCategory::query()->create(['code' => 'demo.http_pref', 'name' => 'Demo Http', 'default_channels' => ['in_app']]);
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/preferences')->assertOk();

        $this->post('/wne/preferences', [
            'preferences' => [['category_code' => 'demo.http_pref', 'channels' => ['email'], 'opted_out' => false]],
            'quiet_hours' => [['channel' => 'in_app', 'start_time' => '09:00', 'end_time' => '17:00']],
        ])->assertRedirect();

        $tenant->run(function () use ($admin) {
            $this->assertSame(['email'], app(PreferenceService::class)->resolveChannelsFor($admin, 'demo.http_pref', ['in_app']));
            $this->assertSame(1, MsgUserQuietHours::query()->where('user_id', $admin)->where('channel', 'in_app')->count());
        });
    }

    public function test_http_update_rejects_opting_out_of_a_mandatory_category(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            MsgCategory::query()->create(['code' => 'demo.http_mandatory', 'name' => 'Demo', 'is_mandatory' => true]);
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post('/wne/preferences', [
            'preferences' => [['category_code' => 'demo.http_mandatory', 'channels' => null, 'opted_out' => true]],
            'quiet_hours' => [],
        ])->assertSessionHasErrors('opted_out');
    }
}
