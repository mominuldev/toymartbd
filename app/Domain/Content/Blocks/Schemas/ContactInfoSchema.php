<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Contact details (2026-11-16, built for the Contact page): a heading and intro, opening hours and
 * which channels to show. The values themselves — hotline, WhatsApp, Messenger, email, address —
 * are never typed into the block: the storefront reads them from Settings › Store › Business
 * (`GET /storefront/store-info`), so one change there updates the footer, the header and every
 * contact block at once. `map_href` is an optional "Open in Maps" link (store path or https).
 *
 * 2026-11-16 (Contact us 165:9913): `title` is optional — the Contact page shows the tiles without
 * a heading — and each tile may carry a caption: `hours` under Call, `whatsapp_note`, `email_note`
 * and `address_note`; `address_label` replaces the full store address as the Visit tile's value
 * ("Dhanmondi, Dhaka"), the address itself still coming from the settings when it is empty.
 */
final class ContactInfoSchema extends BlockSchema
{
    /** The channels an editor can switch off, in display order. */
    public const array CHANNELS = ['hotline', 'whatsapp', 'messenger', 'email', 'address'];

    public function rules(): array
    {
        $rules = [
            ...self::text('title', 120, false),
            ...self::text('sub', 300, false),
            ...self::text('hours', 120, false),
            ...self::text('whatsapp_note', 120, false),
            ...self::text('email_note', 120, false),
            ...self::text('address_label', 120, false),
            ...self::text('address_note', 120, false),
            'map_href' => self::href(),
        ];
        foreach (self::CHANNELS as $channel) {
            $rules['show_'.$channel] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function defaults(): array
    {
        return [
            'title' => 'Talk to a real person', 'title_bn' => 'সরাসরি কথা বলুন',
            'sub' => 'Questions about a toy, an order or a delivery? Call, message or email us — we answer every day.',
            'sub_bn' => 'খেলনা, অর্ডার বা ডেলিভারি নিয়ে প্রশ্ন? কল করুন, মেসেজ দিন বা ইমেইল করুন — আমরা প্রতিদিন উত্তর দিই।',
            'hours' => 'Saturday to Thursday, 9 AM – 9 PM', 'hours_bn' => 'শনিবার থেকে বৃহস্পতিবার, সকাল 9টা – রাত 9টা',
            'whatsapp_note' => null, 'whatsapp_note_bn' => null, 'email_note' => null, 'email_note_bn' => null,
            'address_label' => null, 'address_label_bn' => null, 'address_note' => null, 'address_note_bn' => null,
            'map_href' => null,
            'show_hotline' => true, 'show_whatsapp' => true, 'show_messenger' => true, 'show_email' => true, 'show_address' => true,
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title', 'sub', 'hours', 'whatsapp_note', 'email_note', 'address_label', 'address_note');
        $out['map_href'] = self::str($content['map_href'] ?? null);
        foreach (self::CHANNELS as $channel) {
            $out['show_'.$channel] = self::bool($content['show_'.$channel] ?? null, true);
        }

        return $out;
    }
}
