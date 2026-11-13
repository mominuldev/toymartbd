<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Domain\Ordering\Models\Order;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Phone risk lookup (Figma B · 06 241:36699): OUR score with breakdown and timestamps, the
 * staff list status, and — SEPARATELY labelled and timestamped — each courier's verified
 * record or "not connected" (rules 24, 33b). The two are never combined into one number.
 *
 * @property PhoneRiskView $resource
 */
final class PhoneRiskResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        return [
            'phone' => RiskProfilePresenter::phone($view->phone, $user),
            'pii_masked' => $user?->can('viewPii', Order::class) !== true,
            'ours' => RiskProfilePresenter::ours($view->profile, $view->preview, $view->previewCounts),
            'status' => RiskProfilePresenter::status($view->profile),
            'courier' => [
                'checked_at' => $view->courierCheckedAt,
                /** @var list<array<string, mixed>> */
                'providers' => $view->courier,
            ],
            'recent_orders' => $view->recentOrders->map(static fn (Order $o): array => [
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
