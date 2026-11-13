# Profit reconciliation — the ten-order hand check

**Gate:** `DEVELOPMENT-ROADMAP.md` §Phase 5 completion criteria. The criterion reads: *"A purchase can be received, its landed cost flows into a sale, and the resulting net profit figure is correct to the taka including courier charges, discounts and refunds — verified by hand against a spreadsheet on at least ten real orders."*

**Tools:**

- `php artisan toymart:finance:order-breakdown` (`app/Console/Commands/Finance/OrderProfitBreakdownCommand.php`, `app/Domain/Finance/Reconciliation/`)
- the blank sheet `docs/ops/profit-reconciliation-template.csv`
- the rehearsal `tests/Feature/Finance/Reconciliation/TenOrderRehearsalTest.php`

## What the export proves

Each order's net profit is computed **three ways**. The three must agree to the poisha.

| Column group | Source | Shares code with |
|---|---|---|
| `order_*` | Straight from the source tables: `orders`, `order_items.cost_price_snapshot` (rule 7), inspected `order_returns`, approved `refunds`, paid `payments.fee_amount`, order-linked approved `expenses` | Nothing in Finance. It is an independent recomputation, so a listener that books the wrong amount, or nothing, shows up. |
| `ledger_*` | `financial_transactions`: recognised entries (`is_recognised_revenue`, rule 10) whose source belongs to the order | Only the list of order-scoped source types (`OrderLedgerSources`) |
| `projection_net_profit` | the stored `order_financials.net_profit` | (the nightly `toymart:finance:verify` checks it too) |

`difference` = order net − ledger net. `mismatched_components` names every component whose two sides differ. `result` is `OK` only when there is no mismatch, the difference is 0.00, and the projection equals the ledger. The command exits **1** if any order differs.

**The hand check adds a fourth opinion.** The accountant rebuilds the order-side numbers from paper: the invoice, the order page and the returns page. The aim is to confirm that the software's reading of the source tables matches what actually happened.

## Components (each in its natural direction; a cost is positive)

```
net profit = item_revenue − discount + delivery_charge − cogs + cogs_reversed − write_off
             − refunds − courier_charges − gateway_fees − order_expenses + other
```

| Component | Order side, from | When it counts |
|---|---|---|
| `item_revenue` | Σ `unit_price × quantity` (+ line tax; VAT is 0 today) | Only once the order is delivered: status delivered, completed, returned or partially returned, with `delivered_at`. Revenue is recognised on delivery (rule 10). |
| `discount` | Σ `order_items.discount`: the coupon/promotion share on each line. It must equal `orders.discount_total`; if not, `discount` mismatches. | delivered |
| `delivery_charge` | `orders.shipping_charge`: what the customer paid for delivery (৳0 when free) | delivered |
| `cogs` | Σ `cost_price_snapshot × quantity`, rounded to the poisha per line. The cost frozen at order time (rule 7), never today's cost. | delivered |
| `cogs_reversed` | Σ returned units × the same snapshot, per inspected return line. Goods we hold again no longer count as sold. | delivered and return inspected |
| `write_off` | Σ **damaged** returned units × snapshot | delivered and return inspected |
| `refunds` | Σ `refunds.amount` approved and not rejected (includes a refunded delivery charge) | delivered |
| `courier_charges` | **always 0 until Phase 6.** Manual fulfilment records no courier charge or COD fee (`DATABASE-DESIGN.md` §4.3 "as built"). Profit is overstated by the courier bill until then. Enter courier bills as an expense against the order (category "Courier bills") to see them here, under `order_expenses`. | — |
| `gateway_fees` | Σ `payments.fee_amount` on paid payments (SSLCommerz). COD = 0. | when paid |
| `order_expenses` | approved expenses with `order_id` = this order (e.g. packaging), in a non-capitalised category | when approved |
| `other` | always 0 on the order side; anything else the ledger holds for the order | — |

**Placed, cancelled before shipping, or still in transit:** every component is 0. Nothing has been earned yet.

## The procedure (owner + accountant, about an hour)

1. **Pick ten real orders**, at least one of each kind where they exist: plain COD delivered, a coupon, Dhaka and outside-Dhaka delivery, a multi-line order, a partial return, a full return, a damaged return, a cancelled order, one still in transit. At least one product on them should have been **received through a purchase order with freight**, so the landed cost reaches COGS.
2. **Export:**
   ```bash
   php artisan toymart:finance:order-breakdown --orders=TMB-26-00012,TMB-26-00015,… --format=xlsx
   # or every order placed in a period, plus the period P&L cross-check:
   php artisan toymart:finance:order-breakdown --from=2026-12-01 --to=2026-12-07 --format=xlsx
   ```
   - The file goes to `storage/app/private/reconciliation/`, or wherever `--output=` points.
   - It holds no phone, name or address.
   - It is read-only: it writes nothing to the database.
   - The xlsx has an **Orders** sheet and, with `--from`, a **Period** sheet. The Period sheet shows:
     - the P&L report's net profit
     - the same figure summed straight from the ledger
     - the split into order-scoped entries and everything else (rent, salaries, stock write-offs…)
3. **Open `profit-reconciliation-template.csv`** in Excel or Google Sheets. Its columns match the export, plus four hand-check columns.
4. **Fill each order's line by hand from paper, not from the export.** Fill only the `order_*` columns and A–G. Sources:
   - **invoice** (`Print invoice` on the order page): unit prices, quantities, discount, delivery charge
   - **product's cost at the time:**
     - the purchase order's landed unit cost: **Purchasing → the PO → Receive** panel, "landed" per unit
     - or the opening stock cost
     - check it against the order line's snapshot
   - **Returns page:** resellable or damaged units, and the refund paid
5. **Formula hints** (row 2; copy down). Columns: H `order_item_revenue` … Z `order_order_expenses`, AB `order_other`, AE `ledger_net_profit`, AJ `hand_net_profit`, AK `hand_matches_ledger`.
   ```
   N2  (order_cogs)        = sum over lines of ROUND(snapshot_cost * qty, 2)
   P2  (order_cogs_reversed)= sum over returned lines of ROUND(snapshot_cost * returned_qty, 2)
   R2  (order_write_off)   = sum over returned lines of ROUND(snapshot_cost * damaged_qty, 2)
   AJ2 (hand_net_profit)   = H2 - J2 + L2 - N2 + P2 - R2 - T2 - V2 - X2 - Z2 + AB2
   AK2 (hand_matches_ledger)= IF(ROUND(AJ2 - AE2, 2) = 0, "YES", "NO")
   ```
   Paste the export's `ledger_*`, `ledger_net_profit` (AE) and `projection_net_profit` (AF) next to your hand figures. Every AK must read **YES**, and every export `result` must read **OK**.
6. **The period check.** On the export's Period sheet, "P&L report net profit" must equal "Ledger recognised net, summed directly". Their difference is not the same thing as Σ `ledger_net_profit` of the orders. The period counts entries *dated* in it (Dhaka dates, rule 11), so a refund approved next month belongs to next month. Lifetime order rows do not.
7. **Sign:** `checked_by` on every row, and a dated note in the owner's Phase 5 sign-off.

## When something differs

**Do not fix the ledger by hand.** `stock_movements` and `financial_transactions` are append-only (rule 8). A difference means a code path booked the wrong amount, or did not book at all. Instead:

1. Record the order number, the `mismatched_components` value, and both amounts.
2. Open a bug with the export attached. The developer finds the code path, fixes it with a test, and writes a reversing entry through the proper Action if one is needed.
3. Re-run the export after the fix. Use `toymart:finance:verify --rebuild` only once the cause is fixed.

## Known differences (as of 2026-11-13)

| Where | What you will see | Why | Status |
|---|---|---|---|
| Online (SSLCommerz) orders | `DIFFERS`, `mismatched_components = gateway_fees`; the difference is the negative fee | The gateway's fee is stored on the payment but **never booked to the ledger** (no `gateway_fee` writer exists). Net profit in the P&L is overstated by the fee. | **Open.** Needs a decision on the booking date (capture vs delivery) and whether it is a cash entry, plus a Finance listener. The gateway is inactive for launch, so COD-only orders are unaffected. |
| Every order | `order_courier_charges` = `ledger_courier_charges` = 0 | No courier charge is recorded before Phase 6 | By design until Phase 6. The P&L overstates profit by the courier bill. |

## Rehearsal (2026-11-13)

`TenOrderRehearsalTest` runs the whole check in CI on ten orders built through the real flow:

- storefront checkout over HTTP: cart, coupon, resolver delivery charge, cost snapshot
- admin confirm / ship / deliver / cancel
- returns inspected, and refunds approved and paid, through the admin API
- one product bought on a PO with ৳2,000 freight (landed ৳1,500 a unit)
- an order-linked packaging expense, and ৳5,000 rent in the period

It asserts that the order side, the ledger side, the projection and the hand-worked figures written in the test all agree for every order. The figures:

| Order | Net |
|---|---|
| COD | 505.00 |
| Coupon | 730.00 |
| Dhaka ৳70 delivery | 469.00 |
| Heavy parcel ৳170 delivery | 870.00 |
| Multi-line, free delivery | 1,349.50 |
| Partial return | 729.50 |
| Full return | 0.00 |
| Damaged return | −70.00 |
| Cancelled | 0 |
| In transit | 0 |

Period totals: Σ order nets 4,583.00, rent −5,000.00, P&L net −417.00. The test also confirms that both invariant commands exit 0. **Result: every COD scenario agreed to the poisha. No disagreement was found.**
