<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;

/**
 * Every notification template the system knows (P4-I18N-03), with its variable
 * allowlist, its sample values and its default EN + BN copy.
 *
 * Copy is docs/_design/05-content-and-assets-spec.md §A.17 with the hard-coded sample
 * values turned into `{variables}`. Two deliberate differences from that document:
 *  - the sample order number is the real format `TMB-26-00482` (12 characters), not the
 *    spec's `TM10482` (7) — so the stored segment counts are honest for real orders;
 *  - the hotline, short-link domain and store domain are variables from config, because
 *    they are PLACEHOLDERS (UI-UX-DESIGN-SPEC §10).
 *
 * The database (`notification_templates`) is the source of truth once synced; this class
 * supplies the allowlist (which never comes from the DB) and the defaults to sync.
 *
 * WhatsApp bodies mirror what is submitted to Meta. `whatsapp_parameters` is the
 * positional order ({{1}}, {{2}}…) and must match the approved template exactly.
 */
final class TemplateCatalog
{
    /**
     * @return array<string, array{
     *     category: NotificationCategory,
     *     variables: list<string>,
     *     whatsapp_parameters?: list<string>,
     *     channels: array<string, array{body_en: string, body_bn: string, subject_en?: string, subject_bn?: string, preheader_en?: string, preheader_bn?: string, provider_template?: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            'otp.login' => [
                'category' => NotificationCategory::Security,
                'variables' => ['code', 'minutes'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD verification code: {code}. Valid {minutes} minutes. Do not share this code.',
                        'body_bn' => 'টয় মার্ট বিডি কোড: {code}। {minutes} মিনিট কাজ করবে। কাউকে দেবেন না।',
                    ],
                    'email' => [
                        'subject_en' => 'Your Toy Mart BD code is {code}',
                        'subject_bn' => 'আপনার টয় মার্ট বিডি কোড {code}',
                        'preheader_en' => 'Valid {minutes} minutes. We never ask for this code on a call.',
                        'preheader_bn' => '{minutes} মিনিট কাজ করবে। ফোনে আমরা কখনো এই কোড চাই না।',
                        'body_en' => "Your verification code is {code}. It is valid for {minutes} minutes.\nWe never ask for this code on a call. Do not share it with anyone.",
                        'body_bn' => "আপনার কোড {code}। {minutes} মিনিট কাজ করবে।\nফোনে আমরা কখনো এই কোড চাই না। কাউকে দেবেন না।",
                    ],
                ],
            ],

            'order.placed' => [
                'category' => NotificationCategory::OrderUpdates,
                'variables' => ['name', 'order_number', 'item_count', 'total', 'track_url', 'hotline'],
                'whatsapp_parameters' => ['name', 'order_number', 'item_count', 'total'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: order {order_number} received. BDT {total}, cash on delivery. We will call you to confirm. {track_url}',
                        'body_bn' => 'টয় মার্ট বিডি: অর্ডার {order_number} পেয়েছি। BDT {total}, ক্যাশ অন ডেলিভারি। কনফার্ম করতে ফোন করব। {track_url}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'order_placed',
                        'body_en' => 'Hello {name}, we have your Toy Mart BD order *{order_number}*. {item_count} items, total BDT {total}, cash on delivery. We will call this number within 2 hours on working days to confirm. Keep BDT {total} ready for the rider on delivery day.',
                        'body_bn' => '{name}, আপনার টয় মার্ট বিডি অর্ডার *{order_number}* আমাদের কাছে পৌঁছেছে। {item_count}টি পণ্য, সর্বমোট BDT {total}, ক্যাশ অন ডেলিভারি। কর্মদিবসে 2 ঘণ্টার মধ্যে এই নম্বরে ফোন করে কনফার্ম করব। ডেলিভারির দিন রাইডারের জন্য BDT {total} হাতে রাখুন।',
                    ],
                    'email' => [
                        'subject_en' => 'Order {order_number} received - Toy Mart BD',
                        'subject_bn' => 'অর্ডার {order_number} পেয়েছি - টয় মার্ট বিডি',
                        'preheader_en' => 'We will call you within 2 hours to confirm.',
                        'preheader_bn' => 'কনফার্ম করতে 2 ঘণ্টার মধ্যে ফোন করব।',
                        'body_en' => "Thank you, we have your order {order_number}: {item_count} items, total BDT {total}, cash on delivery.\nWe will call you within 2 hours on working days to confirm it.",
                        'body_bn' => "ধন্যবাদ, আপনার অর্ডার {order_number} আমরা পেয়েছি: {item_count}টি পণ্য, সর্বমোট BDT {total}, ক্যাশ অন ডেলিভারি।\nকর্মদিবসে 2 ঘণ্টার মধ্যে ফোন করে কনফার্ম করব।",
                    ],
                ],
            ],

            'order.confirmed' => [
                'category' => NotificationCategory::OrderUpdates,
                'variables' => ['name', 'order_number', 'date_range', 'track_url', 'hotline'],
                'whatsapp_parameters' => ['order_number', 'date_range'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: order {order_number} is confirmed and being packed. Delivery {date_range}. {track_url}',
                        'body_bn' => 'টয় মার্ট বিডি: অর্ডার {order_number} কনফার্ম হয়েছে, প্যাক করা হচ্ছে। {date_range} ডেলিভারি। {track_url}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'order_confirmed',
                        'body_en' => 'Your order *{order_number}* is confirmed and being packed. Expected delivery {date_range}. If you need to change the address or anything else, reply here or call {hotline} today.',
                        'body_bn' => 'আপনার অর্ডার *{order_number}* কনফার্ম হয়েছে, প্যাক করা হচ্ছে। ডেলিভারি হবে {date_range}। ঠিকানা বা অন্য কিছু বদলাতে হলে আজই এখানে উত্তর দিন বা {hotline} নম্বরে ফোন করুন।',
                    ],
                    'email' => [
                        'subject_en' => 'Order {order_number} confirmed',
                        'subject_bn' => 'অর্ডার {order_number} কনফার্ম হয়েছে',
                        'preheader_en' => 'Packed and ready. Expected delivery {date_range}.',
                        'preheader_bn' => 'প্যাক করা হয়ে গেছে। ডেলিভারি হবে {date_range}।',
                        'body_en' => "Your order {order_number} is confirmed and being packed. Expected delivery {date_range}.\nTo change the address or anything else, call {hotline} today.",
                        'body_bn' => "আপনার অর্ডার {order_number} কনফার্ম হয়েছে, প্যাক করা হচ্ছে। ডেলিভারি হবে {date_range}।\nঠিকানা বা অন্য কিছু বদলাতে হলে আজই {hotline} নম্বরে ফোন করুন।",
                    ],
                ],
            ],

            'order.shipped' => [
                'category' => NotificationCategory::OrderUpdates,
                'variables' => ['name', 'order_number', 'courier', 'consignment', 'eta', 'track_url'],
                'whatsapp_parameters' => ['order_number', 'courier', 'consignment', 'eta'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: order {order_number} handed to {courier}. Consignment {consignment}. The rider will call before delivery. {track_url}',
                        'body_bn' => 'টয় মার্ট বিডি: অর্ডার {order_number} {courier}-কে দেওয়া হয়েছে। কনসাইনমেন্ট {consignment}। রাইডার ফোন করবে। {track_url}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'order_shipped',
                        'body_en' => 'Order *{order_number}* has been handed to {courier}. Tracking number {consignment}. The rider will call before delivery, usually in {eta}.',
                        'body_bn' => 'অর্ডার *{order_number}* {courier}-কে দিয়ে দেওয়া হয়েছে। ট্র্যাকিং নম্বর {consignment}। ডেলিভারির আগে রাইডার ফোন করবে, সাধারণত {eta}-এর মধ্যে।',
                    ],
                    'email' => [
                        'subject_en' => 'Order {order_number} is on the way',
                        'subject_bn' => 'অর্ডার {order_number} পথে আছে',
                        'preheader_en' => '{courier} has it. Tracking {consignment}. The rider will call first.',
                        'preheader_bn' => '{courier}-এর কাছে আছে। ট্র্যাকিং {consignment}। রাইডার আগে ফোন করবে।',
                        'body_en' => "Your order {order_number} has been handed to {courier}. Tracking number {consignment}.\nThe rider will call before delivery, usually in {eta}.",
                        'body_bn' => "আপনার অর্ডার {order_number} {courier}-কে দেওয়া হয়েছে। ট্র্যাকিং নম্বর {consignment}।\nডেলিভারির আগে রাইডার ফোন করবে, সাধারণত {eta}-এর মধ্যে।",
                    ],
                ],
            ],

            'order.out_for_delivery' => [
                'category' => NotificationCategory::OrderUpdates,
                'variables' => ['name', 'order_number', 'cod_amount', 'rider_phone'],
                'whatsapp_parameters' => ['order_number', 'cod_amount', 'rider_phone'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: order {order_number} is out for delivery today. Please keep BDT {cod_amount} ready. Rider: {rider_phone}',
                        'body_bn' => 'টয় মার্ট বিডি: অর্ডার {order_number} আজ ডেলিভারি হবে। BDT {cod_amount} হাতে রাখুন। রাইডার: {rider_phone}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'order_out_for_delivery',
                        'body_en' => "Order *{order_number}* is out for delivery today. Please keep BDT {cod_amount} ready. The rider's number is {rider_phone}. If nobody will be home, reply here and we will reschedule.",
                        'body_bn' => 'অর্ডার *{order_number}* আজ ডেলিভারি হবে। BDT {cod_amount} হাতে রাখুন। রাইডারের নম্বর {rider_phone}। বাসায় কেউ না থাকলে এখানে উত্তর দিন, আমরা অন্যদিন পাঠাব।',
                    ],
                ],
            ],

            'order.delivered' => [
                'category' => NotificationCategory::OrderUpdates,
                'variables' => ['name', 'order_number', 'date', 'hotline'],
                'whatsapp_parameters' => ['order_number', 'date'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: order {order_number} delivered. Thank you. 7-day return applies. Problem? Call {hotline}',
                        'body_bn' => 'টয় মার্ট বিডি: অর্ডার {order_number} ডেলিভারি হয়েছে। ধন্যবাদ। 7 দিনের রিটার্ন সুবিধা আছে। সমস্যা হলে: {hotline}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'order_delivered',
                        'body_en' => 'Order *{order_number}* was delivered on {date}. Thank you. If anything is wrong — wrong item, damage, missing part — tell us within 7 days and we will take it back.',
                        'body_bn' => 'অর্ডার *{order_number}* {date}-এ ডেলিভারি হয়েছে। ধন্যবাদ। কিছু ভুল হলে — ভুল পণ্য, ভাঙা, অংশ কম — 7 দিনের মধ্যে জানান, আমরা ফেরত নেব।',
                    ],
                    'email' => [
                        'subject_en' => 'Delivered - order {order_number}',
                        'subject_bn' => 'ডেলিভারি হয়েছে - অর্ডার {order_number}',
                        'preheader_en' => 'Thank you. Anything wrong, tell us within 7 days and we take it back.',
                        'preheader_bn' => 'ধন্যবাদ। কিছু ভুল হলে 7 দিনের মধ্যে জানান, আমরা ফেরত নেব।',
                        'body_en' => "Your order {order_number} was delivered on {date}. Thank you.\nIf anything is wrong (wrong item, damage, missing part), call {hotline} within 7 days and we will take it back.",
                        'body_bn' => "আপনার অর্ডার {order_number} {date}-এ ডেলিভারি হয়েছে। ধন্যবাদ।\nকিছু ভুল হলে (ভুল পণ্য, ভাঙা, অংশ কম) 7 দিনের মধ্যে {hotline} নম্বরে জানান, আমরা ফেরত নেব।",
                    ],
                ],
            ],

            'order.cancelled' => [
                'category' => NotificationCategory::OrderUpdates,
                'variables' => ['name', 'order_number', 'refund_sentence', 'hotline', 'store_url'],
                'whatsapp_parameters' => ['order_number', 'refund_sentence'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: order {order_number} has been cancelled. Nothing is owed. To order again: {store_url}',
                        'body_bn' => 'টয় মার্ট বিডি: অর্ডার {order_number} বাতিল করা হয়েছে। কোনো টাকা দিতে হবে না। আবার অর্ডার: {store_url}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'order_cancelled',
                        'body_en' => 'Order *{order_number}* has been cancelled. Nothing is owed. {refund_sentence} If this was not you, call {hotline} today.',
                        'body_bn' => 'অর্ডার *{order_number}* বাতিল করা হয়েছে। কোনো টাকা দিতে হবে না। {refund_sentence} আপনি না করলে আজই {hotline} নম্বরে ফোন করুন।',
                    ],
                    'email' => [
                        'subject_en' => 'Order {order_number} cancelled',
                        'subject_bn' => 'অর্ডার {order_number} বাতিল হয়েছে',
                        'preheader_en' => 'Nothing is owed. Call {hotline} if this was not you.',
                        'preheader_bn' => 'কোনো টাকা দিতে হবে না। আপনি না করলে {hotline}-এ ফোন করুন।',
                        'body_en' => "Your order {order_number} has been cancelled. Nothing is owed. {refund_sentence}\nIf this was not you, call {hotline} today.",
                        'body_bn' => "আপনার অর্ডার {order_number} বাতিল করা হয়েছে। কোনো টাকা দিতে হবে না। {refund_sentence}\nআপনি না করলে আজই {hotline} নম্বরে ফোন করুন।",
                    ],
                ],
            ],
        ];
    }

    /** @return array{category: NotificationCategory, variables: list<string>, whatsapp_parameters?: list<string>, channels: array<string, array<string, string>>}|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function variables(string $key): array
    {
        return self::find($key)['variables'] ?? [];
    }

    /** @return list<string> */
    public static function whatsAppParameters(string $key): array
    {
        return self::find($key)['whatsapp_parameters'] ?? [];
    }

    public static function supports(string $key, NotificationChannel $channel): bool
    {
        return isset(self::find($key)['channels'][$channel->value]);
    }

    /**
     * Representative values used to compute the stored character and segment counts —
     * the length a real message will have, not the length of the placeholder.
     *
     * @return array<string, string>
     */
    public static function sampleValues(string $locale): array
    {
        $bn = $locale === 'bn';
        $short = (string) config('notifications.short_link_base', 'tmbd.co');

        return [
            'code' => '482917',
            'minutes' => '5',
            'name' => $bn ? 'নুসরাত জাহান' : 'Nusrat Jahan',
            'order_number' => 'TMB-26-00482',
            'item_count' => '2',
            'total' => '2450',
            'cod_amount' => '2450',
            'track_url' => $short.'/TMB-26-00482',
            'hotline' => (string) config('notifications.hotline', '09600123456'),
            'store_url' => (string) config('notifications.store_url', 'toymartbd.com'),
            'date_range' => (string) trans('notifications.delivery_window', [], $locale),
            'eta' => (string) trans('notifications.shipping_eta', [], $locale),
            'courier' => $bn ? 'পাঠাও' : 'Pathao',
            'consignment' => 'PT4471920',
            'rider_phone' => '01712345678',
            'date' => '14/11/2026',
            'refund_sentence' => '',
        ];
    }
}
