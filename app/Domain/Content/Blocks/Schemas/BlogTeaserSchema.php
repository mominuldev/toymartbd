<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/** Blog teaser slot: the `limit` latest live posts (Figma Home "Blog" row). Hidden when there are none. */
final class BlogTeaserSchema extends BlockSchema
{
    public function rules(): array
    {
        return [...self::text('title', 120), ...self::text('sub', 160, false), 'limit' => ['sometimes', 'integer', 'min:1', 'max:6']];
    }

    public function defaults(): array
    {
        return ['title' => 'From the toy box', 'title_bn' => 'খেলনার বাক্স থেকে', 'sub' => null, 'sub_bn' => null, 'limit' => 3];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title', 'sub');
        $out['limit'] = self::int($content['limit'] ?? null, 3);

        return $out;
    }
}
