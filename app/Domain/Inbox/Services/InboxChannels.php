<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\Contracts\InboxChannelDriver;
use App\Domain\Inbox\Contracts\ReceivesWebhooks;
use App\Domain\Inbox\Drivers\LogChannelDriver;
use App\Domain\Inbox\Drivers\MessengerDriver;
use App\Domain\Inbox\Drivers\WhatsAppCloudDriver;
use App\Domain\Inbox\Enums\InboxChannel;

/**
 * Channel → driver (rule 5). Adding a channel means a driver and a line here, never a branch
 * in the Actions. Sending uses the CONFIGURED driver (default `log` everywhere); receiving
 * always uses the Meta driver for its signature check, whatever the send driver is, so an
 * unconfigured app secret refuses every webhook.
 */
final readonly class InboxChannels
{
    public function __construct(private InboxChannelSettings $settings) {}

    public function sender(InboxChannel $channel): InboxChannelDriver
    {
        return match ([$channel, $this->settings->driver($channel)]) {
            [InboxChannel::WhatsApp, 'cloud'] => new WhatsAppCloudDriver($this->settings),
            [InboxChannel::Messenger, 'messenger'] => new MessengerDriver($this->settings),
            default => new LogChannelDriver($channel),
        };
    }

    public function receiver(InboxChannel $channel): ?ReceivesWebhooks
    {
        return match ($channel) {
            InboxChannel::WhatsApp => new WhatsAppCloudDriver($this->settings),
            InboxChannel::Messenger => new MessengerDriver($this->settings),
            InboxChannel::LiveChat => null,
        };
    }

    /**
     * What the Integrations hub and the inbox header can say about each channel — never a
     * credential, only whether each is set.
     *
     * @return list<array{channel: string, driver: string, verified: bool, webhook_ready: bool, send_ready: bool}>
     */
    public function summary(): array
    {
        $rows = [];
        foreach (InboxChannel::cases() as $channel) {
            $driver = $this->sender($channel);
            $rows[] = [
                'channel' => $channel->value,
                'driver' => $driver->name(),
                'verified' => $driver->isVerified(),
                'webhook_ready' => match ($channel) {
                    InboxChannel::WhatsApp, InboxChannel::Messenger => $this->settings->isSet($channel, 'app_secret') && $this->settings->isSet($channel, 'verify_token'),
                    InboxChannel::LiveChat => false,
                },
                'send_ready' => match ($channel) {
                    InboxChannel::WhatsApp => $this->settings->isSet($channel, 'phone_number_id') && $this->settings->isSet($channel, 'access_token'),
                    InboxChannel::Messenger => $this->settings->isSet($channel, 'page_id') && $this->settings->isSet($channel, 'page_access_token'),
                    InboxChannel::LiveChat => false,
                },
            ];
        }

        return $rows;
    }
}
