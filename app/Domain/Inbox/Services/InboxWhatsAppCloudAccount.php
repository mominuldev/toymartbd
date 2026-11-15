<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Notification\Contracts\WhatsAppCloudAccount;
use App\Domain\Notification\DTOs\WhatsAppCloudCredentials;

/**
 * The Inbox's WhatsApp credentials, lent to Notification's WhatsApp driver and template sync
 * through its contract (rule 2: Notification never reads Inbox internals). One WhatsApp number,
 * one stored token — never a second copy of the secret.
 */
final readonly class InboxWhatsAppCloudAccount implements WhatsAppCloudAccount
{
    public function __construct(private InboxChannelSettings $settings) {}

    public function credentials(): WhatsAppCloudCredentials
    {
        $base = config('inbox.graph_base_url');
        $version = config('inbox.graph_version');
        $timeout = config('inbox.timeout', 10);

        return new WhatsAppCloudCredentials(
            phoneNumberId: $this->settings->get(InboxChannel::WhatsApp, 'phone_number_id'),
            accessToken: $this->settings->get(InboxChannel::WhatsApp, 'access_token'),
            businessAccountId: $this->settings->get(InboxChannel::WhatsApp, 'business_account_id'),
            graphBaseUrl: is_string($base) && $base !== '' ? $base : 'https://graph.facebook.com',
            graphVersion: is_string($version) && $version !== '' ? $version : 'v27.0',
            timeout: is_int($timeout) ? $timeout : 10,
        );
    }
}
