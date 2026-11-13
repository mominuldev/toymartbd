<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Fulfilment\Events\ShipmentStatusChanged;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderStatusTransition;
use App\Domain\Risk\Listeners\RefreshProfileOnOrderChange;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Models\RiskVerificationCall;
use App\Policies\CustomerRiskProfilePolicy;
use App\Policies\RiskVerificationCallPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Risk wiring (Phase 6): phone profiles rebuilt from order events, and the policies of the
 * verification queue, phone lookup and risk rules. Risk only ever HOLDS (rules 23, 24b).
 */
final class RiskServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(CustomerRiskProfile::class, CustomerRiskProfilePolicy::class);
        Gate::policy(RiskVerificationCall::class, RiskVerificationCallPolicy::class);

        // Placed, delivered, cancelled, returned… → rebuild the phone's profile (§4.1, §4.3, §4.4).
        Event::listen(OrderPlaced::class, RefreshProfileOnOrderChange::class);
        Event::listen(OrderStatusTransition::class, RefreshProfileOnOrderChange::class);
        Event::listen(ShipmentStatusChanged::class, RefreshProfileOnOrderChange::class);
    }
}
