<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Enums\LabelTemplate;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A barcode label stock (`GET /products/labels/templates`). Sizes in millimetres.
 *
 * @property LabelTemplate $resource
 */
#[SchemaName('LabelTemplateInfo')]
final class LabelTemplateResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $template = $this->resource;
        [$width, $height] = $template->labelSize();

        return [
            /** @var 'roll_38x25'|'roll_50x25'|'a4_65'|'a4_24' */
            'value' => $template->value,
            /** @var 'roll'|'a4' */
            'stock' => $template->isSheet() ? 'a4' : 'roll',
            /**
             * Label width, mm, as a decimal string ("38.1").
             *
             * @var string
             */
            'width_mm' => self::mm($width),
            /**
             * Label height, mm, as a decimal string ("21.2").
             *
             * @var string
             */
            'height_mm' => self::mm($height),
            /** Labels per page: 1 on a roll. */
            'per_page' => $template->perPage(),
        ];
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
