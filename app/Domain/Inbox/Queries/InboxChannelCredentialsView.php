<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Queries;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Services\InboxChannels;
use App\Domain\Inbox\Services\InboxChannelSettings;

/**
 * What Admin → Integrations → Messaging shows for the WhatsApp and Messenger inbox: the
 * driver, the account id, and for each secret ONLY whether it is set, where from and a
 * last-4 hint — never the value. Readiness comes from {@see InboxChannels::summary()}, so the
 * screen, the Integrations hub and the inbox header can never disagree. `verified` is always
 * what the driver says (false: no real Meta account has been connected).
 *
 * @phpstan-type SecretState array{set: bool, hint: ?string, source: ?string}
 * @phpstan-type ChannelView array{channel: string, driver: string, driver_source: string, account_id: ?string, account_id_source: ?string, send_token: SecretState, app_secret: SecretState, verify_token: SecretState, webhook_url: string, webhook_ready: bool, send_ready: bool, verified: bool}
 */
final readonly class InboxChannelCredentialsView
{
    public function __construct(private InboxChannelSettings $settings, private InboxChannels $channels) {}

    /** @return array{whatsapp: ChannelView, messenger: ChannelView} */
    public function get(): array
    {
        $summary = [];
        foreach ($this->channels->summary() as $row) {
            $summary[$row['channel']] = $row;
        }

        return [
            'whatsapp' => $this->channel(InboxChannel::WhatsApp, $summary['whatsapp']),
            'messenger' => $this->channel(InboxChannel::Messenger, $summary['messenger']),
        ];
    }

    /**
     * @param  array{channel: string, driver: string, verified: bool, webhook_ready: bool, send_ready: bool}  $summary
     * @return ChannelView
     */
    private function channel(InboxChannel $channel, array $summary): array
    {
        $idName = InboxChannelSettings::ACCOUNT_ID[$channel->value];

        return [
            'channel' => $channel->value,
            'driver' => $this->settings->driver($channel),
            'driver_source' => $this->settings->source($channel, 'driver') ?? 'env',
            'account_id' => $this->settings->get($channel, $idName),
            'account_id_source' => $this->settings->source($channel, $idName),
            'send_token' => $this->secret($channel, InboxChannelSettings::SEND_TOKEN[$channel->value]),
            'app_secret' => $this->secret($channel, 'app_secret'),
            'verify_token' => $this->secret($channel, 'verify_token'),
            // What to paste into Meta's "Callback URL": absolute, from APP_URL.
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/webhooks/inbox/'.$channel->value,
            'webhook_ready' => $summary['webhook_ready'],
            'send_ready' => $summary['send_ready'],
            'verified' => $summary['verified'],
        ];
    }

    /** @return array{set: bool, hint: ?string, source: ?string} */
    private function secret(InboxChannel $channel, string $name): array
    {
        $source = $this->settings->source($channel, $name);

        return ['set' => $source !== null, 'hint' => $this->settings->hint($channel, $name), 'source' => $source];
    }
}
