<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The company details printed on invoices issued from now on (P8, N-13). Null = not set;
 * `placeholders` lists the required fields still unset (printed as "[placeholder]").
 *
 * @property array{values: array<string, string|int|null>, placeholders: list<string>} $resource
 */
#[SchemaName('InvoiceSettings')]
final class InvoiceSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v = $this->resource['values'];

        return [
            /** @var string|null */
            'name_en' => $v['name_en'] ?? null,
            /** @var string|null */
            'name_bn' => $v['name_bn'] ?? null,
            /** @var string|null */
            'address_en' => $v['address_en'] ?? null,
            /** @var string|null */
            'address_bn' => $v['address_bn'] ?? null,
            /** @var string|null */
            'hotline' => $v['hotline'] ?? null,
            /** @var string|null */
            'email' => $v['email'] ?? null,
            /** @var string|null */
            'website' => $v['website'] ?? null,
            /** @var string|null */
            'trade_licence' => $v['trade_licence'] ?? null,
            /** @var string|null */
            'bin' => $v['bin'] ?? null,
            /** @var int|null */
            'logo_media_id' => isset($v['logo_media_id']) ? (int) $v['logo_media_id'] : null,
            /** @var string|null */
            'footer_en' => $v['footer_en'] ?? null,
            /** @var string|null */
            'footer_bn' => $v['footer_bn'] ?? null,
            /**
             * Required fields not set yet.
             *
             * @var list<string>
             */
            'placeholders' => $this->resource['placeholders'],
        ];
    }
}
