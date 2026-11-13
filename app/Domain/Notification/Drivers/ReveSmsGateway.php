<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Support\SmsSegments;
use App\Domain\Notification\Support\SmsTariff;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

/**
 * REVE SMS reseller API (the owner's provider, 2026-11-13), served for us from
 * `https://smpp.ajuratech.com:7790`.
 *
 * Contract, from the provider's own Postman collections and API sheet, plus a probe of
 * the live endpoint with dummy credentials on 2026-11-13:
 *   POST {base_url}/sendtext   JSON {apikey, secretkey, callerID, toUser, messageContent}
 *   → HTTP 200 ALWAYS, body {"Status": "...", "Text": "...", "Message_ID": "..."}
 *   errors seen: Status 109 "Invalid api key/secret key", 114 "Inappropriate request
 *   parameter" — both with an empty Message_ID.
 * We POST a JSON body rather than the documented GET query string so the API key and
 * secret never sit in a URL (proxy logs, exception messages).
 *
 * NOT yet confirmed with a real account: the success Status value and whether Bangla
 * (UCS-2) needs a flag. So "accepted" is decided by the provider handing back a
 * Message_ID — every error observed returns an empty one — rather than by guessing the
 * success code; a refusal is never mistaken for a send, and an accepted message is never
 * retried into a duplicate. The first real send with `php artisan toymart:sms:test`
 * settles both questions; record the answer here.
 *
 * Recipients go as 8801XXXXXXXXX (E.164 without the plus), the provider's format.
 * Nothing here logs the body (an OTP in a log is a credential), the secret, or a full
 * phone number (rule 20). Cost is the configured tariff, never the provider's word.
 */
final readonly class ReveSmsGateway implements SmsGateway
{
    public function __construct(private Http $http, private SmsTariff $tariff) {}

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $segments = SmsSegments::count($body);
        $config = (array) config('notifications.sms.drivers.reve');
        $masked = PhoneNumber::tryParse($e164Phone)?->masked() ?? '***';

        foreach (['base_url', 'api_key', 'secret_key', 'sender_id'] as $key) {
            if (! is_string($config[$key] ?? null) || $config[$key] === '') {
                return SmsResult::rejected("reve driver is not configured: {$key} is missing.", $segments);
            }
        }

        try {
            $response = $this->http
                ->timeout((int) ($config['timeout'] ?? 10))
                ->acceptJson()
                ->asJson()
                ->post(rtrim($config['base_url'], '/').'/sendtext', [
                    'apikey' => $config['api_key'],
                    'secretkey' => $config['secret_key'],
                    'callerID' => $config['sender_id'],
                    'toUser' => ltrim($e164Phone, '+'),
                    'messageContent' => $body,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('sms.reve.connection_failed', ['to' => $masked, 'template' => $template, 'exception' => $e::class]);

            return SmsResult::rejected('reve: could not reach the SMS provider.', $segments);
        }

        $payload = $response->json();
        $status = is_array($payload) ? (string) ($payload['Status'] ?? '') : '';
        $text = is_array($payload) ? (string) ($payload['Text'] ?? '') : '';
        $messageId = is_array($payload) ? trim((string) ($payload['Message_ID'] ?? '')) : '';

        if ($response->successful() && $messageId !== '') {
            Log::info('sms.reve.accepted', ['to' => $masked, 'template' => $template, 'status' => $status, 'text' => $text, 'segments' => $segments]);

            return SmsResult::accepted($messageId, $segments, $this->tariff->costFor($segments));
        }

        Log::warning('sms.reve.rejected', ['to' => $masked, 'template' => $template, 'http' => $response->status(), 'status' => $status, 'text' => $text]);

        return SmsResult::rejected(
            $text !== '' ? "reve {$status}: {$text}" : "reve: unexpected response (HTTP {$response->status()}).",
            $segments,
        );
    }
}
