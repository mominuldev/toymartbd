<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Models\Order;
use App\Http\Resources\ApiResource;
use App\Models\User;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Phone lookup on the create-order screen (P4-ORD-06): who this number is, their saved
 * addresses, and any order still open (so staff can add to it instead of charging a
 * second delivery). `customer` is null for a number the shop has never seen — neutral,
 * not safe. Street line and landmark only with customers.view_pii (rule 20).
 *
 * @property array{phone: PhoneNumber, customer: Customer|null, addresses: Collection<int, Address>, open_orders: Collection<int, Order>} $resource
 */
#[SchemaName('OrderComposerCustomer')]
final class OrderComposerCustomerResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $pii = $user instanceof User && $user->can('viewPii', Order::class);
        $customer = $this->resource['customer'];

        return [
            /** E.164 — the number as it will be stored. */
            'phone' => $this->resource['phone']->e164(),
            'pii_masked' => ! $pii,
            'customer' => $customer === null ? null : [
                /** @var int */
                'id' => $customer->id,
                'name' => $customer->name,
                /** @var 'en'|'bn' */
                'preferred_language' => $customer->preferred_language,
                /** @var int */
                'orders_count' => $customer->orders_count,
                /** @var int */
                'delivered_orders_count' => $customer->delivered_orders_count,
                'total_spent' => $customer->total_spent->toDecimal(),
                /** @var string|null */
                'last_order_at' => $customer->last_order_at?->toIso8601String(),
            ],
            /** @var list<array{id: int, recipient_name: string, division_id: int, district_id: int, upazila_id: int|null, division: string, district: string, upazila: string|null, area: string|null, address_line: string|null, landmark: string|null, is_default: bool}> */
            'addresses' => $this->resource['addresses']->map(static fn (Address $a): array => [
                'id' => $a->id,
                'recipient_name' => $a->recipient_name,
                'division_id' => $a->division_id,
                'district_id' => $a->district_id,
                /** @var int|null */
                'upazila_id' => $a->upazila_id,
                'division' => $a->division->name,
                'district' => $a->district->name,
                /** @var string|null */
                'upazila' => $a->upazila?->name,
                /** @var string|null */
                'area' => $a->area,
                /** @var string|null */
                'address_line' => $pii ? $a->address_line : null,
                /** @var string|null */
                'landmark' => $pii ? $a->landmark : null,
                'is_default' => $a->is_default_shipping,
            ])->values()->all(),
            /** @var list<array{id: int, number: string, status: string, grand_total: string, placed_at: string|null}> */
            'open_orders' => $this->resource['open_orders']->map(static fn (Order $o): array => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status->value,
                'grand_total' => $o->grand_total->toDecimal(),
                /** @var string|null */
                'placed_at' => $o->placed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
