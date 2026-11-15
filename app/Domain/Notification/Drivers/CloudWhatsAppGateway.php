<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\WhatsAppCloudAccount;
use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\DTOs\WhatsAppResult;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WhatsApp Cloud API (Meta) — sends one approved utility template (rule 39; never OTP, rule
 * 32b). UNVERIFIED: no WhatsApp Business account exists (2026-11-16); written from Meta's
 * published docs and tested with Http::fake fixtures only (docs/api/inbox.md, "Order
 * notifications over WhatsApp").
 *
 *  - `POST /{version}/{phone-number-id}/messages`, Bearer token, `type: template`,
 *    `template: {name, language: {code}, components: [{type: body, parameters: [{type: text, text}]}]}`
 *    (documentation/business-messaging/whatsapp/templates/overview).
 *  - `messages[0].id` is the accepted message id; delivery arrives later as `statuses` webhooks
 *    (documentation/business-messaging/whatsapp/messages/send-messages).
 *
 * The credentials are the Inbox's WhatsApp number ({@see WhatsAppCloudAccount}). A provider
 * refusal (4xx) is a rejected WhatsAppResult — the send job then falls back to SMS; a network
 * error, 429 or 5xx throws so the job retries. The number is never logged; neither is the token
 * or the URL (the exception text of a connection error can carry it).
 */
final readonly class CloudWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(private WhatsAppCloudAccount $account) {}

    /** @param  list<string>  $parameters */
    public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): WhatsAppResult
    {
        $credentials = $this->account->credentials();
        if (! $credentials->canSend() || $providerTemplate === '') {
            return WhatsAppResult::rejected($providerTemplate === '' ? 'no_provider_template' : 'not_configured');
        }

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            // Meta's examples give the number without `+`.
            'to' => ltrim($e164Phone, '+'),
            'type' => 'template',
            'template' => [
                'name' => $providerTemplate,
                'language' => ['code' => $language],
            ],
        ];
        if ($parameters !== []) {
            $body['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(static fn (string $text): array => ['type' => 'text', 'text' => $text], $parameters),
            ]];
        }

        try {
            $response = Http::withToken((string) $credentials->accessToken)
                ->acceptJson()
                ->timeout($credentials->timeout)
                ->post($credentials->url(rawurlencode((string) $credentials->phoneNumberId).'/messages'), $body);
        } catch (ConnectionException) {
            throw new RuntimeException('WhatsApp Cloud API unreachable.');
        }

        if ($response->successful()) {
            $id = $response->json('messages.0.id');
            if (! is_string($id) || $id === '') {
                return WhatsAppResult::rejected('provider_error: accepted without a message id');
            }
            $rate = config('notifications.whatsapp.cost_per_message', '0.00');

            return WhatsAppResult::accepted($id, Money::of(is_string($rate) || is_int($rate) ? $rate : '0.00'));
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException("WhatsApp Cloud API answered {$response->status()}.");
        }

        $code = $response->json('error.code');
        $code = is_int($code) || is_string($code) ? (string) $code : (string) $response->status();
        Log::warning('notifications.whatsapp.rejected', [
            'to' => PhoneNumber::tryParse($e164Phone)?->masked() ?? '***',
            'template' => $template,
            'status' => $response->status(),
            'code' => $code,
        ]);

        $title = $response->json('error.error_user_title') ?? $response->json('error.message');

        return WhatsAppResult::rejected(mb_substr('provider_error:'.$code.(is_string($title) ? ' '.$title : ''), 0, 300));
    }
}
