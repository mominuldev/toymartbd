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

            // The same message when no rider number is recorded on the shipment (owner decision
            // 2026-11-13): the rider line is dropped — never our hotline in its place, never a
            // literal {rider_phone}. Neither courier's API returns the rider's number, so this is
            // what goes out unless staff record the rider before the courier marks the parcel out
            // for delivery. The WhatsApp body is its own Meta template and needs its own approval.
            'order.out_for_delivery.no_rider' => [
                'category' => NotificationCategory::OrderUpdates,
                'variables' => ['name', 'order_number', 'cod_amount'],
                'whatsapp_parameters' => ['order_number', 'cod_amount'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: order {order_number} is out for delivery today. Please keep BDT {cod_amount} ready.',
                        // "অর্ডার" dropped so the Bangla fits ONE UCS-2 segment (66 of 70 with the
                        // sample; 05-content-and-assets-spec A.17.1 `.short` does the same).
                        'body_bn' => 'টয় মার্ট বিডি: {order_number} আজ ডেলিভারি হবে। BDT {cod_amount} হাতে রাখুন।',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'order_out_for_delivery_no_rider',
                        'body_en' => 'Order *{order_number}* is out for delivery today. Please keep BDT {cod_amount} ready. If nobody will be home, reply here and we will reschedule.',
                        'body_bn' => 'অর্ডার *{order_number}* আজ ডেলিভারি হবে। BDT {cod_amount} হাতে রাখুন। বাসায় কেউ না থাকলে এখানে উত্তর দিন, আমরা অন্যদিন পাঠাব।',
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
            // Abandoned-cart reminder (P7). Marketing: sent only with consent, at most once per
            // cart, SMS only. The link restores the cart server-side and carries no PII.
            'cart.recovery' => [
                'category' => NotificationCategory::Marketing,
                'variables' => ['name', 'item_count', 'restore_url', 'store_url'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: your cart with {item_count} items is saved. Finish your order here: {restore_url}',
                        'body_bn' => 'টয় মার্ট বিডি: আপনার কার্টে {item_count}টি পণ্য রাখা আছে। অর্ডার শেষ করুন: {restore_url}',
                    ],
                ],
            ],

            // Review request (P8 automation): N days after delivery, once per order. Marketing
            // (opt-in only, NotificationCategory). The WhatsApp body is its own Meta template,
            // not submitted yet — no WhatsApp Business account exists. Links to the PDP's
            // "Write a review" form.
            'review.request' => [
                'category' => NotificationCategory::Marketing,
                'variables' => ['name', 'order_number', 'review_url', 'store_url'],
                'whatsapp_parameters' => ['name', 'order_number', 'review_url'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: how are the toys from order {order_number}? Please rate them: {review_url}',
                        'body_bn' => 'টয় মার্ট বিডি: অর্ডার {order_number} এর খেলনা কেমন লাগল? রিভিউ দিন: {review_url}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'review_request',
                        'body_en' => 'Hello {name}, we hope the toys from order *{order_number}* are a hit. Would you rate them? It takes a minute and helps other parents choose: {review_url}',
                        'body_bn' => '{name}, আশা করি অর্ডার *{order_number}* এর খেলনাগুলো ভালো লেগেছে। একটা রিভিউ দেবেন? এক মিনিট লাগবে, আর অন্য বাবা-মায়েদের বেছে নিতে সাহায্য করবে: {review_url}',
                    ],
                ],
            ],

            // Birthday reminder (P8 batch 3, owner decision 2026-11-13): to the PARENT, `lead_days`
            // (default 7) before each child's birthday, from 10:00 Dhaka outside quiet hours, once
            // per child per year, only with the birthday consent. Marketing. `offer_sentence` holds
            // the personal coupon line (lang notifications.birthday.offer_sentence) or is empty while
            // the offer is off — WhatsApp then gets a non-empty line instead (Meta refuses an empty
            // parameter). `coupon_code` / `coupon_expiry` are there for edited copy; empty without
            // an offer. Copy adapted from the approved batch-2 text — NEW sentences for owner review.
            'customer.birthday' => [
                'category' => NotificationCategory::Marketing,
                'variables' => ['name', 'child_possessive', 'birthday_date', 'offer_sentence', 'coupon_code', 'coupon_expiry', 'store_url'],
                'whatsapp_parameters' => ['name', 'child_possessive', 'birthday_date', 'offer_sentence'],
                'channels' => [
                    'sms' => [
                        'body_en' => 'Toy Mart BD: {child_possessive} birthday is on {birthday_date}! {offer_sentence} Gifts: {store_url}',
                        'body_bn' => 'টয় মার্ট বিডি: {birthday_date} {child_possessive} জন্মদিন! {offer_sentence} {store_url}',
                    ],
                    'whatsapp' => [
                        'provider_template' => 'child_birthday_reminder',
                        'body_en' => 'Hello {name}, {child_possessive} birthday is coming up on {birthday_date}. {offer_sentence} Find a gift they will love: {store_url}',
                        'body_bn' => '{name}, {birthday_date} {child_possessive} জন্মদিন আসছে। {offer_sentence} পছন্দের উপহারটি খুঁজে নিন: {store_url}',
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
            // Birthday reminder (P8 batch 3): the longest realistic case — a named child and an offer.
            'child_possessive' => $bn ? 'আয়ান-এর' : "Ayaan's",
            'birthday_date' => $bn ? '12 মার্চ 2027' : '12 Mar 2027',
            'coupon_code' => 'BDAY-7KQ2MXRT',
            'coupon_expiry' => $bn ? '19 মার্চ 2027' : '19 Mar 2027',
            'offer_sentence' => (string) trans('notifications.birthday.offer_sentence', [
                'code' => 'BDAY-7KQ2MXRT',
                'offer' => trans('notifications.birthday.offer_fixed', ['value' => '1000'], $locale),
                'expiry' => $bn ? '19 মার্চ 2027' : '19 Mar 2027',
            ], $locale),
            // A real restore link is 24 random characters under the storefront origin.
            // A review link: the storefront PDP of a typical product slug, `#reviews`.
            'review_url' => rtrim((string) (config('services.storefront.url') ?: config('cart_recovery.storefront_url', 'https://toymartbd.com')), '/').($bn ? '/bn' : '').'/products/remote-control-racing-car-red#reviews',
            'restore_url' => rtrim((string) config('cart_recovery.storefront_url', 'https://toymartbd.com'), '/').($bn ? '/bn' : '').'/cart/restore/'.str_repeat('x', 24),
        ];
    }
}
