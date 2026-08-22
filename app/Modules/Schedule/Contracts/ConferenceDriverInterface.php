<?php

namespace App\Modules\Schedule\Contracts;

use App\Modules\Schedule\Data\ConferenceMeeting;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;

/**
 * §3G: a new provider is a new class registered in ConferenceService's driver map, never a
 * core engine change — same additive-driver pattern as WNE's ChannelDriverInterface.
 */
interface ConferenceDriverInterface
{
    public function createMeeting(SchedItem $event, ConferenceProvider $provider, ?string $manualUrl = null): ConferenceMeeting;

    public function cancelMeeting(SchedConferenceLink $link, ConferenceProvider $provider): void;
}
