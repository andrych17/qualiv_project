<?php

namespace App\Modules\WNE\Drivers;

use App\Modules\WNE\Contracts\ChannelDriverInterface;
use App\Modules\WNE\Data\DeliveryResult;
use App\Modules\WNE\Data\NotificationMessage;
use App\Modules\WNE\Models\WrkflowStep;
use Illuminate\Support\Facades\Http;

/**
 * §3G outbound `webhook_call` — the fifth channel (WNE_SPECS.md §4's `channel_types` already
 * listed `webhook`), so it rides §3I/§3M's entire pipeline unchanged: attempt tracking,
 * retry/backoff, and dead-lettering are RetryService's, not reimplemented here.
 *
 * URL/method live on the *step definition* (`wrkflow_steps`, via `$message->subjectType`/
 * `subjectId` — MessagingService::notifyWebhook() points these at the step, not the
 * instance, since every instance of the same workflow version calls the same URL). Auth
 * headers are the one webhook-specific secret, kept off the plain `config` JSON in an
 * encrypted column instead.
 */
class WebhookDriver implements ChannelDriverInterface
{
    public function send(NotificationMessage $message): DeliveryResult
    {
        if ($message->subjectType !== 'WNE.wrkflow_steps' || ! $message->subjectId) {
            return DeliveryResult::failure('Webhook delivery has no source step to read its URL/method from.');
        }

        $step = WrkflowStep::query()->find($message->subjectId);

        if (! $step) {
            return DeliveryResult::failure('The workflow step that configured this webhook no longer exists.');
        }

        $config = $step->config ?? [];
        $url = $config['url'] ?? null;

        if (! $url) {
            return DeliveryResult::failure('Webhook step has no URL configured.');
        }

        $method = strtoupper($config['method'] ?? 'POST');
        $headers = $step->webhook_auth_headers ?? [];

        $response = Http::withHeaders($headers)
            ->withBody($message->body, 'application/json')
            ->send($method, $url);

        if (! $response->successful()) {
            return DeliveryResult::failure("Webhook call failed: HTTP {$response->status()}.");
        }

        return DeliveryResult::success($response->header('X-Request-Id'));
    }
}
