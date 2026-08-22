<?php

namespace App\Modules\Schedule\Data;

/** §3G — the result of a ConferenceDriverInterface::createMeeting() call. */
class ConferenceMeeting
{
    public function __construct(
        public readonly string $joinUrl,
        public readonly ?string $externalMeetingId = null,
        public readonly ?string $dialInInfo = null,
    ) {}
}
