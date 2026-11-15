<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * "Test connection" on an Inbox channel card (Admin → Integrations → Messaging): one read-only
 * Graph API call with the saved credentials, then the result is stored as the channel's last
 * check (`inbox.{channel}.last_check`) and audited (`credential_changed`, scope
 * `inbox_connection_test`, the outcome only).
 *
 *  - WhatsApp: `GET /{phone-number-id}?fields=display_phone_number,verified_name,quality_rating`
 *    (documentation/business-messaging/whatsapp/reference/whatsapp-business-phone-number/…).
 *  - Messenger: `GET /{page-id}?fields=name` (docs/graph-api/reference/page).
 *
 * The provider call happens BEFORE the transaction (rule 4). The stored result holds what Meta
 * said about the account (display name / number, quality rating) or a Graph error code and its
 * message — never the token, which is also scrubbed from any message Meta echoes. A passing test
 * does NOT mark the channel verified: that is the owner's call once a real message has arrived.
 */
final readonly class TestInboxChannelConnection
{
    public function __construct(private InboxChannelSettings $settings) {}

    /** @return array{ok: bool, checked_at: string, display_name: ?string, display_number: ?string, quality_rating: ?string, error_code: ?string, error_message: ?string} */
    public function handle(InboxChannel $channel, ?int $userId): array
    {
        if (! $channel->receivesMetaWebhooks()) {
            throw new InvalidArgumentException('Only WhatsApp and Messenger can be tested.');
        }

        $result = $this->probe($channel);

        DB::transaction(function () use ($channel, $result, $userId): void {
            Setting::put(InboxChannelSettings::key($channel, InboxChannelSettings::LAST_CHECK), $result, SettingGroup::Notification, false, $userId);

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'before' => ['scope' => 'inbox_connection_test', 'channel' => $channel->value],
                'after' => ['scope' => 'inbox_connection_test', 'channel' => $channel->value, 'result' => $result['ok'] ? 'ok' : 'failed', 'error_code' => $result['error_code']],
            ]);
        });

        return $result;
    }

    /** @return array{ok: bool, checked_at: string, display_name: ?string, display_number: ?string, quality_rating: ?string, error_code: ?string, error_message: ?string} */
    private function probe(InboxChannel $channel): array
    {
        $id = $this->settings->get($channel, InboxChannelSettings::ACCOUNT_ID[$channel->value]);
        $token = $this->settings->get($channel, InboxChannelSettings::SEND_TOKEN[$channel->value]);
        if ($id === null || $token === null) {
            return self::failed('not_configured', null);
        }

        $fields = $channel === InboxChannel::WhatsApp ? 'display_phone_number,verified_name,quality_rating' : 'name';
        $url = rtrim((string) config('inbox.graph_base_url'), '/').'/'.config('inbox.graph_version').'/'.rawurlencode($id);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('inbox.timeout', 10))
                ->get($url, ['fields' => $fields]);
        } catch (ConnectionException) {
            // The exception text can carry the URL; report a fixed code only.
            return self::failed('unreachable', null);
        }

        if (! $response->successful()) {
            return self::failed(self::errorCode($response), self::errorMessage($response, $token));
        }

        $text = static fn (string $key): ?string => is_string($response->json($key)) && $response->json($key) !== '' ? mb_substr((string) $response->json($key), 0, 120) : null;

        return [
            'ok' => true,
            'checked_at' => now()->toIso8601String(),
            'display_name' => $channel === InboxChannel::WhatsApp ? $text('verified_name') : $text('name'),
            'display_number' => $channel === InboxChannel::WhatsApp ? $text('display_phone_number') : null,
            'quality_rating' => $channel === InboxChannel::WhatsApp ? $text('quality_rating') : null,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /** `meta_190` for a Graph error with a code, otherwise `http_{status}`. */
    private static function errorCode(Response $response): string
    {
        $code = $response->json('error.code');

        return is_int($code) || (is_string($code) && ctype_digit($code)) ? 'meta_'.$code : 'http_'.$response->status();
    }

    private static function errorMessage(Response $response, string $token): ?string
    {
        $message = $response->json('error.error_user_msg') ?? $response->json('error.message');
        if (! is_string($message) || $message === '') {
            return null;
        }

        return mb_substr(str_replace($token, '••••', $message), 0, 300);
    }

    /** @return array{ok: bool, checked_at: string, display_name: ?string, display_number: ?string, quality_rating: ?string, error_code: ?string, error_message: ?string} */
    private static function failed(string $code, ?string $message): array
    {
        return [
            'ok' => false,
            'checked_at' => now()->toIso8601String(),
            'display_name' => null,
            'display_number' => null,
            'quality_rating' => null,
            'error_code' => $code,
            'error_message' => $message,
        ];
    }
}
