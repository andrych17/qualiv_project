<?php

namespace App\Modules\WNE\Services;

use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\WNE\Contracts\ChannelDriverInterface;
use App\Modules\WNE\Drivers\EmailDriver;
use App\Modules\WNE\Drivers\InAppDriver;
use App\Modules\WNE\Drivers\PushDriver;
use App\Modules\WNE\Drivers\SmsDriver;
use App\Modules\WNE\Drivers\WebhookDriver;
use App\Modules\WNE\Jobs\SendNotificationDeliveryJob;
use App\Modules\WNE\Models\MsgCategory;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgDeliveryEvent;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §3I — "send a message through whichever channel(s) apply, without the caller knowing
 * anything about SMTP/Twilio/FCM specifics." A role recipient fans out to one
 * msg_notifications header per resolved user (not one shared header) — "single logical
 * notification... fan out to multiple channels" describes fan-out across CHANNELS for one
 * recipient, not across multiple recipients.
 *
 * Channel selection precedence: the caller's explicit `$channels` argument to notify() is an
 * authorial override (e.g. §3F's SLA escalation deliberately choosing where a breach shows
 * up) and skips §3J entirely; only when the caller passes no override does resolution go
 * per-recipient through PreferenceService (user's own channel choice, else the category's
 * `default_channels`, else `['in_app']`). §3L (templates) — subject/body are literal text
 * supplied by the caller unless an active template exists for the category/channel.
 */
class MessagingService
{
    public function __construct(private readonly PreferenceService $preferences) {}

    /** @var array<string, class-string<ChannelDriverInterface>> */
    private const DRIVER_MAP = [
        MsgChannelConfig::CHANNEL_EMAIL => EmailDriver::class,
        MsgChannelConfig::CHANNEL_IN_APP => InAppDriver::class,
        MsgChannelConfig::CHANNEL_SMS => SmsDriver::class,
        MsgChannelConfig::CHANNEL_PUSH => PushDriver::class,
        MsgChannelConfig::CHANNEL_WEBHOOK => WebhookDriver::class,
    ];

    /**
     * @param  array{type: string, user_id?: int, role?: string}  $recipient
     * @return Collection<int, MsgNotification> one header per resolved recipient
     */
    public function notify(
        string $category,
        array $recipient,
        string $subject,
        string $body,
        array $data = [],
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $channels = null,
    ): Collection {
        $recipientUserIds = $this->resolveRecipients($recipient);

        if ($recipientUserIds === null) {
            // Genuinely unresolvable (e.g. manager_of_user — no HCM org-chart to consult) —
            // one failed header records that, rather than silently dropping the request.
            return collect([$this->createUnresolvedHeader($category, $recipient, $subject, $body, $data, $subjectType, $subjectId)]);
        }

        if ($recipientUserIds->isEmpty()) {
            // A role with zero members today is a legitimate (if unusual) state, not a
            // resolution failure — still recorded as a failed header, never a silent no-op.
            return collect([$this->createUnresolvedHeader($category, $recipient, $subject, $body, $data, $subjectType, $subjectId, "No users hold role '{$recipient['role']}'.")]);
        }

        return $recipientUserIds->map(
            fn (int $userId) => $this->sendToOneRecipient($category, $recipient, $userId, $subject, $body, $data, $subjectType, $subjectId, $channels)
        );
    }

    /**
     * §3G: a webhook_call has no user/role recipient to resolve — it bypasses notify()'s
     * whole recipient-fan-out and §3J preference resolution entirely (an outbound systems
     * integration point isn't something a user opts in/out of or gets quiet hours on) and
     * creates exactly one header + one `webhook` delivery. Everything past that point —
     * dispatch, retry, dead-letter — is the identical §3M pipeline every other channel uses.
     */
    public function notifyWebhook(string $category, string $subject, string $body, ?string $subjectType, ?int $subjectId): MsgNotification
    {
        return DB::transaction(function () use ($category, $subject, $body, $subjectType, $subjectId) {
            $notification = MsgNotification::query()->create([
                'category_code' => $category,
                'recipient_type' => MsgNotification::RECIPIENT_WEBHOOK,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject' => $subject,
                'body' => $body,
                'data' => [],
                'status' => MsgNotification::STATUS_PENDING,
                'created_at' => now(),
            ]);

            $delivery = MsgNotificationDelivery::query()->create([
                'notification_id' => $notification->id,
                'channel' => MsgChannelConfig::CHANNEL_WEBHOOK,
                'status' => MsgNotificationDelivery::STATUS_PENDING,
            ]);
            MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_CREATED);

            SendNotificationDeliveryJob::dispatch($delivery->id)->onQueue('notifications')->afterCommit();
            MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_QUEUED);

            return $notification;
        });
    }

    /** @return ?Collection<int, int> resolved user ids, or null when the recipient type is unresolvable (e.g. no HCM) */
    private function resolveRecipients(array $recipient): ?Collection
    {
        return match ($recipient['type'] ?? null) {
            'user' => collect([$recipient['user_id']]),
            'role' => ConfigGroupUser::query()->where('group_code', $recipient['role'])->pluck('user_id')->unique()->values(),
            default => null, // manager_of_user and anything else — WNE has no org-chart to resolve it
        };
    }

    private function resolveChannels(string $category): array
    {
        $default = MsgCategory::query()->where('code', $category)->value('default_channels');

        return $default ?: [MsgChannelConfig::CHANNEL_IN_APP];
    }

    /** @param  ?list<string>  $channels  null = resolve per recipient via §3J; non-null = caller override, §3J is skipped entirely */
    private function sendToOneRecipient(
        string $category,
        array $recipient,
        int $userId,
        string $subject,
        string $body,
        array $data,
        ?string $subjectType,
        ?int $subjectId,
        ?array $channels,
    ): MsgNotification {
        return DB::transaction(function () use ($category, $recipient, $userId, $subject, $body, $data, $subjectType, $subjectId, $channels) {
            $notification = MsgNotification::query()->create([
                'category_code' => $category,
                'recipient_type' => $recipient['type'],
                'recipient_user_id' => $userId,
                'recipient_role' => $recipient['type'] === 'role' ? $recipient['role'] : null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject' => $subject,
                'body' => $body,
                'data' => $data,
                'status' => MsgNotification::STATUS_PENDING,
                'created_at' => now(),
            ]);

            $resolvedChannels = $channels ?? $this->preferences->resolveChannelsFor($userId, $category, $this->resolveChannels($category));

            if ($resolvedChannels === []) {
                // §3J opt-out (or an explicit empty override) — recorded, never a silent drop.
                $notification->update(['status' => MsgNotification::STATUS_FAILED, 'error' => 'No delivery channel resolved for this recipient.']);

                return $notification;
            }

            foreach ($resolvedChannels as $channel) {
                $delivery = MsgNotificationDelivery::query()->create([
                    'notification_id' => $notification->id,
                    'channel' => $channel,
                    'status' => MsgNotificationDelivery::STATUS_PENDING,
                ]);
                MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_CREATED);

                $dispatch = SendNotificationDeliveryJob::dispatch($delivery->id)->onQueue('notifications');

                // §3J: a non-urgent category's delivery generated inside the recipient's own
                // quiet hours is deferred to the window's end, never dropped — the delivery
                // row above already exists at `pending`, so this only delays the job's own
                // execution time, not when the durable state was recorded.
                if ($delay = $this->preferences->quietHoursDelayFor($userId, $channel, $category)) {
                    $dispatch->delay($delay);
                }

                $dispatch->afterCommit();
                MsgDeliveryEvent::log($delivery->id, MsgDeliveryEvent::EVENT_QUEUED);
            }

            return $notification;
        });
    }

    private function createUnresolvedHeader(
        string $category,
        array $recipient,
        string $subject,
        string $body,
        array $data,
        ?string $subjectType,
        ?int $subjectId,
        ?string $error = null,
    ): MsgNotification {
        return MsgNotification::query()->create([
            'category_code' => $category,
            'recipient_type' => $recipient['type'] ?? 'unknown',
            'recipient_role' => $recipient['role'] ?? null,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject' => $subject,
            'body' => $body,
            'data' => $data,
            'status' => MsgNotification::STATUS_FAILED,
            'error' => $error ?? "Recipient type '{$recipient['type']}' cannot be resolved (no HCM/org-chart integration built yet).",
            'created_at' => now(),
        ]);
    }

    /** @return class-string<ChannelDriverInterface> */
    public static function driverFor(string $channel): string
    {
        return self::DRIVER_MAP[$channel] ?? throw new \InvalidArgumentException("No driver registered for channel '{$channel}'.");
    }
}
