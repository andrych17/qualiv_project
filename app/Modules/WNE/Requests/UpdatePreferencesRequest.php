<?php

namespace App\Modules\WNE\Requests;

use App\Modules\WNE\Models\MsgChannelConfig;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $channels = [MsgChannelConfig::CHANNEL_EMAIL, MsgChannelConfig::CHANNEL_SMS, MsgChannelConfig::CHANNEL_PUSH, MsgChannelConfig::CHANNEL_IN_APP];

        return [
            'preferences' => 'array',
            'preferences.*.category_code' => 'required|string',
            'preferences.*.channels' => 'nullable|array',
            'preferences.*.channels.*' => 'string|in:'.implode(',', $channels),
            'preferences.*.opted_out' => 'boolean',

            'quiet_hours' => 'array',
            'quiet_hours.*.channel' => 'required|string|in:'.implode(',', $channels),
            'quiet_hours.*.start_time' => 'nullable|date_format:H:i',
            'quiet_hours.*.end_time' => 'nullable|date_format:H:i',
        ];
    }
}
