<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Models\CartRecovery;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One abandoned cart in the admin report (P7). The phone is masked (rule 20); the message
 * columns come from its notification log row. Times ISO 8601 UTC.
 *
 * @property CartRecovery $resource
 */
#[SchemaName('CartRecovery')]
final class CartRecoveryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'cart_id' => $r->cart_id,
            /** @var string|null */
            'phone' => $r->getAttribute('masked_phone'),
            /** @var string|null */
            'customer_name' => $r->getAttribute('customer_name'),
            /** @var 'messaged'|'skipped' */
            'status' => $r->status->value,
            /** @var 'no_phone'|'no_consent'|'opted_out'|'cooldown'|'already_ordered'|'below_minimum'|'template_inactive'|'converted'|null */
            'skip_reason' => $r->skip_reason?->value,
            'locale' => $r->locale,
            /** @var string */
            'cart_value' => $r->cart_value->toDecimal(),
            'item_count' => $r->item_count,
            /** @var string|null */
            'abandoned_at' => self::utcIso($r->abandoned_at),
            /** @var 'queued'|'sent'|'failed'|'dead_lettered'|'skipped'|null */
            'message_status' => $r->getAttribute('message_status'),
            /** @var string|null */
            'message_cost' => $r->getAttribute('message_cost') === null ? null : (string) $r->getAttribute('message_cost'),
            /** @var int|null */
            'message_segments' => $r->getAttribute('message_segments') === null ? null : (int) $r->getAttribute('message_segments'),
            /** @var string|null */
            'sent_at' => $r->getAttribute('message_sent_at') === null ? null : (string) $r->getAttribute('message_sent_at'),
            /** @var string|null */
            'restored_at' => self::utcIso($r->restored_at),
            /** @var array{id: int, number: string, status: string, grand_total: string}|null */
            'recovered_order' => $r->recovered_order_id === null ? null : [
                'id' => $r->recovered_order_id,
                'number' => (string) $r->getAttribute('order_number'),
                'status' => (string) $r->getAttribute('order_status'),
                'grand_total' => (string) $r->getAttribute('order_total'),
            ],
            /** @var string|null */
            'recovered_at' => self::utcIso($r->recovered_at),
        ];
    }

    private static function utcIso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
