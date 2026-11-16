<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use App\Domain\Content\Blocks\Enums\TileIcon;
use Illuminate\Validation\Rule;

/**
 * Contact form (2026-11-16, Contact us 165:9974 "Columns"): the "Send us a message" card — a
 * heading, an intro and a privacy line; its fields (name, mobile, topic, order number, message)
 * are fixed, because the API that receives them (POST /storefront/contact-messages → the admin
 * Inbox) is. Beside it, two optional cards: "Visit our shop" (text, opening-hours rows, an
 * optional map link) and a list of quick links, each with an icon. A card without a title is
 * not shown. Hours and the visit text are copy, not settings: the owner edits them here.
 */
final class ContactFormSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            ...self::text('intro', 400, false),
            ...self::text('privacy', 200, false),
            ...self::text('visit_title', 80, false),
            ...self::text('visit_body', 400, false),
            'visit_map_href' => self::href(),
            'hours' => ['sometimes', 'array', 'max:6'],
            'hours.*' => ['required', 'array'],
            'hours.*.label' => ['required', 'string', 'max:60'],
            'hours.*.label_bn' => ['nullable', 'string', 'max:60'],
            'hours.*.value' => ['required', 'string', 'max:40'],
            'hours.*.value_bn' => ['nullable', 'string', 'max:40'],
            ...self::text('links_title', 80, false),
            'links' => ['sometimes', 'array', 'max:6'],
            'links.*' => ['required', 'array'],
            'links.*.icon' => ['required', Rule::in(TileIcon::values())],
            'links.*.label' => ['required', 'string', 'max:60'],
            'links.*.label_bn' => ['nullable', 'string', 'max:60'],
            'links.*.href' => self::href(true),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Send us a message', 'title_bn' => 'আমাদের মেসেজ পাঠান',
            'intro' => 'Tell us what you need and we will call or WhatsApp you back.',
            'intro_bn' => 'কী দরকার জানান, আমরা কল বা হোয়াটসঅ্যাপে আপনাকে উত্তর দেব।',
            'privacy' => 'We use your number only to reply to this message.',
            'privacy_bn' => 'এই মেসেজের উত্তর দিতেই শুধু আপনার নম্বর ব্যবহার করি।',
            'visit_title' => null, 'visit_title_bn' => null, 'visit_body' => null, 'visit_body_bn' => null, 'visit_map_href' => null,
            'hours' => [],
            'links_title' => null, 'links_title_bn' => null,
            'links' => [],
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title', 'intro', 'privacy', 'visit_title', 'visit_body');
        $out['visit_map_href'] = self::str($content['visit_map_href'] ?? null);
        $out['hours'] = [];
        foreach (is_array($content['hours'] ?? null) ? $content['hours'] : [] as $row) {
            if (is_array($row)) {
                $hour = [];
                self::copyText($row, $hour, 'label', 'value');
                $out['hours'][] = $hour;
            }
        }
        self::copyText($content, $out, 'links_title');
        $out['links'] = [];
        foreach (is_array($content['links'] ?? null) ? $content['links'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $link = ['icon' => TileIcon::tryFrom(is_string($row['icon'] ?? null) ? $row['icon'] : '')->value ?? TileIcon::CheckCircle->value];
            self::copyText($row, $link, 'label');
            $link['href'] = self::str($row['href'] ?? null);
            $out['links'][] = $link;
        }

        return $out;
    }
}
