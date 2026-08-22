<?php

namespace App\Modules\Schedule\Drivers;

use App\Modules\Schedule\Contracts\ConferenceDriverInterface;
use App\Modules\Schedule\Data\ConferenceMeeting;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;
use Illuminate\Validation\ValidationException;

/** §3G: zero integration cost — the user pastes any URL, nothing is called out. */
class ManualLinkDriver implements ConferenceDriverInterface
{
    public function createMeeting(SchedItem $event, ConferenceProvider $provider, ?string $manualUrl = null): ConferenceMeeting
    {
        if (! $manualUrl) {
            throw ValidationException::withMessages(['conference_manual_url' => 'Paste a join URL for a manual conference link.']);
        }

        return new ConferenceMeeting(joinUrl: $manualUrl);
    }

    public function cancelMeeting(SchedConferenceLink $link, ConferenceProvider $provider): void
    {
        // Nothing external to cancel — the link is just a pasted URL.
    }
}
