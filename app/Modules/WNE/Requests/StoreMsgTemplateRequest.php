<?php

namespace App\Modules\WNE\Requests;

use App\Modules\WNE\Models\MsgChannelConfig;
use Illuminate\Foundation\Http\FormRequest;

class StoreMsgTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_code' => 'required|string|max:100',
            'channel' => 'required|string|in:'.implode(',', [
                MsgChannelConfig::CHANNEL_EMAIL,
                MsgChannelConfig::CHANNEL_SMS,
                MsgChannelConfig::CHANNEL_PUSH,
                MsgChannelConfig::CHANNEL_IN_APP,
            ]),
            'locale' => 'nullable|string|max:10',
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string',
            'variables' => 'nullable|array',
            'variables.*' => 'string',
        ];
    }
}
