<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Support\SmsSegments;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Support\PhoneNumber;

/**
 * What the container hands out for SmsGateway: the configured driver, plus a
 * notification_logs row per send (masked recipient, segments, cost). This is how a
 * direct sender — the OTP job — gets its SMS spend recorded without knowing about logs.
 * It never stores the body: an OTP body is a credential.
 *
 * The locale of a direct send is inferred from the encoding (UCS-2 → bn), because the
 * contract carries no locale. Called from queued jobs only, never inside a transaction.
 */
final readonly class RecordingSmsGateway implements SmsGateway
{
    public function __construct(private SmsGateway $inner, private string $provider) {}

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $result = $this->inner->send($e164Phone, $body, $template);

        $category = TemplateCatalog::find($template)['category']
            ?? (str_starts_with($template, 'otp.') ? NotificationCategory::Security : NotificationCategory::OrderUpdates);

        NotificationLog::query()->create([
            'channel' => NotificationChannel::Sms,
            'template' => mb_substr($template, 0, 64),
            'category' => $category,
            'locale' => SmsSegments::isGsm7($body) ? 'en' : 'bn',
            'recipient' => PhoneNumber::tryParse($e164Phone)?->masked() ?? '***',
            'recipient_hash' => NotificationLog::hashRecipient($e164Phone),
            'status' => $result->accepted ? NotificationStatus::Sent : NotificationStatus::Failed,
            'provider' => $this->provider,
            'provider_message_id' => $result->providerMessageId,
            'segments' => $result->segments,
            'encoding' => SmsSegments::encoding($body),
            'cost' => $result->cost,
            'error' => $result->error,
            'attempts' => 1,
            'queued_at' => now(),
            'sent_at' => $result->accepted ? now() : null,
            'failed_at' => $result->accepted ? null : now(),
        ]);

        return $result;
    }
}
