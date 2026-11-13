<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Services\CourierHistoryLookup;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Actions\RefreshRiskProfile;
use App\Domain\Risk\Actions\SetPhoneRiskStatus;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Services\PhoneHistoryReader;
use App\Domain\Risk\Services\RiskScorer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Risk\PhoneLookupRequest;
use App\Http\Requests\Admin\Risk\SetPhoneStatusRequest;
use App\Http\Resources\Admin\Risk\PhoneRiskResource;
use App\Http\Resources\Admin\Risk\PhoneRiskView;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phone risk lookup (Figma B · 06 241:36699) and list management. Our score and the courier
 * panel come back as separate blocks with their own timestamps (rule 24); a courier that is
 * not connected says so — never a zero (rule 33b).
 */
final class RiskPhoneController extends ApiController
{
    /**
     * `GET /risk/lookup?phone=…` — needs risk.view. A phone with no stored profile shows a
     * score computed now from its history (`ours.stored = false`); nothing is written.
     */
    public function lookup(PhoneLookupRequest $request, CourierHistoryLookup $courier, PhoneHistoryReader $history, RiskScorer $scorer): PhoneRiskResource
    {
        return new PhoneRiskResource(self::view($request->phone()->e164(), $courier, $history, $scorer));
    }

    /** `POST /risk/lookup/rescore` — rebuild and store the phone's profile now. Needs risk.verify. */
    public function rescore(PhoneLookupRequest $request, RefreshRiskProfile $refresh, CourierHistoryLookup $courier, PhoneHistoryReader $history, RiskScorer $scorer): PhoneRiskResource
    {
        $e164 = $request->phone()->e164();
        $refresh->handle($e164);

        return new PhoneRiskResource(self::view($e164, $courier, $history, $scorer));
    }

    /**
     * `PUT /risk/phone-status` — watch / blacklist / whitelist / normal, with a reason (who and
     * when are recorded). A blacklisted phone's orders are HELD, never cancelled. Needs
     * risk.manage_lists.
     */
    public function setStatus(SetPhoneStatusRequest $request, SetPhoneRiskStatus $set, CourierHistoryLookup $courier, PhoneHistoryReader $history, RiskScorer $scorer): PhoneRiskResource
    {
        $e164 = $request->phone()->e164();
        $set->handle($e164, $request->status(), $request->reason(), $request->user()?->id);

        return new PhoneRiskResource(self::view($e164, $courier, $history, $scorer));
    }

    public static function view(string $e164, CourierHistoryLookup $courier, PhoneHistoryReader $history, RiskScorer $scorer): PhoneRiskView
    {
        $profile = CustomerRiskProfile::forPhone($e164);
        $preview = null;
        $counts = null;
        if ($profile === null) {
            $h = $history->forPhone($e164);
            $preview = $scorer->score($h);
            $counts = [
                'total_orders' => $h->totalOrders, 'delivered' => $h->deliveredCount, 'cancelled' => $h->cancelledCount,
                'returned' => $h->returnedCount, 'failed_delivery' => $h->failedDeliveryCount, 'refused' => $h->refusedCount,
            ];
        }

        $recent = Order::query()
            ->where(static fn (Builder $q) => $q->where('shipping_phone', $e164)->orWhereHas('customer', static fn (Builder $c) => $c->where('phone', $e164)))
            ->orderByDesc('placed_at')->orderByDesc('id')->limit(10)->get();

        return new PhoneRiskView($e164, $profile, $preview, $counts, $courier->forPhone($e164), now()->toIso8601String(), $recent);
    }
}
