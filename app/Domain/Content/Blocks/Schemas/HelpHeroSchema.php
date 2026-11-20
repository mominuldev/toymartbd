<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Help hero (2026-11-17, Common questions 171:10981 / 174:8582): a centred Bloom-sky header — an
 * overline pill, the page headline, a lead — with an optional search box that filters the
 * page's FAQ sections as the visitor types (in the browser; nothing is sent anywhere). Like the
 * story and contact heroes, placed first on a page built from sections it replaces the page
 * header and its title is the page's H1.
 */
final class HelpHeroSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('overline', 40, false),
            ...self::text('title', 120),
            ...self::text('lead', 300, false),
            'show_search' => ['sometimes', 'boolean'],
            ...self::text('search_placeholder', 80, false),
        ];
    }

    public function defaults(): array
    {
        return [
            'overline' => 'Help centre', 'overline_bn' => 'সহায়তা কেন্দ্র',
            'title' => 'Common questions', 'title_bn' => 'সাধারণ প্রশ্ন',
            'lead' => null, 'lead_bn' => null,
            'show_search' => true,
            'search_placeholder' => null, 'search_placeholder_bn' => null,
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'overline', 'title', 'lead');
        $out['show_search'] = self::bool($content['show_search'] ?? null, true);
        self::copyText($content, $out, 'search_placeholder');

        return $out;
    }
}
