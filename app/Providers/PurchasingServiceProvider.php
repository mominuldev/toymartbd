<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Listeners\Purchasing\BookThirdPartyLandedCosts;
use App\Domain\Finance\Listeners\Purchasing\RecordSupplierPaymentInLedger;
use App\Domain\Purchasing\Events\LandedCostsPaidToThirdParty;
use App\Domain\Purchasing\Events\SupplierPaymentRecorded;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Models\PurchaseReceiptItem;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Policies\PurchasePolicy;
use App\Policies\SupplierPaymentPolicy;
use App\Policies\SupplierPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Purchasing wiring (P5): policies for every model (rule 21) and the Finance listener
 * that turn a supplier payment into its ledger entry and third-party landed costs into a
 * capitalised (cash-only) expense (rule 2: cross-domain writes go
 * through events). Kept out of AuthServiceProvider so this domain does not contend for
 * its map.
 */
final class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Purchase::class, PurchasePolicy::class);
        // Lines and receipts are parts of their PO and are only ever reached through it.
        Gate::policy(PurchaseItem::class, PurchasePolicy::class);
        Gate::policy(PurchaseReceipt::class, PurchasePolicy::class);
        Gate::policy(PurchaseReceiptItem::class, PurchasePolicy::class);
        Gate::policy(SupplierPayment::class, SupplierPaymentPolicy::class);

        Event::listen(SupplierPaymentRecorded::class, RecordSupplierPaymentInLedger::class);
        Event::listen(LandedCostsPaidToThirdParty::class, BookThirdPartyLandedCosts::class);
    }
}
