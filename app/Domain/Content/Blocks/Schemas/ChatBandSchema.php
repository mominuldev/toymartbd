<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Chat band (2026-11-17, FAQ "Still need help?" 171:11163): a mint band with a WhatsApp disc, a
 * title, a line of copy and a "Chat on WhatsApp" button. The WhatsApp number is never typed into
 * the block: the storefront links to the store's own number (Settings › Store › Business), and
 * leaves the button out while that number is still the sample.
 */
final class ChatBandSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            ...self::text('body', 300, false),
            ...self::text('button_label', 40, false),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Still need help?', 'title_bn' => 'আরও সাহায্য দরকার?',
            'body' => null, 'body_bn' => null,
            'button_label' => 'Chat on WhatsApp', 'button_label_bn' => 'হোয়াটসঅ্যাপে কথা বলুন',
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title', 'body', 'button_label');

        return $out;
    }
}
