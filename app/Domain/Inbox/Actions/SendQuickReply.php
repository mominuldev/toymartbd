<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inbox\Exceptions\QuickReplyNotAllowed;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Ordering\Models\Order;
use App\Models\User;
use App\Support\StorefrontUrl;

/**
 * The quick actions panel (Figma B · 22): the server composes the text, in the customer's
 * language (`bn` when unknown), and sends it as an ordinary reply through {@see SendReply}
 * — same window rule, same queue.
 *
 *  - `product_card`: the product's name and its storefront link. No price: the price a
 *    customer pays depends on variant and promotions, which the storefront resolves; a
 *    quoted price here could be wrong (rule 1). Messenger and WhatsApp unfurl the link.
 *  - `tracking_link`: the order number and the tracking short link, the same link order
 *    notifications send (config notifications.short_link_base). Only an order of the
 *    conversation's linked customer. Tracking still asks for the phone digits (rule 22).
 */
final readonly class SendQuickReply
{
    public const array KINDS = ['product_card', 'tracking_link'];

    public function __construct(private SendReply $reply) {}

    public function handle(Conversation $conversation, User $by, string $kind, int $targetId): ConversationMessage
    {
        $locale = $conversation->customer?->preferred_language === 'en' ? 'en' : 'bn';

        $body = match ($kind) {
            'product_card' => $this->productCard($targetId, $locale),
            'tracking_link' => $this->trackingLink($conversation, $targetId, $locale),
            default => throw QuickReplyNotAllowed::make('Unknown quick action.'),
        };

        return $this->reply->handle($conversation, $by, $body);
    }

    private function productCard(int $productId, string $locale): string
    {
        $product = Product::query()->find($productId);
        if (! $product instanceof Product || $product->status !== ProductStatus::Active || ! $product->channel_online_store) {
            throw QuickReplyNotAllowed::make(__('inbox.errors.product_not_shareable'));
        }

        $base = rtrim((string) config('inbox.storefront_url'), '/');
        $name = $locale === 'bn' && is_string($product->name_bn) && $product->name_bn !== '' ? $product->name_bn : $product->name;

        return __('inbox.quick.product_card', [
            'name' => $name,
            'url' => StorefrontUrl::url($base, $locale, '/products/'.$product->slug),
        ], $locale);
    }

    private function trackingLink(Conversation $conversation, int $orderId, string $locale): string
    {
        $order = $conversation->customer_id === null ? null : Order::query()->where('customer_id', $conversation->customer_id)->find($orderId);
        if (! $order instanceof Order) {
            throw QuickReplyNotAllowed::make(__('inbox.errors.order_not_shareable'));
        }

        $base = config('notifications.short_link_base', 'tmbd.co');

        return __('inbox.quick.tracking_link', [
            'number' => $order->number,
            'url' => (is_string($base) ? $base : 'tmbd.co').'/'.$order->number,
        ], $locale);
    }
}
