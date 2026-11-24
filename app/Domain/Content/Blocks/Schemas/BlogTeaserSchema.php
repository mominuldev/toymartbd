<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Blog teaser slot: the `limit` latest live posts (Smart Junior "Play & learn journal" 34:5851),
 * under the section header (eyebrow chip, heading, line). Hidden when there are no posts.
 */
final class BlogTeaserSchema extends BlockSchema
{
    public function rules(): array
    {
        return [...self::headerRules(), 'show_see_all' => ['sometimes', 'boolean'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:6']];
    }

    public function defaults(): array
    {
        return [
            'eyebrow' => 'For parents', 'eyebrow_bn' => 'অভিভাবকদের জন্য', 'eyebrow_tone' => 'green',
            'title' => 'From the toy box', 'title_bn' => 'খেলনার বাক্স থেকে', 'sub' => null, 'sub_bn' => null,
            'show_see_all' => false, 'limit' => 3,
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyHeader($content, $out, 'green');
        $out['show_see_all'] = self::bool($content['show_see_all'] ?? null, false);
        $out['limit'] = self::int($content['limit'] ?? null, 3);

        return $out;
    }
}
