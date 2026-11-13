<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/** "Shop by age" tiles: the seven age bands, each with its live product count. Only the heading is editable. */
final class AgeTilesSchema extends BlockSchema
{
    public function rules(): array
    {
        return [...self::text('title', 120), 'show_see_all' => ['sometimes', 'boolean']];
    }

    public function defaults(): array
    {
        return ['title' => 'Shop by age', 'title_bn' => 'বয়স অনুযায়ী কিনুন', 'show_see_all' => true];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $out['show_see_all'] = self::bool($content['show_see_all'] ?? null, true);

        return $out;
    }
}
