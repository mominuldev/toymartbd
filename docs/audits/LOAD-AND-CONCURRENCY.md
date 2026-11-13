# Load and concurrency audit (Phase 9, batch 1)

**Scope:**
- the counter-row deadlock pattern;
- a lock-order audit of every Action that takes more than one row lock;
- a repeatable load harness for concurrent checkout, flash-sale bursts and courier bulk booking (roadmap Phase 9).

**Harness:** `tests/Load/run.php`, with `tests/Load/README.md`.
**Date:** 2026-11-14.

**How this was verified.** Local MySQL was believed broken. A private `mysqld` (Homebrew MySQL **9.3**) was started from a temporary datadir on port 3341. It did not touch the owner's services. Every MySQL race test and the MySQL load run below executed against it. CI runs MySQL **8.4**. The InnoDB locking behaviour involved (shared lock on a duplicate key, gap locks under REPEATABLE READ) is the same in both versions.

The machine's load average was 25–30 throughout, because five other agents were running tests. Latency figures below are therefore pessimistic, and they come from `php -S`, not PHP-FPM.

## Findings

| # | Severity | Where | Finding | Fix | Test that proves it | Status |
|---|---|---|---|---|---|---|
| F-1 | **High** | `Ordering/Services/OrderNumberGenerator::next()` | **Order-number deadlock.** The counter row was `INSERT IGNORE`d unconditionally inside the checkout transaction. On an existing row that takes a shared lock, and two checkouts holding it deadlock when they upgrade to `FOR UPDATE`. **Measured before the fix:** 12 parallel transactions with one attempt each gave **11 of 12 deadlocked**. PlaceOrder retries 3×, which hid it as wasted work: the whole checkout rolls back and re-runs. Under a burst a checkout can lose three times and return a 500. | Added `prepare()`, called by PlaceOrder **before** the transaction. It does a plain read and inserts only for the first order of a Dhaka year, in autocommit. `next()` now locks first and inserts only when the row is missing (year turned mid-call). Same pattern as `InvoiceNumberGenerator` after PR #24. | `tests/Feature/Ordering/OrderNumberRaceTest.php`. **MySQL:** 12×5 transactions with no retry and a third of them rolled back give 0 deadlocks (InnoDB counter) and gap-free numbers. A second test has every child prepare at once for a new year, giving one row. **SQLite:** prepare is idempotent per Dhaka year, missing-row path, rollback returns the number. | **Fixed** |
| F-2 | **High** | `Fulfilment/Services/ShipmentNumberGenerator`, `Actions/RequestShipment` | **The same counter deadlock on shipment numbers, with no retry at all.** RequestShipment's transaction had no attempts, so two staff bulk-sending at once got a **500** for the losing order. | `prepare()` before the transaction. Lock-first in `next()`. RequestShipment's transaction now has 3 attempts. | `tests/Feature/Fulfilment/ShipmentNumberRaceTest.php`. **MySQL:** 12×5 no-retry draws give 0 deadlocks and gap-free numbers. Four staff run RequestShipments over the same 8 orders (two in board order, two reversed): 8 requested, 24 conflicts, 8 shipments, 8 distinct numbers. **SQLite:** sequencing, Dhaka year, missing row, rollback. | **Fixed** |
| F-3 | Medium | `Fulfilment/Actions/RequestShipments` | An unexpected exception on order *k* of a bulk request propagated before the dispatch step. Shipments 1…*k*−1 were already committed and sat `pending` with no booking job. | The loop is wrapped in `try … finally`, and the finally block always dispatches what was committed (bulk job or one job each). | `ShipmentNumberRaceTest`: "still books the shipments it created when a later order in the bulk request throws" (SQLite). | **Fixed** |
| F-4 | Medium | `Marketing/Actions/ReverseOrderMarketingUsage` | It runs synchronously after a cancel commits, locking coupon → promotions. That is the other side of EditOrder's documented tier-4 residue (DATABASE-DESIGN §4.8: an edit can lock a new coupon or promotion after releasing old ones). It had **1 attempt**, so a deadlock returned a 500 on a cancel that had already committed, and the usage was never given back. | 3 attempts. | Covered by the existing Marketing suite (behaviour unchanged). The retry is the same Laravel mechanism PlaceOrder and EditOrder use, so no new test was written. | **Fixed** |
| F-5 | Medium | `Fulfilment/Actions/ApplyCourierStatus::complete()` | Booking completion ran in a savepoint and `catch (Throwable)` recorded `completion_failed` and carried on. A **deadlock is not a refusal**: InnoDB had rolled back the whole transaction, shipment lock included. The failure record was then written outside any transaction, and the status update was lost. | `DeadlockException` (deadlock or lock-wait timeout) is rethrown. The outer transaction now has 3 attempts, and the poll or webhook job retries after that. | `tests/Feature/Fulfilment/CourierStatusDeadlockTest.php`. A deadlock injected on the order update propagates and no `completion_failed` is written. This test fails without the fix (checked). A real refusal still records `completion_failed`. | **Fixed** |
| F-6 | **High** (launch day) | `Ordering/Checkout/CheckoutCustomers::findOrCreate()`, `Risk/Actions/RefreshRiskProfile` | **Found by the MySQL load run:** 3 deadlocks in a 50-shopper burst, all on `customers_phone_unique`. Both paths used `SELECT … FOR UPDATE` on a phone that does not exist yet, which is a **gap lock**. Two first orders from **different** new phones in the same index gap then deadlock on their inserts. On launch day every shopper is new. Retries hid it: one rolled-back checkout per collision, or a 500 after three losses. Once the customer path was fixed, `NewCustomerCheckoutRaceTest` caught the same pattern in `RefreshRiskProfile` (`customer_risk_profiles_phone_unique`). | Neither path locks a missing key any more. The order is: plain read, insert the missing row (insert-intention locks do not conflict with each other; `createOrFirst` or `insertOrIgnore` absorbs a same-phone race), then `FOR UPDATE` on the row that now exists, which is a record lock and not a gap lock. | `tests/Feature/Checkout/NewCustomerCheckoutRaceTest.php`. **MySQL:** 12 parallel first orders from new phones, one of them a returning customer, give 12 orders, 12 customers and **0 deadlocks**. `RiskProfileRaceTest` was updated: its simulation now races the new existence check, and its MySQL test (8 rebuilds of one phone) still passes. The load run after the fix showed **0 deadlocks**. | **Fixed** |
| F-7 | Medium | Catalog: `CropProductImage`, `UpdateProductImage`, `DeleteProductImage` vs `ReplaceMedia`, `UpdateMedia`, `CompleteMediaRenditions`, `AttachMediaToProduct` | **Lock-order inversion.** The image actions lock product → `product_images` → `media`. The media actions lock `media` and then UPDATE every `product_images` row with that `media_id` (`ProductImageMediaMirror`). None of them retries. It needs staff editing an image while its renditions complete or the media is replaced, so it is rare. The result is a 500, or a failed queued job that the queue retries. | **Fixed (lead, integration).** `ProductImageMediaMirror::lockLinkedImages()` locks the linked `product_images` rows ascending id **before** the media row in `UpdateMedia`, `ReplaceMedia` and `CompleteMediaRenditions` (handle + fail), matching the image side; each of those transactions retries `ATTEMPTS` = 3. `AttachMediaToProduct` already locked product → media with no prior image lock, so no cycle. | `tests/Feature/Media/MediaLockOrderTest.php` (fails without the fix). | **Fixed** |
| F-8 | Low | `Ordering/Actions/PlaceManualOrder` | `$cart->save()` with `coupon_id` takes a **shared FK lock on the coupon row** before PlaceOrder locks stock. Two manual orders with the same coupon, or one against a web checkout, can deadlock: S then X upgrade, or coupon before stock against stock before coupon. | Not changed. Both sides retry 3×, it is staff-only, and it needs the same coupon at the same moment. Removing it means applying the coupon outside the order transaction, which brings back orphan carts. | — | Accepted (retried) |
| F-9 | Low | `Customer/Actions/VerifyLoginOtp`, `RequestLoginOtp`, `Finance/Actions/ImportCourierSettlement`, `GenerateRecurringExpenses` | The same gap-lock-then-insert shape as F-6, with **1 attempt**. Contention needs the same phone, or the same settlement reference or expense period, at the same instant. | Not changed. Note: apply the F-6 pattern if any of these becomes hot. | — | Accepted |
| F-10 | Low | `Inventory/Services/StockLevelLocks` | Between the non-locking existence read and the `INSERT IGNORE` of a *missing* stock row, a concurrent first writer can insert it, which reproduces the S-then-X upgrade. This only happens on a variant's first-ever stock row. | Not changed. PlaceOrder and EditOrder retry. Receiving and adjusting are staff actions on one variant. | Existing `CheckoutConcurrencyTest`, `StockTransferConcurrencyTest`. | Accepted |
| F-11 | Low | `Invoicing/Actions/IssueSalesInvoice` | Locks the global `INV` counter **before** the order row. There is no cycle, because nothing that holds an order asks for `INV`. While it waits for a busy order, every other invoice issue queues behind it. | Not changed. This is a throughput note, not a deadlock. | — | Accepted |
| F-12 | Low (local only) | SQLite under concurrent HTTP writers | DEFERRED transactions failed lock upgrades at once with "database is locked", returning 500s in the first harness run. Production is MySQL. | `config/database.php`: the SQLite `busy_timeout`, `journal_mode` and `transaction_mode` read `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE` and `DB_TRANSACTION_MODE`. Defaults are unchanged. The harness sets IMMEDIATE, a 60 s busy timeout and WAL. | The SQLite harness run (below). | **Fixed (harness)** |

`InvoiceNumberGenerator::prepare()` also got the plain-read-first guard. It takes no lock in the common case even if a caller ever runs it inside a transaction.

## Lock-order audit

**Method.** Every Action and Service that takes `lockForUpdate`, raw `FOR UPDATE`, or an `INSERT IGNORE`/upsert inside a transaction was read and tabulated. Events were checked for after-commit dispatch. Every `Order*`, `Payment*`, `Stock*`, `Refund*` and `Return*` event and `ShipmentStatusChanged` is `ShouldDispatchAfterCommit`. `ShipmentBooked`, `ShipmentDelivered`, `ShipmentReturned` and `ShipmentPartiallyDelivered` are synchronous by design, and their Ordering listeners run inside the Fulfilment transaction, which was audited as one chain.

**Global order (DATABASE-DESIGN §4.8, still holds):** order row (or cart at checkout) → `stock_levels` ascending `variant_id` (then `location_id`) → coupons → promotions, each followed by its flash items ascending.

**Where the rest of the code fits:**
- **PlaceOrder:** cart → stock → customer (record lock only, since F-6) → `order_number_sequences` → coupon → promotions and flash items.
- **RequestShipment:** order → `shipment_number_sequences`.
- **Fulfilment:** shipment → order → stock → payments ascending.
- **Invoicing:** `CN` counter → `INV` counter → invoice row. IssueSalesInvoice uses `INV` → order (F-11).

**No cycle found in:**
- stock;
- shipment ↔ order (Fulfilment always takes shipment → order, and nothing takes order → existing shipment);
- payments (every multi-payment lock is ascending, and always under the order lock);
- invoicing;
- purchasing;
- inventory transfers (`lockAcross` orders by (variant, location));
- bulk actions. Every bulk action (confirm, pack, ship, deliver, request shipments, invoice bulk, poll, `BookShipmentsBulk`) runs **one transaction per order or shipment**. `MarkShipmentsSubmitted` is the only multi-shipment lock, and it is sorted.

**Inversions:**
- EditOrder's coupon/promotion residue: documented, retried both sides, and F-4 closes the one side without a retry.
- `PlaceManualOrder` (F-8): retried.
- Media ↔ `product_images` (F-7): escalated.

Multi-row single-statement locks without `orderBy` are all keyed singletons, or sit behind a parent lock (settings by key, `CategoryTree`, reorder actions under their parent row).

The existing `tests/Feature/Orders/LockOrderTest.php` still asserts the PlaceOrder and EditOrder order.

## Load test results

The harness is described in `tests/Load/README.md`.

**Scale.** No document states the expected launch concurrency. The only figure is 500 orders/day (PRD §4). **Assumption: 5 simultaneous checkout submissions at launch, so 10× = 50**, all fired at the same instant. For bulk booking: 1 staff member × 100 orders, so 10× = 10 staff sending the same 100 orders at once. The owner should confirm these numbers.

**Concurrent checkout** (50 shoppers, 10% double taps, stock = 80% of demand):

| Run | Outcomes | Checkout p50 / p95 / max | Cart and quote p95 | Deadlocks | Result |
|---|---|---|---|---|---|
| SQLite (writes serialised; correctness only) | 44 placed · 3 replayed · 6 insufficient stock · 2 in progress | 2457 / 4307 / 4380 ms | 2354 ms | — | PASS |
| MySQL 9.3, before F-6 | 44 placed · 4 replayed · 6 insufficient stock · 1 in progress | 1805 / 3027 / 3233 ms | 924 ms | **3** (retried) | PASS |
| **MySQL 9.3, after all fixes** | 45 placed · 3 replayed · 5 insufficient stock · 2 in progress | 1899 / 2866 / 3027 ms | 2036 ms | **0** | **PASS** |
| MySQL 9.3, 20× (100 shoppers) | 94 placed · 9 replayed · 6 insufficient stock · 1 in progress | 2881 / 4966 / 5203 ms | 2607 ms | 0 | PASS |

**Flash-sale burst** (50 shoppers × 2 units, allotment 20, limit 2):

| Run | Outcomes | First-press p50 / p95 | Deadlocks | Result |
|---|---|---|---|---|
| SQLite | 10 placed at the sale price · 40 price-changed → re-quoted → 40 placed at the regular price. Claimed 20/20, flash units 20, discount ৳4,000 | 1314 / 2891 ms | — | PASS |
| MySQL 9.3, after all fixes | 10 placed at the sale price · 40 price-changed → 40 re-placed. Claimed 20/20 | 1135 / 1768 ms | 0 | PASS |
| MySQL 9.3, 20× (100 shoppers, allotment 40) | 20 + 80 → 80 re-placed. Claimed 40/40 | 1260 / 1950 ms | 0 | PASS |

**Courier bulk booking** (10 staff × the same 100 orders; `NullCourierGateway`):

| Run | Requests | Bulk p95 | Requested | Shipments | Courier calls | Queue workers | Result |
|---|---|---|---|---|---|---|---|
| SQLite | 10 × 200 | 3446 ms | 100 | 100, all `submitted` | 100 (each once) | 1 | PASS |
| MySQL 9.3, after all fixes | 10 × 200 | 1612 ms | 100 | 100, all `submitted` | 100 (each once) | 4 | PASS |

Every run also passed these checks:
- stock movements sum to stock levels;
- order financials equal the ledger;
- order numbers unique and gap-free;
- error rate 0;
- no ERROR line in the server log.

The SQLite runs prove correctness only. SQLite serialises every write, and here it ran with IMMEDIATE transactions.

**Latency is not a capacity figure.** These numbers come from `php -S` with 16 workers and MySQL on the same overloaded laptop. All 50 checkouts arrive in the same millisecond and serialise on hot rows: each variant's stock row and the single order-number counter. The p95 is roughly the length of that queue. No API latency budget exists in `UI-UX-DESIGN-SPEC.md` §7, which budgets pages only. The harness gate is set at p95 ≤ 5 s locally and ≤ 15 s in CI. Both are correctness gates.

## CI

`.github/workflows/ci.yml`, PHP job: a new step **"Load harness (MySQL …)"** after the MySQL Pest group.
- It creates `toymartbd_load` and runs the full default scale with 4 queue workers and `--p95-ms=15000`.
- It took about 50–60 s locally on MySQL 9.3 under heavy machine load.
- It was green on the current code locally (MySQL 9.3 and SQLite). It has **not yet run on CI's MySQL 8.4.**

The new MySQL race tests join the existing `--group=mysql` step:
- 2 in `OrderNumberRaceTest`;
- 2 in `ShipmentNumberRaceTest`;
- 1 in `NewCustomerCheckoutRaceTest`.

Each one migrates a throwaway database, which takes about 15–20 s on CI.

## For other areas

- **F-7 (media pipeline, agent 3 / Catalog):** make the media-side actions (`ReplaceMedia`, `UpdateMedia`, `CompleteMediaRenditions`, `AttachMediaToProduct`) lock the `product_images` rows for the media (ascending id) before the `media` row, matching `Crop`, `Update` and `DeleteProductImage`. Then add 3 attempts.

## Needs the owner

1. **Expected launch concurrency.** Confirm or replace the assumption of 5 simultaneous checkouts and 1 × 100-order bulk send. The roadmap gate is "10× expected launch concurrency", and no document gives the base figure.
2. **Staging-scale run.** Run on production-like infrastructure (PHP-FPM behind nginx, Redis queue and cache, MySQL 8.4 on its own host, the real worker count). That run sets real latency budgets for checkout and the admin bulk actions. Command: `tests/Load/README.md`, run against a staging database named `*load*`.
3. **API latency budget.** Decide whether checkout should carry a server-side p95 budget alongside the checkout LCP ≤ 2.0 s, so the staging run has something to gate against.
