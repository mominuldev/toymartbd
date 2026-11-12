<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Listeners\Refunds\RecordRefundApproved;
use App\Domain\Finance\Listeners\Refunds\RecordRefundPaid;
use App\Domain\Finance\Listeners\Refunds\RecordReturnInspection;
use App\Domain\Ordering\Events\RefundApproved;
use App\Domain\Ordering\Events\RefundPaid;
use App\Domain\Ordering\Events\ReturnInspected;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Models\OrderReturnItem;
use App\Policies\OrderReturnPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Returns and refunds (DATABASE-DESIGN §4.7, PRD O-21): policies for the RMA models, and
 * the Ordering → Finance listeners (cross-domain writes through events, rule 2). The
 * events are held until the Ordering transaction commits; the listeners run synchronously
 * after it and write through the Ledger contract.
 */
final class ReturnsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(OrderReturn::class, OrderReturnPolicy::class);
        Gate::policy(OrderReturnItem::class, OrderReturnPolicy::class);

        Event::listen(ReturnInspected::class, RecordReturnInspection::class);
        Event::listen(RefundApproved::class, RecordRefundApproved::class);
        Event::listen(RefundPaid::class, RecordRefundPaid::class);
    }
}
