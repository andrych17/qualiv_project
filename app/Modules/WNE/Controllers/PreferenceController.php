<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Models\MsgCategory;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgUserPreference;
use App\Modules\WNE\Models\MsgUserQuietHours;
use App\Modules\WNE\Requests\UpdatePreferencesRequest;
use App\Modules\WNE\Services\PreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** §3J — self-service screen: the logged-in user's own channel/opt-out preferences and quiet hours. */
class PreferenceController extends Controller
{
    private const CHANNELS = [MsgChannelConfig::CHANNEL_EMAIL, MsgChannelConfig::CHANNEL_SMS, MsgChannelConfig::CHANNEL_PUSH, MsgChannelConfig::CHANNEL_IN_APP];

    public function __construct(private readonly PreferenceService $preferences) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $existingPreferences = MsgUserPreference::query()->where('user_id', $userId)->get()->keyBy('category_id');
        $existingQuietHours = MsgUserQuietHours::query()->where('user_id', $userId)->get()->keyBy('channel');

        $categories = MsgCategory::query()->orderBy('name')->get()->map(function (MsgCategory $category) use ($existingPreferences) {
            $preference = $existingPreferences->get($category->id);

            return [
                'code' => $category->code,
                'name' => $category->name,
                'is_mandatory' => $category->is_mandatory,
                'is_urgent' => $category->is_urgent,
                'default_channels' => $category->default_channels ?? [MsgChannelConfig::CHANNEL_IN_APP],
                'channels' => $preference->channels ?? $category->default_channels ?? [MsgChannelConfig::CHANNEL_IN_APP],
                'opted_out' => $preference->opted_out ?? false,
            ];
        });

        $quietHours = collect(self::CHANNELS)->map(fn (string $channel) => [
            'channel' => $channel,
            'start_time' => $existingQuietHours->get($channel)?->start_time,
            'end_time' => $existingQuietHours->get($channel)?->end_time,
        ]);

        return Inertia::render('WNE/Preferences/Index', [
            'categories' => $categories,
            'quietHours' => $quietHours,
            'channels' => self::CHANNELS,
        ]);
    }

    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $userId = $request->user()->id;

        try {
            DB::transaction(function () use ($request, $userId) {
                foreach ($request->validated('preferences', []) as $row) {
                    $this->preferences->setPreference($userId, $row['category_code'], $row['channels'] ?? null, $row['opted_out'] ?? false);
                }

                foreach ($request->validated('quiet_hours', []) as $row) {
                    $this->preferences->setQuietHours($userId, $row['channel'], $row['start_time'] ?? null, $row['end_time'] ?? null);
                }
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Preferences saved.');
    }
}
