<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The result of "Test connection" on an inbox channel — also returned as `last_check` on
 * GET /settings/inbox-channels. Never a token. A passing test does not mark the channel verified.
 *
 * @property array{channel: string, ok: bool, checked_at: string, display_name: ?string, display_number: ?string, quality_rating: ?string, error_code: ?string, error_message: ?string} $resource
 */
#[SchemaName('InboxChannelTestResult')]
final class InboxChannelTestResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var 'whatsapp'|'messenger' */
            'channel' => $r['channel'],
            /** @var bool Meta answered the read with these credentials. */
            'ok' => $r['ok'],
            /** @var string ISO 8601. */
            'checked_at' => $r['checked_at'],
            /** @var string|null WhatsApp: the verified business name. Messenger: the Page name. */
            'display_name' => $r['display_name'],
            /** @var string|null WhatsApp only: the number as Meta displays it. */
            'display_number' => $r['display_number'],
            /** @var string|null WhatsApp only: GREEN | YELLOW | RED | NA (Meta's value, passed through). */
            'quality_rating' => $r['quality_rating'],
            /** @var string|null `not_configured`, `unreachable`, `meta_{graph error code}` (e.g. meta_190 = token invalid or expired) or `http_{status}`. */
            'error_code' => $r['error_code'],
            /** @var string|null Meta's own error text, token scrubbed, ≤ 300 characters. */
            'error_message' => $r['error_message'],
        ];
    }
}
