<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\StoreSettings;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Store settings › Business (Figma B · 38). The name, trade licence, BIN and hotline are the
 * same saved values `GET /settings/invoice` returns. Null = not set yet: the values are
 * placeholders until the owner supplies real ones (UI-UX-DESIGN-SPEC §10).
 *
 * @property array{values: array<string, string|null>, whatsapp: string|null, placeholders: list<string>, default_names: array{name_en: string, name_bn: string}, hotline_default: string|null} $resource
 */
#[SchemaName('BusinessDetails')]
final class BusinessDetailsResource extends ApiResource
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
            'trade_licence' => $v['trade_licence'] ?? null,
            /** @var string|null */
            'bin' => $v['bin'] ?? null,
            /** @var string|null */
            'hotline' => $v['hotline'] ?? null,
            /**
             * E.164, e.g. `+8801711000222`.
             *
             * @var string|null
             */
            'whatsapp' => $this->resource['whatsapp'],
            /**
             * What invoices print while a name is unset (the brand name).
             *
             * @var array{name_en: string, name_bn: string}
             */
            'default_names' => $this->resource['default_names'],
            /**
             * The hotline order messages use while none is saved here (env `STORE_HOTLINE`).
             *
             * @var string|null
             */
            'hotline_default' => $this->resource['hotline_default'],
            /**
             * Fields of this section an invoice still prints as "[placeholder]".
             *
             * @var list<string>
             */
            'placeholders' => $this->resource['placeholders'],
        ];
    }
}
