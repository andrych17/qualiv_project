<?php

namespace App\Modules\WNE\Services;

use App\Modules\SysConfig\Services\ConfigService;
use App\Modules\WNE\Models\MsgCategory;
use App\Modules\WNE\Models\MsgUserPreference;
use App\Modules\WNE\Models\MsgUserQuietHours;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * §3J — per-user channel/opt-out preferences and per-(user, channel) quiet hours.
 * MessagingService is the only other caller: it resolves channels and a quiet-hours delay
 * per recipient right before creating that recipient's deliveries. Nothing here talks to a
 * queue or a delivery row directly — this is pure resolution logic.
 */
class PreferenceService
{
    public function __construct(private readonly ConfigService $config) {}

    /**
     * @param  ?list<string>  $channels  null = no explicit choice, category default_channels apply
     */
    public function setPreference(int $userId, string $categoryCode, ?array $channels, bool $optedOut): MsgUserPreference
    {
        $category = MsgCategory::query()->where('code', $categoryCode)->firstOrFail();

        if ($category->is_mandatory) {
            if ($optedOut) {
                throw ValidationException::withMessages(['opted_out' => "Category '{$categoryCode}' is mandatory and cannot be opted out of."]);
            }

            if ($channels === []) {
                throw ValidationException::withMessages(['channels' => "Category '{$categoryCode}' is mandatory and needs at least one channel."]);
            }
        }

        return MsgUserPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'category_id' => $category->id],
            ['channels' => $channels, 'opted_out' => $optedOut],
        );
    }

    /** Null start/end clears quiet hours for that channel rather than storing a meaningless window. */
    public function setQuietHours(int $userId, string $channel, ?string $startTime, ?string $endTime): void
    {
        if ($startTime === null || $endTime === null) {
            MsgUserQuietHours::query()->where('user_id', $userId)->where('channel', $channel)->delete();

            return;
        }

        MsgUserQuietHours::query()->updateOrCreate(
            ['user_id' => $userId, 'channel' => $channel],
            ['start_time' => $startTime, 'end_time' => $endTime],
        );
    }

    /**
     * @param  list<string>  $categoryDefaultChannels
     * @return list<string> empty = the recipient should get nothing on this notify() call (opted out)
     */
    public function resolveChannelsFor(int $userId, string $categoryCode, array $categoryDefaultChannels): array
    {
        $category = MsgCategory::query()->where('code', $categoryCode)->first();

        if (! $category) {
            return $categoryDefaultChannels;
        }

        $preference = MsgUserPreference::query()
            ->where('user_id', $userId)
            ->where('category_id', $category->id)
            ->first();

        if (! $preference) {
            return $categoryDefaultChannels;
        }

        if ($preference->opted_out) {
            return [];
        }

        return $preference->channels ?? $categoryDefaultChannels;
    }

    /**
     * When to release a notification generated right now, or null to send immediately.
     * A category's `is_urgent` flag bypasses quiet hours entirely regardless of what the
     * user configured — matches §3F's SLA escalations, which must never wait for morning.
     */
    public function quietHoursDelayFor(int $userId, string $channel, string $categoryCode): ?Carbon
    {
        if (MsgCategory::query()->where('code', $categoryCode)->where('is_urgent', true)->exists()) {
            return null;
        }

        $quietHours = MsgUserQuietHours::query()->where('user_id', $userId)->where('channel', $channel)->first();

        if (! $quietHours) {
            return null;
        }

        return $this->windowEndIfActive(Carbon::now($this->tenantTimezone()), $quietHours->start_time, $quietHours->end_time);
    }

    /**
     * Returns the instant the window ends if `$now` falls inside [start, end) — handling an
     * overnight window (start > end, e.g. 22:00–06:00) by comparing against both "today's"
     * and "yesterday's" occurrence of the window. Same-day windows (start <= end) are the
     * simple case.
     */
    private function windowEndIfActive(Carbon $now, string $startTime, string $endTime): ?Carbon
    {
        $start = $now->copy()->setTimeFromTimeString($startTime);
        $end = $now->copy()->setTimeFromTimeString($endTime);

        if ($start->lessThanOrEqualTo($end)) {
            return $now->between($start, $end) ? $end : null;
        }

        // Overnight window. `$now` is inside it either between start and midnight (window
        // ends tomorrow) or between midnight and end (window ends today).
        if ($now->greaterThanOrEqualTo($start)) {
            return $end->copy()->addDay();
        }

        if ($now->lessThan($end)) {
            return $end;
        }

        return null;
    }

    /** Rung 1 of the customization ladder (CLAUDE.md §2) — no seeded default; an unset const falls back to the app timezone. */
    public function tenantTimezone(): string
    {
        return $this->config->get('GENERAL', 'TIMEZONE') ?? config('app.timezone', 'UTC');
    }
}
