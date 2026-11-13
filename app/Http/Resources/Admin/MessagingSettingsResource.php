<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Admin → Integrations → Messaging. Effective values (saved setting, else env). The API key
 * and secret are NEVER returned — not even to super_admin: only whether each is set, where
 * it comes from, and a hint of at most its last four characters.
 *
 * @property array{sms_driver: string, reve_base_url: ?string, reve_sender_id: ?string, sms_cost_per_segment: string, whatsapp_enabled: bool, reve_api_key_set: bool, reve_api_key_hint: ?string, reve_api_key_source: ?string, reve_secret_key_set: bool, reve_secret_key_hint: ?string, reve_secret_key_source: ?string, sources: array<string, string>} $resource
 */
#[SchemaName('MessagingSettings')]
final class MessagingSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var 'log'|'null'|'reve'|'sms_net_bd' The SMS driver in use. */
            'sms_driver' => $r['sms_driver'],
            /** @var string|null */
            'reve_base_url' => $r['reve_base_url'],
            /** @var string|null */
            'reve_sender_id' => $r['reve_sender_id'],
            /** @var string Decimal string, taka per SMS segment (PLACEHOLDER until a contract). */
            'sms_cost_per_segment' => $r['sms_cost_per_segment'],
            /** @var bool Order updates go WhatsApp-first. Off until a WhatsApp account exists. */
            'whatsapp_enabled' => $r['whatsapp_enabled'],
            /** @var bool */
            'reve_api_key_set' => $r['reve_api_key_set'],
            /** @var string|null "••••1234" at most. */
            'reve_api_key_hint' => $r['reve_api_key_hint'],
            /** @var 'settings'|'env'|null */
            'reve_api_key_source' => $r['reve_api_key_source'],
            /** @var bool */
            'reve_secret_key_set' => $r['reve_secret_key_set'],
            /** @var string|null "••••1234" at most. */
            'reve_secret_key_hint' => $r['reve_secret_key_hint'],
            /** @var 'settings'|'env'|null */
            'reve_secret_key_source' => $r['reve_secret_key_source'],
            /** @var array{sms_driver: 'settings'|'env', reve_base_url: 'settings'|'env', reve_sender_id: 'settings'|'env', sms_cost_per_segment: 'settings'|'env', whatsapp_enabled: 'settings'|'env'} */
            'sources' => $r['sources'],
        ];
    }
}
