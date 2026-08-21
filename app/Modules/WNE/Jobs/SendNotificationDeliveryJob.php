<?php

namespace App\Modules\WNE\Jobs;

use App\Modules\WNE\Data\NotificationMessage;
use App\Modules\WNE\Models\MsgDeliveryEvent;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Models\MsgTemplate;
use App\Modules\WNE\Services\MessagingService;
use App\Modules\WNE\Services\RetryService;
use App\Modules\WNE\Services\TemplateRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §3I's one hard rule: "the engine never calls a provider synchronously from the
 * triggering request" — this job is that boundary, regardless of what triggered the send
 * (a direct MessagingService::notify() call or the NotificationRequested listener).
 * `QUEUE_CONNECTION=sync` in this dev environment still runs it through the real
 * queue/afterCommit machinery, just inline — the code path is the same one that runs
 * async in production against a real queue connection.
 */
class SendNotificationDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Framework-level retry only — driver->send() itself never throws (every driver catches
     * its own provider errors and returns a failed DeliveryResult, handled below without a
     * retry). These few attempts exist for the narrow case where handle() itself blows up
     * before reaching a driver (a DB blip, an unknown channel). §3M owns the notification
     * engine's own business-facing retry policy/DLQ on top of this — this is not that.
     */
    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = MsgNotificationDelivery::query()->with('notification')->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        // §3M: guards against a stale delayed retry job or a resend race running against a
        // delivery some other path already resolved — e.g. an admin resent it and the fresh
        // attempt already completed before a leftover job got here.
        if (in_array($delivery->status, MsgNotificationDelivery::TERMINAL_STATUSES, true)) {
            return;
        }

        $driver = app(MessagingService::driverFor($delivery->channel));
        [$subject, $body] = $this->resolveContent($delivery);

        $message = new NotificationMessage(
            deliveryId: $delivery->id,
            recipientUserId: $delivery->notification->recipient_user_id,
            subject: $subject,
            body: $body,
            data: $delivery->notification->data ?? [],
            subjectType: $delivery->notification->subject_type,
            subjectId: $delivery->notification->subject_id,
        );

        MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_SENDING);

        $result = $driver->send($message);

        if ($result->success) {
            $delivery->update(['status' => MsgNotificationDelivery::STATUS_SENT, 'provider_message_id' => $result->providerMessageId, 'sent_at' => now()]);
            $delivery->notification->recomputeStatus();
            MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_SENT, $result->providerMessageId !== null ? ['provider_message_id' => $result->providerMessageId] : []);

            return;
        }

        // §3M: business-level retry on a *result*, not an exception — driver->send() never
        // throws, so this is the only place a provider failure is handled. RetryService
        // decides pending-failure vs. retry-and-reschedule vs. dead-letter.
        app(RetryService::class)->handleFailure($delivery, $result->error);
    }

    /**
     * All `$tries` framework-level attempts are exhausted — leave the delivery in a terminal,
     * visible `failed` state instead of stuck open forever (whether it was still `pending` on
     * its first attempt, or `retrying` because a re-dispatched §3M retry job itself blew up
     * before reaching a driver, e.g. a DB blip). This is terminal-with-visibility, not §3M's
     * dead letter queue proper: no msg_dead_letters row, no per-channel policy from
     * msg_channel_configs — just "don't let the last word be silence." Only writes when the
     * delivery isn't already terminal — if a driver already recorded its own failure reason
     * in handle() (the overwhelmingly common case, since drivers don't throw), that message
     * is kept rather than overwritten with the framework's own retry-exhaustion exception.
     */
    public function failed(\Throwable $exception): void
    {
        $delivery = MsgNotificationDelivery::query()->with('notification')->find($this->deliveryId);

        if (! $delivery || in_array($delivery->status, MsgNotificationDelivery::TERMINAL_STATUSES, true)) {
            return;
        }

        $delivery->update(['status' => MsgNotificationDelivery::STATUS_FAILED, 'error' => $exception->getMessage()]);
        $delivery->notification->recomputeStatus();
        MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_FAILED, ['error' => $exception->getMessage()]);
    }

    /**
     * §3L: an active (category, channel, locale) template renders per delivery — never
     * persisted back onto the shared header, since a different channel on the same
     * notification can render completely different content. No matching template (or none
     * active) falls back to the header's own literal subject/body, preserving §3I's
     * pre-template behavior exactly.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveContent(MsgNotificationDelivery $delivery): array
    {
        $template = MsgTemplate::resolveFor($delivery->notification->category_code, $delivery->channel);

        if (! $template) {
            return [$delivery->notification->subject, $delivery->notification->body];
        }

        $renderer = app(TemplateRenderingService::class);
        $data = $delivery->notification->data ?? [];

        return [
            $template->subject ? $renderer->render($template->subject, $data) : $delivery->notification->subject,
            $renderer->render($template->body, $data),
        ];
    }
}
