<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\DTOs\WhatsAppCloudCredentials;

/**
 * Where Notification reads the WhatsApp Cloud API credentials from without depending on the
 * Inbox (deptrac). The store has one WhatsApp number: its phone number id, access token and
 * Business Account id are entered once, on the Inbox channels card (Admin → Integrations →
 * Messaging), and the Inbox implements this seam over those settings
 * ({@see \App\Domain\Inbox\Services\InboxWhatsAppCloudAccount}). Read fresh on every call —
 * a token fixed in the admin applies to the next send without a worker restart.
 */
interface WhatsAppCloudAccount
{
    public function credentials(): WhatsAppCloudCredentials;
}
