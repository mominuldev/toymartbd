<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Enums\SettlementReview;
use App\Domain\Finance\Enums\SettlementStatus;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\CourierSettlementItem;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A courier COD settlement (Figma B · 09 241:35325): header totals as the courier reported
 * them, the review state (`disputed` while a variance line has no decision) and — on the
 * detail (`items` loaded) — every line beside what our shipment expected.
 *
 * @property CourierSettlement $resource
 */
final class CourierSettlementResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $provider = DB::table('courier_providers')->where('id', $s->provider_id)->first(['code', 'name', 'name_bn']);
        $unresolved = $s->status === SettlementStatus::Reconciled ? 0 : $s->unresolvedCount();
        $name = static fn (?int $id): ?string => $id === null ? null : (is_string($n = User::query()->whereKey($id)->value('name')) ? $n : null);

        return [
            'id' => $s->id,
            'provider' => [
                'code' => is_object($provider) ? (string) $provider->code : '',
                'name' => is_object($provider) ? (string) $provider->name : '',
                /** @var string|null */
                'name_bn' => is_object($provider) && is_string($provider->name_bn) ? $provider->name_bn : null,
            ],
            'reference' => $s->reference,
            /** @var string|null */
            'period_start' => $s->period_start?->toDateString(),
            /** @var string|null */
            'period_end' => $s->period_end?->toDateString(),
            'received_at' => $s->received_at->toDateString(),
            'totals' => [
                'cod_collected' => $s->cod_collected->toDecimal(),
                'delivery_charges' => $s->delivery_charges->toDecimal(),
                'cod_fees' => $s->cod_fees->toDecimal(),
                'return_charges' => $s->return_charges->toDecimal(),
                'net_received' => $s->net_received->toDecimal(),
            ],
            /** `received` | `disputed` (unresolved variance lines) | `reconciled` */
            'status' => $s->status === SettlementStatus::Reconciled ? 'reconciled' : ($unresolved > 0 ? 'disputed' : 'received'),
            'source' => $s->source,
            'variance_count' => $s->variance_count,
            'unresolved_count' => $unresolved,
            /** @var string|null */
            'note' => $s->note,
            /** @var string|null */
            'imported_by' => $name($s->imported_by),
            /** @var string|null */
            'confirmed_by' => $name($s->confirmed_by),
            /** @var string|null */
            'confirmed_at' => $s->confirmed_at?->toIso8601String(),
            /** @var string|null */
            'created_at' => $s->created_at?->toIso8601String(),
            'can_reconcile' => $request->user()?->can('reconcile', CourierSettlement::class) === true,
            'items' => $this->whenLoaded('items', fn (): array => $s->items->map(static function (CourierSettlementItem $i): array {
                $orderNumber = $i->order_id !== null ? DB::table('orders')->where('id', $i->order_id)->value('number') : null;

                return [
                    'id' => $i->id,
                    /** @var int|null */
                    'line_no' => $i->line_no,
                    /** @var string|null */
                    'consignment_id' => $i->consignment_id,
                    /** @var int|null */
                    'shipment_id' => $i->shipment_id,
                    /** @var int|null */
                    'order_id' => $i->order_id,
                    /** @var string|null */
                    'order_number' => is_string($orderNumber) ? $orderNumber : null,
                    'cod_amount' => $i->cod_amount->toDecimal(),
                    'charges' => $i->charges->toDecimal(),
                    'cod_fee' => $i->cod_fee->toDecimal(),
                    'return_charge' => $i->return_charge->toDecimal(),
                    'net' => $i->net->toDecimal(),
                    /** @var string|null */
                    'expected_cod' => $i->expected_cod?->toDecimal(),
                    /** @var string|null */
                    'expected_net' => $i->expected_net?->toDecimal(),
                    'variance' => $i->variance->toDecimal(),
                    'variance_type' => $i->variance_type->value,
                    'acceptable' => $i->variance_type->acceptable(),
                    'is_matched' => $i->is_matched,
                    'review_status' => $i->review_status->value,
                    /** @var string|null */
                    'review_note' => $i->review_note,
                    'needs_review' => $i->variance_type !== SettlementVariance::None && $i->review_status === SettlementReview::Pending,
                    'booked' => $i->booked_at !== null,
                ];
            })->values()->all()),
        ];
    }
}
