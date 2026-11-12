<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A server-side measurement of a template body in both locales, rendered with sample
 * values: characters, encoding, SMS segments (UCS-2 Bangla 70/67, GSM-7 160/153) and the
 * cost of one send at the configured PLACEHOLDER rate. The editor shows this as is.
 *
 * @property array{en: array{rendered: string, chars: int, encoding: string, segments: int|null, per_segment_limit: int|null, cost: Money}, bn: array{rendered: string, chars: int, encoding: string, segments: int|null, per_segment_limit: int|null, cost: Money}, unknown_variables: list<string>} $resource
 */
#[SchemaName('NotificationTemplatePreview')]
final class NotificationTemplatePreviewResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $side = static fn (array $m): array => [
            'rendered' => $m['rendered'],
            'chars' => $m['chars'],
            'encoding' => $m['encoding'],
            'segments' => $m['segments'],
            'per_segment_limit' => $m['per_segment_limit'],
            /** @var string */
            'cost' => $m['cost']->toDecimal(),
        ];

        return [
            /** @var array{rendered: string, chars: int, encoding: string, segments: int|null, per_segment_limit: int|null, cost: string} */
            'en' => $side($this->resource['en']),
            /** @var array{rendered: string, chars: int, encoding: string, segments: int|null, per_segment_limit: int|null, cost: string} */
            'bn' => $side($this->resource['bn']),
            /** @var list<string> */
            'unknown_variables' => $this->resource['unknown_variables'],
        ];
    }
}
