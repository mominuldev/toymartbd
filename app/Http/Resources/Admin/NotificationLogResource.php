<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Models\NotificationLog;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One logged message (P4-NOT-01). `recipient` is already masked at write time (rule 20);
 * the payload is not exposed. `cost` is a decimal string (rule 6). `order_number` is set
 * when the notifiable is an order.
 *
 * @property NotificationLog $resource
 */
#[SchemaName('NotificationLog')]
final class NotificationLogResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $log = $this->resource;
        $payload = $log->payload ?? [];

        return [
            'id' => $log->id,
            /** @var 'sms'|'whatsapp'|'email' */
            'channel' => $log->channel->value,
            'template' => $log->template,
            /** @var 'security'|'order_updates'|'marketing' */
            'category' => $log->category->value,
            'locale' => $log->locale,
            'recipient' => $log->recipient,
            /** @var string|null */
            'order_number' => $log->notifiable_type === NotificationLog::NOTIFIABLE_ORDER && is_string($payload['order_number'] ?? null) ? $payload['order_number'] : null,
            'order_id' => $log->notifiable_type === NotificationLog::NOTIFIABLE_ORDER ? $log->notifiable_id : null,
            /** @var 'queued'|'sent'|'failed'|'dead_lettered'|'skipped' */
            'status' => $log->status->value,
            'provider' => $log->provider,
            'provider_message_id' => $log->provider_message_id,
            'segments' => $log->segments,
            'encoding' => $log->encoding,
            /** @var string */
            'cost' => $log->cost->toDecimal(),
            'error' => $log->error,
            'attempts' => $log->attempts,
            'fallback_of_id' => $log->fallback_of_id,
            /** @var string|null */
            'queued_at' => self::utc($log->queued_at),
            /** @var string|null */
            'sent_at' => self::utc($log->sent_at),
            /** @var string|null */
            'failed_at' => self::utc($log->failed_at),
            /** @var string|null */
            'created_at' => self::utc($log->created_at),
        ];
    }

    private static function utc(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
