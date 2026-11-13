<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\DTOs\SmsResult;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The outcome of one test SMS. `accepted` means the provider took it, not that the phone
 * received it. Cost is our configured tariff, never the provider's word.
 *
 * @property array{driver: string, result: SmsResult} $resource
 */
#[SchemaName('SmsTestResult')]
final class SmsTestResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource['result'];

        return [
            /** @var string */
            'driver' => $this->resource['driver'],
            /** @var bool */
            'accepted' => $result->accepted,
            /** @var string|null */
            'message_id' => $result->providerMessageId,
            /** @var int */
            'segments' => $result->segments,
            /** @var string Decimal string, taka. */
            'cost' => $result->cost->toDecimal(),
            /** @var string|null The provider's refusal, as it gave it. */
            'error' => $result->error,
        ];
    }
}
