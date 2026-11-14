<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inbox;

use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Risk\Services\RiskSettings;
use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use App\Models\User;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A thread's header and its customer panel (Figma B · 22 right column). Everything is
 * computed here, server-side (rule 1):
 *
 *  - `customer` — null when no customer is linked, or without customers.view.
 *  - `orders_count` (cancelled excluded) and `delivered_orders_count` from the customer
 *    aggregates; `lifetime_value` = delivered revenue from the ledger, net of refunds.
 *  - `risk` — OUR score (`source: ours`) and its timestamp, never courier data (rule 24);
 *    null without risk.view or with no profile yet.
 *  - `last_order` — the latest order with its status and a one-line item summary.
 *
 * @property InboxConversationDetail $resource
 */
#[SchemaName('InboxConversationDetail')]
final class InboxConversationDetailResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;
        $user = $request->user();
        $can = static fn (Permission $p): bool => $user instanceof User && $user->checkPermissionTo($p->value);
        $customer = $can(Permission::CustomersView) ? $d->customer : null;
        $order = $customer === null ? null : $d->lastOrder;

        return [
            ...(new InboxConversationResource($d->conversation))->toArray($request),
            /** @var array{id: int, name: string, orders_count: int, delivered_orders_count: int, lifetime_value: string, preferred_language: string, risk: array{score: int, level: 'low'|'medium'|'high', source: 'ours', computed_at: string|null}|null, last_order: array{id: int, number: string, status: string, grand_total: string, placed_at: string|null, items_summary: string|null, items_count: int}|null}|null */
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->name,
                'orders_count' => $customer->orders_count,
                'delivered_orders_count' => $customer->delivered_orders_count,
                'lifetime_value' => ($d->lifetimeValue ?? Money::zero())->toDecimal(),
                'preferred_language' => $customer->preferred_language,
                'risk' => $can(Permission::RiskView) && $d->risk !== null ? [
                    'score' => $d->risk->internal_score,
                    'level' => app(RiskSettings::class)->level($d->risk->internal_score),
                    'source' => 'ours',
                    'computed_at' => $d->risk->score_computed_at?->toIso8601String(),
                ] : null,
                'last_order' => $order === null ? null : [
                    'id' => $order->id,
                    'number' => $order->number,
                    'status' => $order->status->value,
                    'grand_total' => $order->grand_total->toDecimal(),
                    'placed_at' => $order->created_at?->toIso8601String(),
                    'items_summary' => self::itemsSummary($order->items->all()),
                    'items_count' => $order->items->count(),
                ],
            ],
        ];
    }

    /** @param  list<OrderItem>  $items  "Baby Activity Gym Mat × 1" (+ "+2 more" is the UI's job) */
    private static function itemsSummary(array $items): ?string
    {
        $first = $items[0] ?? null;

        return $first === null ? null : $first->product_name.' × '.$first->quantity;
    }
}
