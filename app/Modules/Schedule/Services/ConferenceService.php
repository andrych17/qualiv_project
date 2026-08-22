<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Contracts\ConferenceDriverInterface;
use App\Modules\Schedule\Drivers\ManualLinkDriver;
use App\Modules\Schedule\Drivers\ZoomDriver;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;
use Illuminate\Validation\ValidationException;

/** §3G — attach/detach a join link to an Event without Schedule hardcoding a vendor. */
class ConferenceService
{
    /** @var array<string, class-string<ConferenceDriverInterface>> */
    private const DRIVER_MAP = [
        ConferenceProvider::CODE_MANUAL => ManualLinkDriver::class,
        ConferenceProvider::CODE_ZOOM => ZoomDriver::class,
    ];

    public function attach(SchedItem $event, string $providerCode, ?string $manualUrl = null): SchedConferenceLink
    {
        $provider = ConferenceProvider::query()->where('code', $providerCode)->where('is_active', true)->first();

        if (! $provider) {
            throw ValidationException::withMessages(['conference_provider_code' => 'That conference provider is not available.']);
        }

        $meeting = $this->driverFor($provider->code)->createMeeting($event, $provider, $manualUrl);

        return SchedConferenceLink::query()->updateOrCreate(
            ['sched_item_id' => $event->id],
            [
                'conference_provider_id' => $provider->id,
                'join_url' => $meeting->joinUrl,
                'external_meeting_id' => $meeting->externalMeetingId,
                'dial_in_info' => $meeting->dialInInfo,
            ],
        );
    }

    public function detach(SchedItem $event): void
    {
        $link = $event->conferenceLink;

        if (! $link) {
            return;
        }

        $this->driverFor($link->conferenceProvider->code)->cancelMeeting($link, $link->conferenceProvider);
        $link->delete();
    }

    private function driverFor(string $code): ConferenceDriverInterface
    {
        $class = self::DRIVER_MAP[$code] ?? throw new \InvalidArgumentException("No driver registered for conference provider '{$code}'.");

        return app($class);
    }
}
