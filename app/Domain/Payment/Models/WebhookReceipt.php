<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One signature-verified inbound provider notification (DATABASE-DESIGN `webhook_receipts`,
 * P4-PAY-03). UNIQUE (provider, external_event_id) makes a replay a no-op. Written only
 * after the signature checked out (rule 19).
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
final class WebhookReceipt extends Model
{
    /** @var list<string> */
    protected $fillable = ['provider', 'external_event_id', 'signature_valid', 'payload', 'headers', 'source', 'processed_at', 'result', 'error'];

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
