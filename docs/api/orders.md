# Orders, payments and customers — backend contract (Phase 4 batch 4)

P4-ORD-01, P4-ORD-03, P4-ORD-09, P4-ORD-10, P4-PAY-01, plus the read side of P4-ORD-04/05.
Schema: `DATABASE-DESIGN.md` §3.3–3.5 (as-built notes there). Code: `app/Domain/Ordering`,
`app/Domain/Payment`, `app/Domain/Customer`.

## Order number

`TMB-YY-NNNNN` — two-digit **Dhaka** year, per-year counter padded to five digits (it grows
past 99,999). `OrderNumberGenerator::next()` locks the year's row in `order_number_sequences`
`FOR UPDATE` inside the caller's transaction, so a rolled-back checkout returns its number.

## State machines (P4-ORD-03)

Three status columns, each with its own guarded table (the tables live on the enums; tests
try every `(from, to)` pair). Every allowed move writes one `order_events` row and emits one
domain event (after commit). Any other move — including to the same state, i.e. a race —
throws `InvalidOrderTransition` (409 `conflict`). A save that changes a status column without
going through `OrderTransitions` throws.

### `status`

| from | allowed to | event emitted on entering the target |
|---|---|---|
| pending | confirmed, on_hold, cancelled | |
| on_hold | pending, confirmed, cancelled | → pending `OrderReleasedFromHold` |
| confirmed | processing, ready_to_ship, shipped, on_hold, cancelled | → on_hold `OrderPutOnHold` · → confirmed `OrderConfirmed` |
| processing | ready_to_ship, shipped, on_hold, cancelled | → processing `OrderProcessingStarted` |
| ready_to_ship | shipped, on_hold, cancelled | → ready_to_ship `OrderReadyToShip` (stamps `packed_at`) |
| shipped | delivered, returned, partially_returned | → shipped `OrderShipped` |
| delivered | completed, returned, partially_returned | → delivered `OrderDelivered` |
| partially_returned | returned, completed | → partially_returned `OrderPartiallyReturned` · → returned `OrderReturned` |
| completed · cancelled · returned | — (terminal) | → completed `OrderCompleted` · → cancelled `OrderCancelled` |

All of them extend `OrderStatusChanged` and implement `OrderStatusTransition` (listen to the
interface for "any transition"). Payloads are ids and status strings only.

Cancellation is pre-shipment only; after dispatch the way back is a return. A risky order is
put **on hold**, never auto-cancelled (rules 23, 24b). Customer-visible timeline rows: every
status except pending, on_hold, processing, ready_to_ship.

### `payment_status`

| from | allowed to |
|---|---|
| unpaid | pending, partially_paid, paid, failed |
| pending | unpaid, partially_paid, paid, failed |
| failed | unpaid, pending, partially_paid, paid |
| partially_paid | paid, partially_refunded, refunded |
| paid | partially_refunded, refunded |
| partially_refunded | refunded |
| refunded | — |

Derived, never set by hand: `SyncOrderPaymentStatus` re-computes it (and `paid_total`,
`refunded_total`, `due_total` per §7.4) from the order's payments and completed refunds
whenever a `PaymentStatusChanged` arrives, and applies the result through the table above.
COD before collection is `unpaid`; an online payment at the gateway is `pending`.

### `fulfillment_status`

unfulfilled → partially_fulfilled | fulfilled · partially_fulfilled → fulfilled | returned ·
fulfilled → returned · returned terminal.

### Which Action moves what

| Move | Action | Side effects in the same transaction |
|---|---|---|
| confirm, hold, release, processing, ready_to_ship, completed | `TransitionOrder` | none |
| cancel | `CancelOrder` | release reservation (Inventory `ReleaseStock`), cancel open payments |
| shipped | courier booking (Phase 6, §4.2) | consume stock (`sale_out`), ledger |
| delivered / returned | Fulfilment (Phase 6, §4.3–4.4) | COD collection, ledger, restock |

## Cancel (P4-ORD-09)

`CancelOrder::handle(Order, CancellationReason, ?note, ?userId)`. Reasons:
`customer_request · customer_unreachable · fake_order · duplicate_order · out_of_stock ·
pricing_error · address_unserviceable · payment_failed · other` (other needs a note).
Stored in `orders.cancel_reason` / `cancel_note` and on the event row's `meta`.

**Hooks for other domains** — `OrderCancelled` (after commit) carries `orderId`,
`customerId`, `from`, `reason`, `note`, `couponCode`, `paidTotal`:
- Marketing: reverse the `coupon_usages` row for `orderId` and decrement `used_count`.
- Risk: `customer_risk_profiles.cancelled_count++` for the order's phone (load the order).
- Finance/refunds: `paidTotal > 0` means money was taken online and is owed back.

## Payments (P4-PAY-01)

- `PaymentGateway` contract: `method()`, `initiate(Payment, PaymentContext)`, `verify(Payment)`,
  `refund(Payment, Money, reason)`. Drivers never write to the DB and are called **outside** any
  transaction (rule 4); their `GatewayResult` is then applied by `PaymentTransitions::apply()`.
- `PaymentGatewayRegistry` (AppServiceProvider): `cod` → `CashOnDeliveryGateway`. A new gateway
  is a driver class + one `register()` line (rule 5).
- `payments.status`: pending → initiated → authorised → paid, any open state → failed |
  cancelled | expired, and pending → paid directly. Terminal states never reopen; a retry is a
  new row. Same status = idempotent no-op (webhook replay). `paid` is refused unless the
  reported amount equals the payment's (rule 19, `PaymentAmountMismatch`).
- COD: opened `pending`; `initiate` is a no-op (no redirect); `CollectCodPayment` (courier
  collected) applies `pending → paid` through the same `apply()` an online verification uses.
- Actions: `InitiatePayment`, `VerifyPayment` (re-query; what the webhook will call),
  `CollectCodPayment`. `refunds` / `refund_items` tables exist; the refund flow is Phase 5.

## Customer aggregates (P4-ORD-10)

`customers.orders_count` (non-cancelled orders), `delivered_orders_count` (delivered,
completed, partially_returned), `total_spent` (Σ grand_total − refunded_total over those),
`avg_order_value` (total_spent ÷ delivered count, half away from zero), `first_order_at` /
`last_order_at` (placed_at over all orders). A full recomputation every time, by the queued
listener `RefreshAggregatesOnOrderChange` (queue `reports`) on `OrderPlaced` and every
transition. Repair: `php artisan toymart:customers:rebuild-aggregates [--chunk=500]`.

## Admin API (read side)

Both need `orders.view`; every role without it gets 403 (role-matrix tests).

### `GET /api/v1/admin/orders`

Cursor-paginated (25), newest first. `meta.counts` = `{all, pending, on_hold, …}` under every
filter **except** `status` (the board's tabs). Rows show the customer name and a **masked**
phone only.

| Param | Values |
|---|---|
| `filter[status]` `filter[payment_status]` `filter[fulfillment_status]` | enum values above |
| `filter[source]` | web, admin, phone, facebook, whatsapp |
| `filter[risk_level]` | low, medium, high |
| `filter[payment_method]` | cod, sslcommerz, bkash, nagad, card, bank_transfer, cash |
| `filter[customer_id]` | id |
| `filter[placed_from]` `filter[placed_to]` | Y-m-d **Dhaka** dates, inclusive |
| `filter[search]` | order-number prefix, last 4+ phone digits, or name |
| `sort` | `created_at`, `grand_total` (± `-`), default `-created_at` |

Unlisted filter/sort or a bad value → 400 `bad-request`.

### `GET /api/v1/admin/orders/{order}`

Customer, shipping snapshot, totals, items, payments, timeline, risk. **PII masking** (rule 20):
without `customers.view_pii`, phones are `017****5678` and `address`, `landmark`, `postcode` are
`null` (`pii_masked: true`). `items[].cost_price_snapshot` only with `catalog.view_cost`.

## PlaceOrder (batch 5) — the transaction outline against this schema

`OrderFixtures::place()` (tests/Feature/Ordering/Support) is a test-only stand-in for steps 3–9.

```
POST /checkout  (Idempotency-Key required, rule 17)
outside the transaction:
  1. resolve/validate the cart and address; normalise the phone (PhoneNumber::parse → E.164)
BEGIN (PlaceOrder Action — the only DB::transaction)
  2. re-price the cart via the pricing pipeline (never client totals); re-validate coupon and
     promotions; DeliveryChargeResolver::quote() for shipping (same path as the estimator)
  3. StockReservations::reserve(lines)  — FOR UPDATE on stock_levels, ascending variant_id,
     all-or-nothing; InsufficientStock (409) lists every short line → whole order fails
  4. Customer: find by phone (E.164) or create (source = order source)
  5. number = OrderNumberGenerator::next()  — locks the year row
  6. INSERT orders: status pending, payment_status unpaid, fulfillment_status unfulfilled,
     money columns (grand_total = subtotal − discount_total + shipping_charge + tax_total, §7.3;
     due_total = grand_total), address snapshot incl. landmark + geography ids, payment_method,
     coupon_code, cart_id, utm/ip/user_agent, placed_at
  7. INSERT order_items with every snapshot and cost_price_snapshot = the variant's
     weighted-average cost (stock_levels.avg_cost once purchases exist; variant cost_price
     today) — written once, never again (rule 7); recommendation_source from the cart line
  8. PaymentTransitions::open(order, method, grand_total)  → payments row, status pending
  9. Marketing: INSERT coupon_usages + coupons.used_count++ (its service, same transaction)
 10. risk score → risk_score/risk_level/risk_flags; high → verification_status pending and
     OrderTransitions::transition(order, on_hold) (never cancel, rule 24b)
 11. OrderTransitions::placed(order)  → order_events "placed" + OrderPlaced (after commit)
 12. mark the cart converted (Cart's service: converted_at, order_id)
COMMIT
after commit:
 13. InitiatePayment for an online method (driver HTTP, then a short transaction) → redirect URL
     COD: nothing to call; the order stays unpaid until CollectCodPayment at delivery
 14. listeners on OrderPlaced: notifications, order_financials, customer aggregates (wired),
     search re-index
```
