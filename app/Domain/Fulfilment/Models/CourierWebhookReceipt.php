<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One signature-verified courier webhook (P6, rule 19), kept in the shared
 * `webhook_receipts` table (DATABASE-DESIGN §3.12) under a provider-scoped key
 * `courier:{code}` so a courier event id can never collide with a payment gateway's.
 * UNIQUE (provider, external_event_id) makes a replay a no-op. Written only after the
 * driver's `verifyWebhook()` accepted the raw body.
 *
 * Fulfilment owns this view of the table; Payment keeps its own model for its rows
 * (domains do not share models, deptrac §ruleset).
 *
 * @property int $id
 * @property string $provider
 * @property string $external_event_id
 * @property bool $signature_valid
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $headers
 * @property string $source
 * @property Carbon|null $processed_at
 * @property string|null $result
 * @property string|null $error
 * @property Carbon|null $created_at
 */
final class CourierWebhookReceipt extends Model
{
    public const string PREFIX = 'courier:';

    protected $table = 'webhook_receipts';

    /** @var list<string> */
    protected $fillable = ['provider', 'external_event_id', 'signature_valid', 'payload', 'headers', 'source', 'processed_at', 'result', 'error'];

    public static function providerKey(string $code): string
    {
        return self::PREFIX.$code;
    }

    /** The provider code (`pathao`), without the `courier:` scope. */
    public function courierCode(): string
    {
        return str_starts_with($this->provider, self::PREFIX) ? substr($this->provider, strlen(self::PREFIX)) : $this->provider;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCouriers(Builder $query): Builder
    {
        return $query->where('provider', 'like', self::PREFIX.'%');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
