<?php

declare(strict_types=1);

namespace App\Domain\Ai\Inbox;

use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Ordering\Models\Order;
use App\Support\DhakaDate;
use App\Support\Money;
use App\Support\StorefrontUrl;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What an inbox draft may know (Figma 539:83211) — chosen field by field, so nothing else can
 * leak (rule 20):
 *
 *  - the last {@see self::MESSAGES} customer and shop messages, text only (no internal notes,
 *    no system lines; {@see \App\Domain\Ai\Services\AiClient} redacts phones and emails again);
 *  - the customer's FIRST name, only when Integrations › AI › sharing allows names;
 *  - for the conversation's linked customer's orders (the {@see self::ORDERS} most recent, plus
 *    any of their orders the thread names): number, status, courier, latest tracking status and
 *    time, the delivery district's name and the COD amount as a display string.
 *
 * Never the phone number, address lines, landmark, payment method details or any other
 * customer's order. Order facts need customers.view, like the inbox's customer panel.
 */
final class InboxReplyContext
{
    public const int MESSAGES = 10;

    public const int ORDERS = 3;

    /**
     * @param  array{customer_first_name: string|null, language: 'bn'|'en', today: string, track_url: string, orders: list<array<string, string|null>>, messages: list<array{from: string, text: string}>}  $facts
     * @param  list<string>  $basedOn
     */
    public function __construct(
        public readonly array $facts,
        public readonly array $basedOn,
        public readonly string $customerLanguage,
    ) {}

    public static function for(Conversation $conversation, bool $shareNames, bool $includeOrders): self
    {
        $messages = $conversation->messages()
            ->whereIn('kind', [MessageKind::Text->value, MessageKind::Template->value])
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(self::MESSAGES)
            ->get(['id', 'direction', 'body'])
            ->reverse()
            ->values();

        $lines = [];
        $lastInbound = null;
        foreach ($messages as $m) {
            /** @var ConversationMessage $m */
            $text = trim((string) $m->body);
            if ($text === '') {
                continue;
            }
            $inbound = $m->direction === MessageDirection::In;
            $lines[] = ['from' => $inbound ? 'customer' : 'shop', 'text' => mb_substr($text, 0, 1000)];
            if ($inbound) {
                $lastInbound = $text;
            }
        }

        $language = $lastInbound !== null ? self::detect($lastInbound) : $conversation->locale();
        $locale = app()->getLocale() === 'bn' ? 'bn' : 'en';

        $orders = [];
        $basedOn = [];
        if ($includeOrders && $conversation->customer_id !== null) {
            foreach (self::orders($conversation->customer_id, array_column($lines, 'text')) as $order) {
                [$fact, $chips] = self::orderFact($order, $locale);
                $orders[] = $fact;
                array_push($basedOn, ...$chips);
            }
        }

        $now = CarbonImmutable::now(DhakaDate::zone());

        return new self([
            'customer_first_name' => $shareNames ? self::firstName($conversation) : null,
            'language' => $language,
            'today' => $now->format('l j F Y, g:i a'),
            'track_url' => self::trackUrl($language),
            'orders' => $orders,
            'messages' => $lines,
        ], array_values(array_unique($basedOn)), $language);
    }

    /** Bangla script anywhere in the customer's last message → Bangla; otherwise English. */
    public static function detect(string $text): string
    {
        return preg_match('/\p{Bengali}/u', $text) === 1 ? 'bn' : 'en';
    }

    /** The public tracking page, as text (the customer still enters their phone digits, rule 22). Bangla at the root, English under `/en`. */
    public static function trackUrl(string $language): string
    {
        $base = config('inbox.storefront_url', 'https://smartjunior.com.bd');
        $host = preg_replace('#^https?://#i', '', rtrim(is_string($base) ? $base : 'https://smartjunior.com.bd', '/'));

        return StorefrontUrl::url($host, $language, '/track');
    }

    private static function firstName(Conversation $conversation): ?string
    {
        $name = $conversation->customer !== null ? $conversation->customer->name : $conversation->contact_name;
        $first = trim(explode(' ', trim((string) $name))[0]);

        // A "name" that is digits or an email is not a name.
        return $first === '' || preg_match('/[\d@]/', $first) === 1 ? null : mb_substr($first, 0, 40);
    }

    /**
     * The customer's most recent orders plus any of theirs the thread names. Only this
     * customer's orders — an order number typed by someone else never resolves.
     *
     * @param  list<string>  $texts
     * @return list<Order>
     */
    private static function orders(int $customerId, array $texts): array
    {
        preg_match_all('/\b[A-Z]{2,4}-\d{2}-\d{3,6}\b/u', implode("\n", $texts), $m);
        $named = array_values(array_unique($m[0]));

        $recent = Order::query()->where('customer_id', $customerId)->orderByDesc('created_at')->orderByDesc('id')->limit(self::ORDERS)->get();
        $mentioned = $named === [] ? collect() : Order::query()->where('customer_id', $customerId)->whereIn('number', $named)->get();

        return $mentioned->concat($recent)->unique('id')->take(self::ORDERS + 2)->values()->all();
    }

    /** @return array{0: array<string, string|null>, 1: list<string>} */
    private static function orderFact(Order $order, string $locale): array
    {
        $shipment = Shipment::query()->with('provider')->where('order_id', $order->id)->open()->orderByDesc('id')->first();
        $event = $shipment === null ? null : CourierTrackingEvent::query()->where('shipment_id', $shipment->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $trackedAt = $event !== null ? $event->occurred_at : $shipment?->updated_at;
        $shipmentStatus = $event !== null ? $event->normalised_status->value : $shipment?->status->value;
        $courier = $shipment?->provider->name;

        $cod = null;
        if ($order->payment_method === 'cod') {
            $amount = $shipment !== null ? $shipment->cod_amount : $order->due_total;
            $cod = $amount->isZero() ? null : self::taka($amount);
        }

        $fact = [
            'order_number' => $order->number,
            'order_status' => str_replace('_', ' ', $order->status->value),
            'placed_on' => $order->placed_at !== null ? self::dhaka($order->placed_at)->format('l j F Y') : null,
            'courier' => $courier,
            'tracking_status' => $shipmentStatus !== null ? str_replace('_', ' ', $shipmentStatus) : null,
            'tracking_updated' => $trackedAt !== null ? self::dhaka($trackedAt)->format('l j F Y, g:i a') : null,
            'delivered_on' => $order->delivered_at !== null ? self::dhaka($order->delivered_at)->format('l j F Y') : null,
            'delivery_district' => $order->shipping_district,
            'cod_amount' => $cod,
        ];

        $chips = [__('ai_inbox.based_on.order', ['number' => $order->number], $locale)];
        if ($shipmentStatus !== null && $courier !== null) {
            $status = __('ai_inbox.shipment.'.$shipmentStatus, [], $locale);
            $chips[] = $trackedAt !== null
                ? __('ai_inbox.based_on.tracking', ['courier' => $courier, 'status' => $status, 'ago' => self::ago($trackedAt, $locale)], $locale)
                : __('ai_inbox.based_on.tracking_no_time', ['courier' => $courier, 'status' => $status], $locale);
        } else {
            $chips[] = __('ai_inbox.based_on.status_only', ['status' => __('ai_inbox.order.'.$order->status->value, [], $locale)], $locale);
        }

        return [$fact, array_map(static fn (mixed $s): string => is_string($s) ? $s : '', $chips)];
    }

    /** `৳6,260` — a display string, never a number the model can do arithmetic on. */
    private static function taka(Money $amount): string
    {
        $decimal = $amount->toDecimal();
        $whole = str_ends_with($decimal, '.00');

        return '৳'.number_format((float) $decimal, $whole ? 0 : 2, '.', ',');
    }

    private static function dhaka(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(DhakaDate::zone());
    }

    /** Western digits in both languages (rule 36) — Carbon's bn locale would print Bengali ones. */
    private static function ago(DateTimeInterface $at, string $locale): string
    {
        $minutes = max(0, (int) floor((now()->getTimestamp() - $at->getTimestamp()) / 60));
        $out = match (true) {
            $minutes < 1 => __('ai_inbox.ago.now', [], $locale),
            $minutes < 60 => __('ai_inbox.ago.minutes', ['n' => $minutes], $locale),
            $minutes < 48 * 60 => __('ai_inbox.ago.hours', ['n' => intdiv($minutes, 60)], $locale),
            default => __('ai_inbox.ago.days', ['n' => intdiv($minutes, 1440)], $locale),
        };

        return is_string($out) ? $out : '';
    }
}
