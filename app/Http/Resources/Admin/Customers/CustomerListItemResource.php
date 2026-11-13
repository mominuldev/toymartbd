<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Customers;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\CustomerPhoneMask;
use App\Domain\Reporting\Queries\Customers\CustomerLedgerValues;
use App\Domain\Risk\Services\RiskSettings;
use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A row of the admin Customers list (Figma B · 17 241:40845). The phone is ALWAYS masked
 * (`01712•••318`) — a list never carries a full number, whatever the role (rule 20).
 * `lifetime_value` is delivered revenue from the ledger, net of discounts and refunds,
 * excluding delivery charges — not placed value. `risk` is OUR score and the staff list
 * status (rule 24); null without risk.view.
 *
 * @property Customer $resource
 */
#[SchemaName('CustomerListItem')]
final class CustomerListItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $user = $request->user();
        $score = $c->getAttribute('risk_score_value');
        $status = $c->getAttribute('risk_status_value');
        $district = $c->getAttribute('district_value');

        return [
            'id' => $c->id,
            'name' => $c->name,
            /** @var string `01712•••318` */
            'phone_masked' => CustomerPhoneMask::mask($c->phone),
            /** A storefront account: the phone was verified by OTP at least once. */
            'has_account' => $c->phone_verified_at !== null,
            /** @var 'web'|'facebook'|'phone'|'walk_in'|'whatsapp' */
            'source' => $c->source->value,
            /** @var list<string> */
            'tags' => $c->tags ?? [],
            /** @var string|null Latest order's district name (as entered on the order). */
            'district' => is_string($district) ? $district : null,
            /** Orders placed, cancelled excluded. */
            'orders_count' => $c->orders_count,
            'delivered_orders_count' => $c->delivered_orders_count,
            /** Delivered revenue from the ledger (order_financials), decimal string. */
            'lifetime_value' => CustomerLedgerValues::money($c->getAttribute('ltv_value'))->toDecimal(),
            /** @var 'no_orders'|'lapsed'|'at_risk'|'vip'|'new'|'active' */
            'segment' => (string) $c->getAttribute('segment_value'),
            /** @var array{score: int, level: 'low'|'medium'|'high', status: 'normal'|'watch'|'blacklist'|'whitelist'}|null */
            'risk' => $user instanceof User && $user->checkPermissionTo(Permission::RiskView->value) && $score !== null
                ? ['score' => (int) $score, 'level' => app(RiskSettings::class)->level((int) $score), 'status' => is_string($status) ? $status : 'normal']
                : null,
            /** @var string|null */
            'last_order_at' => $c->last_order_at?->toIso8601String(),
            /** @var string|null */
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
