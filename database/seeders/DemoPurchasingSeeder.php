<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\District;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockAlerts;
use App\Domain\Inventory\Services\StockOverview;
use App\Domain\Purchasing\Actions\ArchiveSupplier;
use App\Domain\Purchasing\Actions\CancelPurchase;
use App\Domain\Purchasing\Actions\ClosePurchaseShort;
use App\Domain\Purchasing\Actions\CreatePurchase;
use App\Domain\Purchasing\Actions\CreateSupplier;
use App\Domain\Purchasing\Actions\PlacePurchaseOrder;
use App\Domain\Purchasing\Actions\ReceivePurchase;
use App\Domain\Purchasing\Actions\RecordSupplierPayment;
use App\Domain\Purchasing\DTOs\PurchaseData;
use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\DTOs\ReceiptData;
use App\Domain\Purchasing\DTOs\SupplierData;
use App\Domain\Purchasing\DTOs\SupplierPaymentData;
use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Domain\Purchasing\Enums\SupplierPaymentMethod;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Role;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Demo purchasing (local/testing only, from DemoDataSeeder): the toy importers and
 * wholesalers of Old Dhaka's Chawkbazar/Bangshal/Islampur markets plus one Chattogram
 * trader, and ~two months of purchase orders in every state the state machine has —
 *
 *   draft · ordered · partially received · received · closed short · cancelled
 *
 * Every step goes through the domain Actions the admin uses (CreatePurchase →
 * PlacePurchaseOrder → ReceivePurchase → ClosePurchaseShort / CancelPurchase →
 * RecordSupplierPayment), with the clock set to the step's Dhaka date and time, so:
 *
 *  - receipts add stock through the ledger as `purchase_in` movements (the inventory
 *    invariant holds) and step each variant's weighted-average cost — the receipts that
 *    carry freight, customs or labour move `cost_price` through the landed-cost path;
 *  - landed costs a forwarder or truck agency charged become a pending capitalised
 *    expense (BookThirdPartyLandedCosts), exactly as from the receive dialog;
 *  - supplier payments post `supplier_payment` ledger entries (cash out, never P&L), and
 *    leave the payables report with suppliers fully paid, partly paid and owed.
 *
 * Receipts land at Dhaka HQ (dhk), except the Chattogram trader's, received at the
 * Agrabad branch (ctg). Nawabpur Plastic Toys is archived after its last order.
 *
 * Idempotent: suppliers are matched by name, purchases by their supplier invoice number;
 * a purchase that exists is skipped with its receipts and payments. StockLevelChanged is
 * silenced (a fresh database has no storefront cache or search index to refresh); the
 * low-stock alerts it would have raised are evaluated directly afterwards.
 */
class DemoPurchasingSeeder extends Seeder
{
    /**
     * name → [contact person, phone (E.164), address, district, payment terms days, opening balance, note]
     *
     * @var array<string, array{string, string, string, string, int, string, string}>
     */
    public const array SUPPLIERS = [
        'Chawkbazar Toy House · চকবাজার টয় হাউস' => [
            'Abdul Karim · আব্দুল করিম', '+8801711402381',
            'Shop 14, Chawkbazar Toy Market, Chawkbazar, Dhaka (near Shahi Masjid) · দোকান 14, চকবাজার খেলনা মার্কেট',
            'Dhaka', 30, '0.00', 'Branded imports — LEGO, Hot Wheels, Barbie. Delivers by van to Mirpur.',
        ],
        'Bangshal Kids Imports · বংশাল কিডস ইম্পোর্টস' => [
            'Shahidul Islam · শহিদুল ইসলাম', '+8801819503746',
            '27 Bangshal Road, Old Dhaka (opposite Bangshal police fari) · 27 বংশাল রোড, পুরান ঢাকা',
            'Dhaka', 15, '0.00', 'Imports through Chattogram port; freight and duty billed by its forwarder.',
        ],
        'Islampur Wholesale Toys · ইসলামপুর হোলসেল টয়েজ' => [
            'Mohammad Jashim · মোহাম্মদ জসিম', '+8801552817390',
            '9/2 Islampur Road, Old Dhaka (behind Ahsan Manzil) · 9/2 ইসলামপুর রোড, পুরান ঢাকা',
            'Dhaka', 45, '15000.00', 'Puzzles, crayons, Play-Doh. Carried-over balance from before the system.',
        ],
        'Mitford Educational Supplies · মিটফোর্ড এডুকেশনাল সাপ্লাইজ' => [
            'Rokeya Begum · রোকেয়া বেগম', '+8801913624058',
            '41 Mitford Road, Babubazar, Old Dhaka (near Mitford Hospital gate) · 41 মিটফোর্ড রোড, বাবুবাজার',
            'Dhaka', 30, '0.00', 'Wooden and Montessori toys, baby gyms.',
        ],
        'Reazuddin Bazar Traders · রিয়াজউদ্দিন বাজার ট্রেডার্স' => [
            'Nurul Alam · নুরুল আলম', '+8801819736402',
            'Block C, Reazuddin Bazar, Chattogram (beside Amtali gate) · ব্লক সি, রিয়াজউদ্দিন বাজার, চট্টগ্রাম',
            'Chattogram', 7, '0.00', 'Outdoor and pool toys. Supplies the Agrabad branch directly.',
        ],
        'Nawabpur Plastic Toys · নবাবপুর প্লাস্টিক টয়েজ' => [
            'Harun Mia · হারুন মিয়া', '+8801612480917',
            '112 Nawabpur Road, Dhaka (near Nawabpur Bazar mosque) · 112 নবাবপুর রোড, ঢাকা',
            'Dhaka', 0, '0.00', 'Stopped supplying after a price dispute — archived.',
        ],
    ];

    /** Archived once its purchases are written (never deleted: they reference it). */
    public const string ARCHIVED_SUPPLIER = 'Nawabpur Plastic Toys · নবাবপুর প্লাস্টিক টয়েজ';

    /**
     * Supplier invoice number (the idempotency key) → the purchase and what happens to it.
     * Days are "days ago" on a 60-day window, scaled to `demo.history_days`; times are Dhaka.
     * `lines`: [variant SKU, quantity, unit cost]. `receipts`: [day, time, units per SKU,
     * freight, customs, other, paid to supplier?, third-party vendor]. `pay`: [day, amount
     * or `full`/`half`, method, reference]. `end`: closed_short / cancelled with its day and reason.
     *
     * @var array<string, array{supplier: string, day: int, location: string, lines: list<array{string, int, string}>, place?: int, receipts?: list<array{int, string, array<string, int>, string, string, string, bool, string|null}>, pay?: list<array{int, string, SupplierPaymentMethod, string}>, end?: array{string, int, string}, note?: string}>
     */
    public const array PURCHASES = [
        // Received in full; the supplier billed the van freight on its invoice. Fully paid.
        'CTH-2609-114' => [
            'supplier' => 'Chawkbazar Toy House · চকবাজার টয় হাউস', 'day' => 57, 'location' => 'dhk',
            'lines' => [['TMB-LEG-CL484', 12, '2850.00'], ['TMB-HOT-10PK', 20, '1150.00']],
            'place' => 56,
            'receipts' => [[53, '11:30', ['TMB-LEG-CL484' => 12, 'TMB-HOT-10PK' => 20], '1200.00', '0', '0', true, null]],
            'pay' => [[46, 'full', SupplierPaymentMethod::BankTransfer, 'DBBL-TRF-560112']],
        ],
        // A small order from the supplier later archived. Fully paid in cash.
        'NPT-0921' => [
            'supplier' => self::ARCHIVED_SUPPLIER, 'day' => 52, 'location' => 'dhk',
            'lines' => [['TMB-PLA-RB10', 12, '720.00']],
            'place' => 52,
            'receipts' => [[50, '16:00', ['TMB-PLA-RB10' => 12], '0', '0', '0', true, null]],
            'pay' => [[48, 'full', SupplierPaymentMethod::Cash, 'CASH-VCH-0917']],
        ],
        // Imported: freight and customs paid to a C&F agent (a capitalised expense). Half paid.
        'BKI-INV-7731' => [
            'supplier' => 'Bangshal Kids Imports · বংশাল কিডস ইম্পোর্টস', 'day' => 49, 'location' => 'dhk',
            'lines' => [['TMB-FIS-SSPUP', 10, '2100.00'], ['TMB-HAP-PTB', 8, '1950.00']],
            'place' => 48,
            'receipts' => [[44, '14:15', ['TMB-FIS-SSPUP' => 10, 'TMB-HAP-PTB' => 8], '900.00', '2400.00', '0', false, 'Karnaphuli C&F Agency · কর্ণফুলী সিএন্ডএফ']],
            'pay' => [[40, 'half', SupplierPaymentMethod::Cheque, 'CHQ-IBBL-482190']],
        ],
        // Two deliveries, then the supplier ran out of the crayon sets: closed short. Owed.
        'IWT-1009-33' => [
            'supplier' => 'Islampur Wholesale Toys · ইসলামপুর হোলসেল টয়েজ', 'day' => 42, 'location' => 'dhk',
            'lines' => [['TMB-RUB-3X3', 30, '560.00'], ['TMB-PLA-RB10', 24, '700.00'], ['TMB-CRA-ST20', 40, '460.00']],
            'place' => 41,
            'receipts' => [
                [37, '12:00', ['TMB-RUB-3X3' => 30, 'TMB-PLA-RB10' => 12], '0', '0', '350.00', true, null],
                [31, '15:40', ['TMB-PLA-RB10' => 12, 'TMB-CRA-ST20' => 25], '0', '0', '0', true, null],
            ],
            'end' => ['closed_short', 26, 'Supplier has no more Crayola 20-colour sets this season · ক্রেয়োলা সেট আর আসবে না'],
        ],
        // The Chattogram trader delivers to the Agrabad branch; a truck agency billed the haul. Part paid.
        'RBT-CTG-2210' => [
            'supplier' => 'Reazuddin Bazar Traders · রিয়াজউদ্দিন বাজার ট্রেডার্স', 'day' => 35, 'location' => 'ctg',
            'lines' => [['TMB-INT-DINOP', 10, '1050.00'], ['TMB-LIT-SLIDE', 4, '4900.00']],
            'place' => 35,
            'receipts' => [[32, '10:45', ['TMB-INT-DINOP' => 10, 'TMB-LIT-SLIDE' => 4], '1800.00', '0', '0', false, 'Agrabad Truck Agency · আগ্রাবাদ ট্রাক এজেন্সি']],
            'pay' => [[28, '20000.00', SupplierPaymentMethod::BankTransfer, 'CITY-TRF-771045']],
        ],
        // Placed, then the supplier re-quoted higher: cancelled before anything arrived.
        'NPT-1003' => [
            'supplier' => self::ARCHIVED_SUPPLIER, 'day' => 29, 'location' => 'dhk',
            'lines' => [['TMB-RUB-3X3', 20, '520.00']],
            'place' => 29,
            'end' => ['cancelled', 27, 'Supplier quoted a higher price after the order · অর্ডারের পর দাম বাড়িয়েছে'],
        ],
        // Received with a labour charge on the invoice. Fully paid by bKash.
        'CTH-2610-162' => [
            'supplier' => 'Chawkbazar Toy House · চকবাজার টয় হাউস', 'day' => 23, 'location' => 'dhk',
            'lines' => [['TMB-BAR-DHPP', 15, '1600.00'], ['TMB-MEL-DOCK', 10, '1800.00']],
            'place' => 22,
            'receipts' => [[19, '13:20', ['TMB-BAR-DHPP' => 15, 'TMB-MEL-DOCK' => 10], '0', '0', '300.00', true, null]],
            'pay' => [[12, 'full', SupplierPaymentMethod::Bkash, 'BKASH-8F3K2L9QXA']],
        ],
        // Half the microscopes still to come: partially received, with an advance paid.
        'BKI-INV-7802' => [
            'supplier' => 'Bangshal Kids Imports · বংশাল কিডস ইম্পোর্টস', 'day' => 16, 'location' => 'dhk',
            'lines' => [['TMB-BRU-MANDT', 6, '3700.00'], ['TMB-CLE-MICRO', 8, '2500.00']],
            'place' => 15,
            'receipts' => [[10, '11:10', ['TMB-BRU-MANDT' => 6, 'TMB-CLE-MICRO' => 3], '600.00', '0', '0', true, null]],
            'pay' => [[14, '10000.00', SupplierPaymentMethod::Cheque, 'CHQ-IBBL-482233']],
        ],
        // Sent to the supplier, awaiting delivery.
        'MES-2611-08' => [
            'supplier' => 'Mitford Educational Supplies · মিটফোর্ড এডুকেশনাল সাপ্লাইজ', 'day' => 9, 'location' => 'dhk',
            'lines' => [['TMB-MID-SHSORT', 20, '800.00'], ['TMB-HUA-GYM', 10, '1550.00']],
            'place' => 8,
            'note' => 'Deliver before the weekend sale · সাপ্তাহিক সেলের আগে ডেলিভারি',
        ],
        // Still a draft: the manager has not confirmed the quantity.
        'IWT-DRAFT-1111' => [
            'supplier' => 'Islampur Wholesale Toys · ইসলামপুর হোলসেল টয়েজ', 'day' => 3, 'location' => 'dhk',
            'lines' => [['TMB-WIN-PUSHW', 8, '2700.00'], ['TMB-CRA-ST20', 30, '450.00']],
            'note' => 'Waiting on the walker quote · ওয়াকারের দাম নিশ্চিত হয়নি',
        ],
    ];

    private ?int $staffId = null;

    private ?int $accountantId = null;

    /** @var array<string, int> */
    private array $locations = [];

    /** @var array<int, true> variants whose stock moved */
    private array $touched = [];

    public function run(): void
    {
        $this->staffId = DemoStaffSeeder::user(Role::InventoryStaff)?->id;
        $this->accountantId = DemoStaffSeeder::user(Role::Accountant)?->id;
        $this->locations = [
            'dhk' => StockLocation::defaultId(),
            'ctg' => (int) (StockLocation::query()->where('code', 'ctg')->value('id') ?? StockLocation::defaultId()),
        ];

        try {
            Event::fakeFor(function (): void {
                $suppliers = $this->suppliers();
                foreach (self::PURCHASES as $invoiceNo => $spec) {
                    if (Purchase::query()->where('supplier_invoice_no', $invoiceNo)->exists()) {
                        continue;
                    }
                    $this->purchase($invoiceNo, $spec, $suppliers[$spec['supplier']]);
                }
                $archived = $suppliers[self::ARCHIVED_SUPPLIER];
                if ($archived->isActive()) {
                    app(ArchiveSupplier::class)->handle($archived);
                }
            }, [StockLevelChanged::class]);
        } finally {
            Carbon::setTestNow(null);
            CarbonImmutable::setTestNow(null);
        }

        $alerts = app(StockAlerts::class);
        foreach (array_keys($this->touched) as $variantId) {
            $alerts->evaluate($variantId);
        }
        StockOverview::forgetLowCounts();
    }

    /** @return array<string, Supplier> name → supplier */
    private function suppliers(): array
    {
        $districts = District::query()->whereIn('name', ['Dhaka', 'Chattogram'])->pluck('id', 'name');
        $out = [];
        foreach (self::SUPPLIERS as $name => [$contact, $phone, $address, $district, $terms, $opening, $note]) {
            $out[$name] = Supplier::query()->where('name', $name)->first()
                ?? $this->at(60, '10:00', static fn (): Supplier => app(CreateSupplier::class)->handle(new SupplierData(
                    name: $name,
                    contactPerson: $contact,
                    phone: $phone,
                    email: null,
                    address: $address,
                    districtId: isset($districts[$district]) ? (int) $districts[$district] : null,
                    paymentTermsDays: $terms,
                    openingBalance: Money::of($opening),
                    note: $note,
                )));
        }

        return $out;
    }

    /**
     * @param  array{supplier: string, day: int, location: string, lines: list<array{string, int, string}>, place?: int, receipts?: list<array{int, string, array<string, int>, string, string, string, bool, string|null}>, pay?: list<array{int, string, SupplierPaymentMethod, string}>, end?: array{string, int, string}, note?: string}  $spec
     */
    private function purchase(string $invoiceNo, array $spec, Supplier $supplier): void
    {
        $variants = $this->variants(array_map(static fn (array $l): string => $l[0], $spec['lines']));
        $locationId = $this->locations[$spec['location']];

        $purchase = $this->at($spec['day'], '10:00', fn (): Purchase => app(CreatePurchase::class)->handle(new PurchaseData(
            supplierId: $supplier->id,
            lines: array_map(static fn (array $l): PurchaseLineData => new PurchaseLineData($variants[$l[0]], $l[1], $l[2], Money::zero()), $spec['lines']),
            orderDate: DhakaDate::todayDate(),
            expectedDate: DhakaDate::todayDate()->addDays(7),
            locationId: $locationId,
            supplierInvoiceNo: $invoiceNo,
            note: $spec['note'] ?? null,
        ), $this->staffId));

        if (isset($spec['place'])) {
            $purchase = $this->at($spec['place'], '12:30', static fn (): Purchase => app(PlacePurchaseOrder::class)->handle($purchase));
        }

        $events = [];
        foreach ($spec['receipts'] ?? [] as $receipt) {
            $events[] = [$receipt[0], $receipt[1], 'receive', $receipt];
        }
        foreach ($spec['pay'] ?? [] as $payment) {
            $events[] = [$payment[0], '17:00', 'pay', $payment];
        }
        if (isset($spec['end'])) {
            $events[] = [$spec['end'][1], '16:30', 'end', $spec['end']];
        }
        // Oldest first (most days ago), then by time of day.
        usort($events, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        foreach ($events as [$day, $time, $kind, $payload]) {
            $this->at($day, $time, function () use ($kind, $payload, $purchase, $supplier, $variants, $locationId): void {
                $fresh = Purchase::query()->with('items')->findOrFail($purchase->id);
                if ($kind === 'receive') {
                    $this->receive($fresh, $payload, $variants, $locationId);
                } elseif ($kind === 'pay') {
                    $this->pay($fresh, $supplier, $payload);
                } elseif ($payload[0] === 'closed_short') {
                    app(ClosePurchaseShort::class)->handle($fresh, $payload[2], $this->staffId);
                } else {
                    app(CancelPurchase::class)->handle($fresh);
                }
            });
        }
    }

    /**
     * @param  array{int, string, array<string, int>, string, string, string, bool, string|null}  $receipt
     * @param  array<string, int>  $variants  SKU → variant id
     */
    private function receive(Purchase $purchase, array $receipt, array $variants, int $locationId): void
    {
        [, , $units, $freight, $customs, $other, $toSupplier, $vendor] = $receipt;
        $itemBySku = [];
        foreach ($purchase->items as $item) {
            /** @var PurchaseItem $item */
            $sku = array_search($item->variant_id, $variants, true);
            if (is_string($sku)) {
                $itemBySku[$sku] = $item->id;
            }
        }

        $lines = [];
        foreach ($units as $sku => $quantity) {
            $lines[] = ['purchase_item_id' => $itemBySku[$sku], 'quantity' => $quantity];
            $this->touched[$variants[$sku]] = true;
        }

        app(ReceivePurchase::class)->handle($purchase, new ReceiptData(
            lines: $lines,
            freight: Money::of($freight),
            customsDuty: Money::of($customs),
            otherCost: Money::of($other),
            method: LandedCostMethod::Value,
            locationId: $locationId,
            costsPaidToSupplier: $toSupplier,
            note: $purchase->supplier_invoice_no === null ? null : 'Invoice '.$purchase->supplier_invoice_no.' checked against delivery · চালান মিলিয়ে দেখা হয়েছে',
            costsVendor: $toSupplier ? null : $vendor,
            costsReference: $toSupplier ? null : 'BILL-'.$purchase->supplier_invoice_no,
            costsPaymentMethod: $toSupplier ? null : 'bank_transfer',
        ), $this->staffId);
    }

    /** @param  array{int, string, SupplierPaymentMethod, string}  $payment */
    private function pay(Purchase $purchase, Supplier $supplier, array $payment): void
    {
        [, $amount, $method, $reference] = $payment;
        $money = match ($amount) {
            'full' => $purchase->due_amount,
            // Half the balance, rounded down to the nearest 1,000 taka.
            'half' => Money::fromPoisha(intdiv(intdiv($purchase->due_amount->toPoisha(), 2), 100_000) * 100_000),
            default => Money::of($amount),
        };
        if (! $money->greaterThan(Money::zero())) {
            return;
        }

        app(RecordSupplierPayment::class)->handle($supplier, new SupplierPaymentData(
            amount: $money,
            method: $method,
            paidAt: DhakaDate::todayDate(),
            purchaseId: $purchase->id,
            reference: $reference,
            note: 'Against '.$purchase->number.' · '.$purchase->number.' এর বিপরীতে',
        ), $this->accountantId);
    }

    /**
     * @param  list<string>  $skus
     * @return array<string, int> SKU → variant id
     */
    private function variants(array $skus): array
    {
        $ids = ProductVariant::query()->whereIn('sku', $skus)->pluck('id', 'sku')->all();
        foreach ($skus as $sku) {
            if (! isset($ids[$sku])) {
                throw new RuntimeException("DemoPurchasingSeeder needs variant [{$sku}] — run DemoCatalogSeeder first.");
            }
        }

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /** A 60-day-window offset scaled to `demo.history_days`; never today (the clock must not run ahead). */
    private function scale(int $daysAgo): int
    {
        $history = max(1, (int) config('demo.history_days', 60));

        return max(1, (int) round($daysAgo * $history / 60));
    }

    /**
     * Run a step with the clock at that Dhaka day and time. The caller resets the clock.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function at(int $daysAgo, string $time, callable $step): mixed
    {
        Carbon::setTestNow(null);
        CarbonImmutable::setTestNow(null);
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $instant = CarbonImmutable::now(DhakaDate::zone())->startOfDay()
            ->subDays($this->scale($daysAgo))->setTime($hour, $minute)->utc();

        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);

        return $step();
    }
}
