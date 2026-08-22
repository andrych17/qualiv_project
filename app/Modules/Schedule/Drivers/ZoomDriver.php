<?php

namespace App\Modules\Schedule\Drivers;

use App\Modules\Schedule\Contracts\ConferenceDriverInterface;
use App\Modules\Schedule\Data\ConferenceMeeting;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * §3G real provider driver — picked over Google Meet per the spec's own note
 * ("whichever has the simpler OAuth setup"): Zoom's Server-to-Server OAuth is
 * a single client-credentials exchange, no per-user consent flow.
 *
 * Credentials live on ConferenceProvider.credentials (encrypted, per-tenant —
 * same "credentials-gated" pattern as WNE.msg_channel_configs), not
 * config/services.php — there is no admin screen to enter them yet (same gap
 * WNE's Twilio/SendGrid config has); set directly on the row until one exists.
 */
class ZoomDriver implements ConferenceDriverInterface
{
    public function createMeeting(SchedItem $event, ConferenceProvider $provider, ?string $manualUrl = null): ConferenceMeeting
    {
        $token = $this->getAccessToken($provider);

        $response = Http::withToken($token)
            ->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => $event->title,
                'type' => 2, // scheduled meeting
                'start_time' => $event->start_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration' => max(1, (int) $event->start_at->diffInMinutes($event->end_at)),
                'timezone' => 'UTC',
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'conference_provider_code' => 'Zoom error: '.($response->json('message') ?? $response->status()),
            ]);
        }

        return new ConferenceMeeting(
            joinUrl: $response->json('join_url'),
            externalMeetingId: (string) $response->json('id'),
            dialInInfo: $response->json('settings.global_dial_in_numbers.0.number') ?? null,
        );
    }

    public function cancelMeeting(SchedConferenceLink $link, ConferenceProvider $provider): void
    {
        if (! $link->external_meeting_id) {
            return;
        }

        try {
            $token = $this->getAccessToken($provider);
            Http::withToken($token)->delete("https://api.zoom.us/v2/meetings/{$link->external_meeting_id}");
        } catch (\Throwable) {
            // Best-effort — an external outage must not block the local cancellation/delete.
        }
    }

    private function getAccessToken(ConferenceProvider $provider): string
    {
        $credentials = $provider->credentials ?? [];
        $accountId = $credentials['account_id'] ?? null;
        $clientId = $credentials['client_id'] ?? null;
        $clientSecret = $credentials['client_secret'] ?? null;

        if (! $accountId || ! $clientId || ! $clientSecret) {
            throw ValidationException::withMessages([
                'conference_provider_code' => 'Zoom is not configured for this tenant yet — set account_id/client_id/client_secret on the Zoom conference provider.',
            ]);
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => $accountId,
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'conference_provider_code' => 'Zoom authentication failed: '.($response->json('reason') ?? $response->status()),
            ]);
        }

        return $response->json('access_token');
    }
}
