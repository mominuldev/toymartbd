# Load and concurrency harness (Phase 9)

`tests/Load/run.php` is the roadmap's Phase 9 load test: concurrent checkout, a flash-sale burst
and courier bulk booking. It drives the real HTTP API with many concurrent clients, then checks
the database invariants. It is a plain PHP script that uses `curl_multi`, so it needs nothing
beyond PHP. It runs locally and in CI's PHP job without k6 or any other binary.

Findings and results are in `docs/audits/LOAD-AND-CONCURRENCY.md`.

## What it does

1. **Refuses** to run unless `DB_DATABASE` contains `load` and `APP_ENV` is not `production`,
   because it runs `migrate:fresh` on that database.
2. Migrates and seeds geography, delivery and roles. Mints a storefront token.
3. Starts its own server with `php -S` on `--port` (`PHP_CLI_SERVER_WORKERS=--workers`),
   using this environment:
   - **Queue, cache and sessions in the database.** Rate limits and idempotency locks are then
     shared across server workers, and courier jobs wait in `jobs` for the harness.
   - **`TRUSTED_PROXIES=127.0.0.1`.** Every virtual shopper sends its own `X-Forwarded-For`,
     as the Next.js server does, so per-IP limits apply per shopper.
   - **Scout off, logs to the server log.**
   - **On SQLite only:** `DB_TRANSACTION_MODE=IMMEDIATE`, `DB_BUSY_TIMEOUT=60000` and WAL. These
     are env overrides in `config/database.php` with unchanged defaults.
4. Runs three scenarios over HTTP, then checks the database.

| Scenario | Traffic | Asserts |
|---|---|---|
| **1. Concurrent checkout** | `--checkout-vus` shoppers (default 50) each build a cart (1–3 of 5 variants, 1–3 units) over `POST /cart/items`, quote it with `GET /cart?district_id&upazila_id`, then all press `POST /checkout` **at the same moment**. Every 10th shopper double-taps: same `Idempotency-Key` and body, in the same burst. Stock is 80% of the shopper count per variant, so some checkouts must be refused. | Error rate 0: only `201`, `201 replayed`, `409 insufficient-stock` and `409 request-in-progress` are allowed. One order per fresh 201, and none from a double tap. **No oversell:** sold ≤ stock and `reserved` = sold per variant. Stock movements sum to stock levels (`ReconcileStockLevels`). Order financials equal the ledger (`FinanceInvariant`). Order numbers are unique and gap-free. p95 ≤ `--p95-ms`. |
| **2. Flash-sale burst** | `--flash-vus` shoppers (default 50) each put 2 units of one flash-sale variant (allotment `--flash-allotment`, default 20, limit 2 per order) in a cart. All quote at the sale price, then press at once. Shoppers told `price-changed` re-quote and press again, as `docs/api/checkout.md` says. | First burst: only `201` or `409 price-changed`. Every re-quoted press is placed. **`claimed` ≤ allotment**, and the allotment sells out completely under contention. Σ flash units in `promotion_usages` = `claimed`. Σ `promotion_discount` = claimed × ৳200. Every shopper ends with exactly one order. Plus the invariants. |
| **3. Courier bulk booking** | `--bulk-orders` confirmed orders (default 100). `--bulk-staff` staff (default 10) each sign in through Sanctum's SPA flow (CSRF cookie, then login) and **all `POST /admin/shipments/bulk` at once**: every one sends every order, half shuffled and half reversed. The harness then runs `--queue-workers` forked `queue:work --queue=courier` workers, each with `NullCourierGateway` registered (rule 33a). | Every bulk request returns 200. Each order is requested exactly once across all staff. One shipment per order, with unique numbers. Every shipment ends `submitted` (rule 24d: bulk returns no consignment ids). The courier gateway saw each shipment **exactly once**. No failed job, and the courier queue is drained. Plus the invariants. |

Finally the harness checks that the server log has no `ERROR` line. On MySQL it also reports
how many InnoDB deadlocks happened during the run, read from `INNODB_METRICS.lock_deadlocks`. Every
deadlock was retried, or it would have shown up as an error. The count is reported, not gated. It
exits 0 when every check passes. `--report=<file>` writes the JSON report.

## Scale: what "10× launch concurrency" means here

No document states the expected launch concurrency. `PRODUCT-REQUIREMENTS.md` §4 gives only
**500 orders/day** as the scale target. The harness therefore uses this **assumption**:

> **Expected launch concurrency = 5 simultaneous checkout submissions.**

- At 500 orders/day with 20% of them in the peak hour, the steady rate is about 0.03 checkouts per
  second. That is far below one in flight at a time.
- A Facebook post announcing a sale bunches arrivals. Five presses landing in the same second is a
  generous peak for a launch-size shop.

10× that is the default of **50 simultaneous checkouts** (and 50 flash-sale shoppers). Every one
is fired in the same instant, which is harsher than real arrivals. Bulk booking has no stated
figure either. The assumption is **1 staff member bulk-sending 100 orders**, the API maximum, and
10× is **10 staff sending the same 100 orders at once**.

The owner should confirm or replace these figures. If they change, change the defaults or pass
the flags.

## Running it

```bash
# SQLite: proves correctness, not throughput (SQLite serialises every write)
mkdir -p "$TMPDIR/load" && : > "$TMPDIR/load/load.sqlite"
DB_CONNECTION=sqlite DB_DATABASE="$TMPDIR/load/load.sqlite" php tests/Load/run.php --port=8341

# MySQL: the real proof of locking (use a database whose name contains "load")
mysql -e 'CREATE DATABASE toymartbd_load CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
DB_CONNECTION=mysql DB_DATABASE=toymartbd_load DB_USERNAME=… DB_PASSWORD=… \
  php tests/Load/run.php --port=8341 --workers=16 --queue-workers=4

# Options
#   --scenario=checkout,flash,bulk   --checkout-vus=50   --flash-vus=50   --flash-allotment=20
#   --bulk-orders=100   --bulk-staff=10   --queue-workers=1   --workers=16   --p95-ms=5000
#   --report=/path/report.json
```

- **Requirements:** PHP with `curl`, `pcntl` and `posix`, plus the dev dependencies. The harness
  uses the test fixtures in `tests/`.
- **SQLite queue workers:** use one. Several workers on SQLite contend on the same `jobs` rows.
- **Latency:** `php -S` is not production PHP-FPM behind nginx, and the database runs on the same
  machine. The latency figures are a regression signal, not a capacity measurement.

## CI

The PHP job runs the full default scale on MySQL 8.4 after the `mysql` Pest group, in its own
`toymartbd_load` database, with `--p95-ms=15000`. A shared runner proves correctness, not latency.

**Needs the owner:** a staging-scale run against production-like infrastructure (PHP-FPM, Redis
queue and cache, MySQL on its own host), to set real latency budgets.
