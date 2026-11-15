<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\DTOs\ReplyAllowance;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\ReplyState;
use App\Domain\Inbox\Models\Conversation;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Decides what may be sent in a conversation now ({@see ReplyState}). The single source for
 * the API resource, the Actions (before queueing and again under the row lock) and the send
 * job (once more before the provider call).
 *
 *  - Live chat: always `open`.
 *  - WhatsApp: `open` within the 24-hour window, `template_only` after it (Meta: outside the
 *    window only an approved template may be sent).
 *  - Messenger: `open` within 24 hours; `human_agent` from 24 hours to 7 days after the
 *    customer's last message when the `inbox.messenger.human_agent` switch is on (Meta's
 *    HUMAN_AGENT tag allows a human reply for 7 days); otherwise `closed`.
 *
 * The switch is read from `settings` (key `inbox.messenger.human_agent`, group notification —
 * saved at Admin → Integrations → Messaging) and falls back to
 * `config('inbox.messenger.human_agent')`, false when neither is set. Not cached: turning it
 * off in the admin stops the next queued tagged reply.
 */
final class ReplyWindow
{
    public const string HUMAN_AGENT_SETTING = 'inbox.messenger.human_agent';

    /** Meta's HUMAN_AGENT tag: up to 7 days after the user's last message. */
    public const int HUMAN_AGENT_DAYS = 7;

    public function for(Conversation $conversation, ?Carbon $now = null): ReplyAllowance
    {
        $now ??= now();

        if (! $conversation->channel->hasServiceWindow()) {
            return new ReplyAllowance(ReplyState::Open, null);
        }

        $expires = $conversation->windowExpiresAt();
        if ($expires !== null && $expires->isAfter($now)) {
            return new ReplyAllowance(ReplyState::Open, $expires);
        }

        if ($conversation->channel === InboxChannel::WhatsApp) {
            return new ReplyAllowance(ReplyState::TemplateOnly, $expires);
        }

        $humanAgentUntil = $conversation->last_inbound_at?->copy()->addDays(self::HUMAN_AGENT_DAYS);
        if ($conversation->channel === InboxChannel::Messenger && $humanAgentUntil !== null && $humanAgentUntil->isAfter($now) && $this->humanAgentEnabled()) {
            return new ReplyAllowance(ReplyState::HumanAgent, $humanAgentUntil);
        }

        return new ReplyAllowance(ReplyState::Closed, $expires);
    }

    public function humanAgentEnabled(): bool
    {
        $stored = Setting::query()->where('group', SettingGroup::Notification->value)->find(self::HUMAN_AGENT_SETTING)?->value;
        $value = $stored ?? config(self::HUMAN_AGENT_SETTING, false);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
