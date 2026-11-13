<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Customers;

use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Queries\EloquentCustomerOrderHistory;
use App\Http\Resources\Admin\Risk\RiskProfilePresenter;
use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use App\Models\User;
use App\Support\Money;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Admin customer profile (Figma B · 18 241:41374; checklist §2.8). Role-based PII masking
 * (rule 20), as on the order detail: without customers.view_pii the phone and e-mail are
 * masked and each address shows only its area, upazila and district — no recipient, street
 * line, landmark or postcode. `pii_masked` tells the client which it got.
 *
 * Money (decimal strings): `lifetime_value` is DELIVERED revenue from the ledger
 * (order_financials: line totals after discounts, net of refunds, excluding delivery charges);
 * `average_order_value` = lifetime_value ÷ delivered orders. Never placed value.
 * `risk` is OUR score and the staff list status, kept as separate blocks (rule 24) — null
 * without risk.view; the courier-verified record lives on the phone risk lookup.
 *
 * @property CustomerProfile $resource
 */
#[SchemaName('CustomerProfile')]
final class CustomerProfileResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $c = $p->customer;
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $pii = $user?->can('viewPii', Customer::class) === true;
        $canRisk = $user?->checkPermissionTo(Permission::RiskView->value) === true;

        $delivered = 0;
        foreach (EloquentCustomerOrderHistory::DELIVERED as $status) {
            $delivered += $p->statusCounts[$status->value] ?? 0;
        }
        $returned = $p->statusCounts[OrderStatus::Returned->value] ?? 0;
        $cancelled = $p->statusCounts[OrderStatus::Cancelled->value] ?? 0;
        $placed = array_sum($p->statusCounts) - $cancelled;
        $ltv = $p->ledger['lifetime_value'];

        return [
            'id' => $c->id,
            'name' => $c->name,
            'pii_masked' => ! $pii,
            /** E.164 with customers.view_pii, otherwise `017****5678`. */
            'phone' => $pii ? $c->phone : $c->maskedPhone(),
            /** @var string|null Full with customers.view_pii, otherwise `j***@gmail.com`. */
            'email' => $c->email === null ? null : ($pii ? $c->email : self::maskEmail($c->email)),
            'has_account' => $c->phone_verified_at !== null,
            /** @var 'bn'|'en' */
            'preferred_language' => $c->preferred_language,
            /** @var 'web'|'facebook'|'phone'|'walk_in'|'whatsapp' */
            'source' => $c->source->value,
            /** @var 'active'|'blocked' */
            'status' => $c->status->value,
            /** @var list<string> */
            'tags' => $c->tags ?? [],
            /** @var 'no_orders'|'lapsed'|'at_risk'|'vip'|'new'|'active' */
            'segment' => $p->segment,
            /** @var string|null */
            'created_at' => $c->created_at?->toIso8601String(),
            /** @var string|null */
            'first_order_at' => $c->first_order_at?->toIso8601String(),
            /** @var string|null */
            'last_order_at' => $c->last_order_at?->toIso8601String(),
            'stats' => [
                /** @var int Orders placed, cancelled excluded. */
                'orders_count' => $placed,
                /** @var int Delivered, completed or partially returned. */
                'delivered_orders_count' => $delivered,
                /** @var int */
                'returned_orders_count' => $returned,
                /** @var int */
                'cancelled_orders_count' => $cancelled,
                'lifetime_value' => $ltv->toDecimal(),
                'delivered_revenue' => $p->ledger['delivered_revenue']->toDecimal(),
                'refunds' => $p->ledger['refunds']->toDecimal(),
                /** @var string|null lifetime_value ÷ delivered orders; null before a delivery. */
                'average_order_value' => $delivered === 0 ? null : Money::of(bcdiv($ltv->toDecimal(), (string) $delivered, 6))->toDecimal(),
                /** @var string|null delivered ÷ (delivered + returned) × 100, two places; null with neither. */
                'delivery_success_rate' => $delivered + $returned === 0 ? null : bcadd(bcdiv(bcmul((string) $delivered, '100', 6), (string) ($delivered + $returned), 6), '0.005', 2),
            ],
            /** @var list<array{id: int, label: string|null, type: string, is_default_shipping: bool, recipient_name: string|null, phone: string, area: string|null, upazila: string|null, district: string|null, district_bn: string|null, division: string|null, address_line: string|null, landmark: string|null, postcode: string|null}> */
            'addresses' => $c->addresses->sortByDesc('is_default_shipping')->values()->map(static fn (Address $a): array => [
                'id' => $a->id,
                'label' => $a->label,
                'type' => $a->type->value,
                'is_default_shipping' => $a->is_default_shipping,
                'recipient_name' => $pii ? $a->recipient_name : null,
                'phone' => $pii ? $a->phone : (PhoneNumber::tryParse($a->phone)?->masked() ?? '***'),
                'area' => $a->area,
                'upazila' => $a->upazila?->name,
                'district' => $a->district->name,
                'district_bn' => $a->district->name_bn,
                'division' => $a->division->name,
                'address_line' => $pii ? $a->address_line : null,
                'landmark' => $pii ? $a->landmark : null,
                'postcode' => $pii ? $a->postcode : null,
            ])->all(),
            /** @var list<array{id: int, number: string, status: string, payment_status: string, grand_total: string, placed_at: string|null, delivered_at: string|null, items_count: int, items: list<array{name: string, name_bn: string|null, variant: string|null, quantity: int}>}> */
            'orders' => $p->orders->map(static fn (Order $o): array => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status->value,
                'payment_status' => $o->payment_status->value,
                'grand_total' => $o->grand_total->toDecimal(),
                'placed_at' => ($o->placed_at ?? $o->created_at)?->toIso8601String(),
                'delivered_at' => $o->delivered_at?->toIso8601String(),
                'items_count' => $o->items->count(),
                'items' => $o->items->take(3)->map(static fn (OrderItem $i): array => [
                    'name' => $i->product_name,
                    'name_bn' => $i->product_name_bn,
                    'variant' => $i->variant_name,
                    'quantity' => $i->quantity,
                ])->values()->all(),
            ])->values()->all(),
            /** @var bool True when there are more orders than `orders` lists (open the order board filtered by customer). */
            'orders_truncated' => $placed + $cancelled > $p->orders->count(),
            /**
             * @var array{ours: array{stored: bool, internal_score: int, level: 'low'|'medium'|'high', breakdown: list<array{code: string, weight: int, value: string, contribution: int}>, score_computed_at: string|null, counts: array{total_orders: int, delivered: int, cancelled: int, returned: int, failed_delivery: int, refused: int}, last_order_at: string|null}, status: array{value: 'normal'|'watch'|'blacklist'|'whitelist', reason: string|null, set_by: string|null, set_at: string|null}}|null
             */
            'risk' => $canRisk ? [
                'ours' => RiskProfilePresenter::ours($p->risk),
                'status' => RiskProfilePresenter::status($p->risk),
            ] : null,
            /** @var int */
            'notes_count' => $p->notesCount,
            /** @var array{id: int, body: string, is_pinned: bool, user_id: int|null, user_name: string|null, created_at: string|null}|null */
            'pinned_note' => $p->pinnedNote === null ? null : (new CustomerNoteResource($p->pinnedNote))->toArray($request),
        ];
    }

    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
