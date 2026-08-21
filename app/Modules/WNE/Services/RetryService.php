<?php

namespace App\Modules\WNE\Services;

use App\Modules\WNE\Jobs\SendNotificationDeliveryJob;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgDeadLetter;
use App\Modules\WNE\Models\MsgDeliveryEvent;
use App\Modules\WNE\Models\MsgNotificationDelivery;

/**
 * §3M — business-level retry on a *failed DeliveryResult*, distinct from §3K's job-level
 * `$tries`/`$backoff` (which only ever fires for an exception before a driver is reached —
 * every driver catches its own provider errors and returns a failure result, never throws).
 *
 * Retry is opt-in per channel: a channel with no `msg_channel_configs` row, or one with
 * `max_attempts <= 1`, fails immediately exactly as it did before this service existed — no
 * retry, no dead letter. Retrying "channel not configured" or a driver that unconditionally
 * fails (e.g. PushDriver's "not yet implemented") cannot succeed no matter how many attempts
 * are spent on it, so nothing is retried unless a channel explicitly declares a multi-attempt
 * policy. This is also the one retry/backoff implementation the module has — §3G's outbound
 * webhook engine is meant to reuse it, not build its own, whenever it exists.
 */
class RetryService
{
    /** 1 min → 5 min → 30 min → 2 hr — the spec's own example, used whenever a channel opts into retry without specifying its own schedule. */
    private const DEFAULT_BACKOFF_SECONDS = [60, 300, 1800, 7200];

    public function handleFailure(MsgNotificationDelivery $delivery, string $error): void
    {
        $policy = MsgChannelConfig::forChannel($delivery->channel);
        $maxAttempts = $policy?->max_attempts ?? 1;

        if ($maxAttempts <= 1) {
            $delivery->update(['status' => MsgNotificationDelivery::STATUS_FAILED, 'error' => $error]);
            $delivery->notification->recomputeStatus();
            MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_FAILED, ['error' => $error]);

            return;
        }

        $history = $delivery->retry_history;
        $history[] = ['attempt' => $delivery->attempt, 'error' => $error, 'occurred_at' => now()->toIso8601String()];

        if ($delivery->attempt >= $maxAttempts) {
            $this->deadLetter($delivery, $error, $history);

            return;
        }

        $delay = $this->backoffSeconds($delivery->attempt, $policy->backoff_schedule);

        $delivery->update([
            'status' => MsgNotificationDelivery::STATUS_RETRYING,
            'error' => $error,
            'retry_history' => $history,
            'attempt' => $delivery->attempt + 1,
        ]);
        MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_RETRYING, ['error' => $error, 'next_attempt' => $delivery->attempt]);

        SendNotificationDeliveryJob::dispatch($delivery->id)->onQueue('notifications')->delay(now()->addSeconds($delay))->afterCommit();
    }

    /** Public so the schedule math (configured vs. exponential default) is directly testable without inspecting a faked job's internals. @param  ?list<int>  $schedule */
    public function backoffSeconds(int $attempt, ?array $schedule): int
    {
        $schedule ??= self::DEFAULT_BACKOFF_SECONDS;

        return $schedule[$attempt - 1] ?? end($schedule);
    }

    /** @param  list<array{attempt: int, error: string, occurred_at: string}>  $history */
    private function deadLetter(MsgNotificationDelivery $delivery, string $error, array $history): void
    {
        MsgDeadLetter::query()->create([
            'delivery_id' => $delivery->id,
            'notification_id' => $delivery->notification_id,
            'channel' => $delivery->channel,
            'recipient_user_id' => $delivery->notification->recipient_user_id,
            'subject' => $delivery->notification->subject,
            'body' => $delivery->notification->body,
            'data' => $delivery->notification->data,
            'failure_history' => $history,
            'created_at' => now(),
        ]);

        $delivery->update(['status' => MsgNotificationDelivery::STATUS_DEAD_LETTERED, 'error' => $error, 'retry_history' => $history]);
        $delivery->notification->recomputeStatus();
        MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_DEAD_LETTERED, ['error' => $error]);
    }

    /** §3M: "resend (re-queues with a fresh attempt counter)." A no-op past the first resend/discard — guards against a double click dispatching two live jobs for the same delivery. */
    public function resend(MsgDeadLetter $deadLetter, int $actorId): void
    {
        if ($deadLetter->isActioned()) {
            return;
        }

        $deadLetter->update(['resent_at' => now(), 'resent_by' => $actorId]);

        $delivery = $deadLetter->delivery;
        $delivery->update(['status' => MsgNotificationDelivery::STATUS_PENDING, 'attempt' => 1, 'error' => null]);

        SendNotificationDeliveryJob::dispatch($delivery->id)->onQueue('notifications')->afterCommit();
        MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_QUEUED, ['resent_by' => $actorId]);
    }

    /** §3M: "discard (explicit, logged action — never a silent drop)." The delivery itself stays dead_lettered; only the DLQ entry is marked acknowledged. */
    public function discard(MsgDeadLetter $deadLetter, int $actorId): void
    {
        if ($deadLetter->isActioned()) {
            return;
        }

        $deadLetter->update(['discarded_at' => now(), 'discarded_by' => $actorId]);
    }
}
