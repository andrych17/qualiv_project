<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Drivers\ZoomDriver;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Services\ConferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Service/driver-level coverage for §3G that isn't reachable through the HTTP layer alone (FormRequest already blocks some of these before the service is ever called). */
class ScheduleConferenceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    private function makeEvent(): SchedItem
    {
        return SchedItem::query()->create([
            'type' => SchedItem::TYPE_EVENT, 'title' => 'Service-level event',
            'owner_id' => User::query()->first()->id, 'status' => 'scheduled',
            'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
        ]);
    }

    public function test_attach_manual_link_without_a_url_throws(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $this->makeConferenceProviders();
            $event = $this->makeEvent();

            $this->expectException(ValidationException::class);
            app(ConferenceService::class)->attach($event, ConferenceProvider::CODE_MANUAL, null);
        });
    }

    public function test_attach_rejects_unknown_or_inactive_provider_code(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $event = $this->makeEvent();

            try {
                app(ConferenceService::class)->attach($event, 'does_not_exist');
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('conference_provider_code', $e->errors());
            }
        });
    }

    public function test_attach_throws_for_a_provider_with_no_registered_driver(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            ConferenceProvider::query()->create(['code' => 'google_meet', 'name' => 'Google Meet', 'is_active' => true]);
            $event = $this->makeEvent();

            $this->expectException(\InvalidArgumentException::class);
            app(ConferenceService::class)->attach($event, 'google_meet');
        });
    }

    public function test_detach_is_a_no_op_when_the_event_has_no_conference_link(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $event = $this->makeEvent();

            app(ConferenceService::class)->detach($event);

            $this->assertNull(SchedConferenceLink::query()->where('sched_item_id', $event->id)->first());
        });
    }

    public function test_zoom_driver_requires_credentials_configured_on_the_provider(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $provider = ConferenceProvider::query()->create(['code' => ConferenceProvider::CODE_ZOOM, 'name' => 'Zoom', 'is_active' => true]);
            $event = $this->makeEvent();

            $this->expectException(ValidationException::class);
            app(ZoomDriver::class)->createMeeting($event, $provider);
        });
    }

    public function test_zoom_driver_surfaces_an_authentication_failure(): void
    {
        Http::fake(['zoom.us/oauth/token' => Http::response(['reason' => 'invalid_client'], 401)]);

        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $provider = ConferenceProvider::query()->create([
                'code' => ConferenceProvider::CODE_ZOOM, 'name' => 'Zoom', 'is_active' => true,
                'credentials' => ['account_id' => 'a', 'client_id' => 'b', 'client_secret' => 'c'],
            ]);
            $event = $this->makeEvent();

            $this->expectException(ValidationException::class);
            app(ZoomDriver::class)->createMeeting($event, $provider);
        });
    }

    public function test_zoom_driver_surfaces_a_meeting_creation_failure(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok'], 200),
            'api.zoom.us/v2/users/me/meetings' => Http::response(['message' => 'quota exceeded'], 429),
        ]);

        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $provider = ConferenceProvider::query()->create([
                'code' => ConferenceProvider::CODE_ZOOM, 'name' => 'Zoom', 'is_active' => true,
                'credentials' => ['account_id' => 'a', 'client_id' => 'b', 'client_secret' => 'c'],
            ]);
            $event = $this->makeEvent();

            $this->expectException(ValidationException::class);
            app(ZoomDriver::class)->createMeeting($event, $provider);
        });
    }

    public function test_zoom_driver_cancel_is_a_no_op_without_an_external_meeting_id(): void
    {
        Http::fake();

        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $provider = ConferenceProvider::query()->create(['code' => ConferenceProvider::CODE_ZOOM, 'name' => 'Zoom', 'is_active' => true]);
            $event = $this->makeEvent();
            $link = SchedConferenceLink::query()->create([
                'sched_item_id' => $event->id, 'conference_provider_id' => $provider->id,
                'join_url' => 'https://zoom.us/j/1', 'external_meeting_id' => null,
            ]);

            app(ZoomDriver::class)->cancelMeeting($link, $provider);

            Http::assertNothingSent();
        });
    }

    public function test_zoom_driver_cancel_swallows_a_failure_instead_of_throwing(): void
    {
        Http::fake();

        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            // No credentials configured -> getAccessToken() throws inside the try block,
            // which cancelMeeting() must swallow (best-effort: an external outage must not
            // block the local cancellation/delete).
            $provider = ConferenceProvider::query()->create(['code' => ConferenceProvider::CODE_ZOOM, 'name' => 'Zoom', 'is_active' => true]);
            $event = $this->makeEvent();
            $link = SchedConferenceLink::query()->create([
                'sched_item_id' => $event->id, 'conference_provider_id' => $provider->id,
                'join_url' => 'https://zoom.us/j/1', 'external_meeting_id' => '555',
            ]);

            app(ZoomDriver::class)->cancelMeeting($link, $provider);

            $this->assertTrue(true); // reaching this line means no exception propagated
        });
    }
}
