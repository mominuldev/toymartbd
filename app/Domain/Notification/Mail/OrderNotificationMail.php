<?php

declare(strict_types=1);

namespace App\Domain\Notification\Mail;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Locale\Formatter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An order email (P4-NOT-02): order placed, confirmed, shipped, delivered, cancelled, in
 * the customer's locale. Subject, preheader and the intro paragraphs come from the
 * editable template; the order summary is Ordering's own figures, formatted (rule 1).
 *
 * Built from plain arrays, not models, and sent from inside SendNotification — never
 * queued on its own, so the send is logged with its outcome.
 */
final class OrderNotificationMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $paragraphs
     * @param  list<array{name: string, variant: string|null, quantity: int, total: string}>  $items
     * @param  array<string, string>  $totals
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $preheader,
        public readonly array $paragraphs,
        public readonly string $customerName,
        public readonly string $orderNumber,
        public readonly array $items,
        public readonly array $totals,
        public readonly string $trackUrl,
        public readonly string $hotline,
        public readonly string $mailLocale,
    ) {
        $this->locale($mailLocale);
    }

    /** @param  array<string, string>  $variables */
    public static function for(NotificationTemplate $template, Order $order, string $locale, array $variables): self
    {
        $order->loadMissing('items');
        $bn = $locale === 'bn';

        $items = $order->items->map(static fn (OrderItem $item): array => [
            'name' => $bn && $item->product_name_bn !== null && $item->product_name_bn !== '' ? $item->product_name_bn : $item->product_name,
            'variant' => $item->variant_name,
            'quantity' => $item->quantity,
            'total' => '৳'.Formatter::money($item->line_total, $locale),
        ])->values()->all();

        $totals = [
            'subtotal' => '৳'.Formatter::money($order->subtotal, $locale),
            'delivery' => '৳'.Formatter::money($order->shipping_charge, $locale),
            'total' => '৳'.Formatter::money($order->grand_total, $locale),
            'due' => '৳'.Formatter::money($order->due_total, $locale),
        ];
        if (! $order->discount_total->isZero()) {
            $totals['discount'] = '−৳'.Formatter::money($order->discount_total, $locale);
        }

        $body = $template->render($locale, $variables);

        return new self(
            subjectLine: $template->renderSubject($locale, $variables),
            preheader: $template->renderPreheader($locale, $variables),
            paragraphs: array_values(array_filter(array_map(trim(...), explode("\n", $body)), static fn (string $p): bool => $p !== '')),
            customerName: $order->shipping_name,
            orderNumber: $order->number,
            items: $items,
            totals: $totals,
            trackUrl: 'https://'.config('notifications.store_url').($bn ? '/bn' : '').'/track?order='.rawurlencode($order->number),
            hotline: (string) config('notifications.hotline'),
            mailLocale: $locale,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.order-notification', text: 'mail.order-notification-text');
    }
}
