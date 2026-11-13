# Gate 4 — owner UAT script

**Gate:** `DEVELOPMENT-ROADMAP.md` §Phase 4. *"The owner can add a real product with variants, images and SEO, and a real customer can buy it COD on a phone and receive an SMS · the entire purchase journey completes in Bangla as well as English, with no untranslated string and no clipped or overflowing Bangla label on any screen at 390px · stock reserves correctly and releases on cancel · the two correctness invariants hold."* Gate 4 is the owner's UAT on staging with real products.

**You need:**

- the staging admin (`https://staging-api.<domain>/admin`) on a laptop
- the storefront (`https://staging.<domain>`) on **your own phone**
- your owner login
- about 90 minutes

A developer should be on hand for §0 and §9, which use a terminal.

Every button name below is the English label on screen. The Bangla label is given where you will see it. If a label differs from this script, record it in §11; it is a finding.

## 0. Before you start

**Developer:**

1. Run `scripts/staging/smoke.sh`. It must end with `0 fail(s)` (`STAGING.md` §8).
2. Run `php artisan toymart:payments:gateway sslcommerz` and confirm it is **inactive**.
3. Note the date and time. The profit check in §10 uses it.

**What you will and won't see for messages.** SMS goes out for real through REVE SMS. You (or a manager) enter the REVE credentials at **Admin → Integrations → Messaging** (`/admin/integrations/messaging`) — provider, API key, secret key, the sender ID (your **non-masking** REVE number, digits only) and the cost per segment (**৳0.26**, your REVE rate); the keys are stored encrypted and only their last 4 characters are ever shown again. There is no WhatsApp account yet, so the **WhatsApp** switch on that screen stays off and every order update goes by SMS. Before you start, use **Send test SMS** on that screen once in English and once in Bangla (the developer's `php artisan toymart:sms:test <your number>` / `--bn` uses the same settings); both must arrive.

| You will see | You will NOT see |
|---|---|
| The sign-in code by SMS on your phone | Any WhatsApp message (no account yet) |
| An SMS for each order event (placed, confirmed, shipped, delivered, cancelled), in the language you ordered in | The message text in the admin log — only the template, masked phone and status |
| A row per message in **Admin → Automations → Message log** (`/admin/automations/log`): template, channel **SMS**, masked recipient (`017****5678`), status, segments and cost | |

If a message does not arrive, find its row in the Message log: **Sent** with a provider message id means the provider accepted it (check the provider panel with that id); **Failed / Dead-lettered** shows the provider's reason.

## 1. Add a real product (admin)

| # | Step | Expected | Pass / Fail |
|---|---|---|---|
| 1.1 | **Products** → **Add product** (`/admin/products/new`). On **General**, fill "Name (English)" and "নাম (বাংলা)", short descriptions in both, Brand, Category, ages. Set "Product type" to **Variable**. | Saved fields stay. Note that the type cannot change later. | ☐ |
| 1.2 | **Variants** tab: **Add option** (e.g. Colour), **Add value** ×2 (e.g. Red, Blue). In the matrix, fill Price, Cost, Weight (g) and **Opening stock** (e.g. 5 each). | One row per colour. SKUs are generated if left empty. | ☐ |
| 1.3 | **Create product**. | Saved. The URL becomes `/admin/products/{id}`. | ☐ |
| 1.4 | **Media** → **Choose from library** → upload 2–3 real photos → **Insert N images**. Add English **and** Bangla alt text to each. | Thumbnails appear. Publishing needs both alt texts. | ☐ |
| 1.5 | **SEO** tab: Meta title and description in English **and** Bangla, Focus keyword. | The "SEO checklist · N of M" improves. | ☐ |
| 1.6 | **Status & visibility** → Publish = **Published** → **Save changes**. | Status "Published". | ☐ |
| 1.7 | **Inventory** (`/admin/inventory`): find both variants. | "On hand" 5, "Reserved" 0, "Available" 5. | ☐ |

## 2. Buy it COD in Bangla (your phone)

| # | Step | Expected | Pass / Fail |
|---|---|---|---|
| 2.1 | Open the storefront. Switch to Bangla: in the **footer**, tap **বাংলা** (the header language pill is hidden on phones). | Address is `/bn/…`. All text is Bangla. Prices, phone and order numbers use **Western digits 0–9** (rule 36). | ☐ |
| 2.2 | Search for your product, then open it (`/bn/products/{slug}`). | Your photos, Bangla name and description, the price you set. No English left on the page. No clipped or overflowing Bangla label. | ☐ |
| 2.3 | Choose **Red** → "Add to cart" (কার্টে যোগ করুন) → cart (`/bn/cart`) → "Proceed to checkout". | Cart total = price × qty + delivery charge (placeholder ৳70 Dhaka / ৳100 suburb / ৳130 elsewhere, free from ৳3,000). | ☐ |
| 2.4 | Checkout (`/bn/checkout`): your name in Bangla, **your real mobile number**, District, Upazila / Thana, Area, Full address, **Landmark**. Payment: **ক্যাশ অন ডেলিভারি** → **অর্ডার করুন**. | Confirmation page (`/bn/orders/{number}`), "Thank you — your order is placed!" in Bangla. Order number like `TMB-26-00001`. Phone shown masked. | ☐ |
| 2.5 | Write down the order number: **A = ____________** | | |

Fail step 2 if any of these happen:

- a string shows in English
- a label is cut off or overflows at phone width
- a total differs from what the cart showed

## 3. Buy it COD in English

| # | Step | Expected | Pass / Fail |
|---|---|---|---|
| 3.1 | Footer → **English**. Buy **Blue** × 2, as in §2, with an English name and a different address. | The page reads English at `/` (no prefix). Confirmation shows "Order TMB-…". | ☐ |
| 3.2 | Order number **B = ____________**. Place a third small order (**C = ____________**). It will be cancelled in §6. | | ☐ |
| 3.3 | `/track` ("Track your order"): order number B + **last 4 digits** of the phone → **Track order**. Then try a wrong 4 digits. | The right digits show the status timeline. The wrong digits show nothing about the order (rule 22). | ☐ |

## 4. Stock is reserved, not yet sold (admin)

| # | Step | Expected | Pass / Fail |
|---|---|---|---|
| 4.1 | **Inventory**: Red and Blue rows. | Red: on hand 5, **reserved 1** (A), available 4. Blue: on hand 5, **reserved 2 + C's quantity**, available drops by the same amount. On hand has **not** changed. | ☐ |
| 4.2 | **Inventory → Movements** (`/admin/inventory/movements`). | Only "Opening stock" rows. A reservation is **not** a movement. | ☐ |
| 4.3 | **Automations → Message log**. | One **Sent** row per order (A, B, C), template "Order confirmation", channel SMS (WhatsApp is off), masked recipient. | ☐ |

## 5. Confirm → pack → ship → deliver (orders A and B)

The order page is `/admin/orders/{id}`. Open it from **Orders**.

| # | Step | Expected | Pass / Fail |
|---|---|---|---|
| 5.1 | Order A → **Confirm order**. | Status Confirmed. Message log: "Order confirmed" row. | ☐ |
| 5.2 | **Mark ready to ship**. This is packing: there is no separate "Pack" button on the order page. On the **Orders** list, the bulk **Mark packed** button does the same for several orders. | Status Ready to ship. The "Where this order is" stepper shows **Packed**. | ☐ |
| 5.3 | **Mark shipped**. Fill "Courier" (e.g. "Steadfast") and "Tracking reference" (optional) → **Mark shipped**. | Status Shipped. Message log: "Order shipped". **Inventory**: Red on hand **4**, reserved **0**. **Movements**: a **Sale out** row −1 referencing the order. | ☐ |
| 5.4 | **Mark delivered** → **Mark delivered**. | Status Delivered. Payment shows collected by the courier. Message log: "Order delivered". | ☐ |
| 5.5 | **Finance → P&L** (`/admin/finance/pnl`), today. | Product revenue, Delivery charges collected, Cost of goods sold, Gross profit and Net profit now include order A. Orders still in transit appear under "Not in this statement". | ☐ |
| 5.6 | Repeat 5.1–5.4 for order **B**. | Blue on hand 3, reserved = C's quantity. | ☐ |

## 6. Cancel releases stock (order C)

| # | Step | Expected | Pass / Fail |
|---|---|---|---|
| 6.1 | Order C → **Cancel order**. Reason "Customer asked to cancel", type the order number → **Cancel order**. | Status Cancelled. Message log: "Order cancelled". | ☐ |
| 6.2 | **Inventory**. | Blue **reserved 0**, on hand unchanged (3), available 3. No Sale out for C in Movements. | ☐ |
| 6.3 | **Finance → P&L**. | C contributes nothing. It was never delivered. | ☐ |

## 7. Return and refund (order B)

| # | Step | Expected | Pass / Fail |
|---|---|---|---|
| 7.1 | Order B → card **Returns & refunds** → **Request a return**: 1 unit of Blue, Reason "Defective" → **Open return**. | Return opened. **Open in Returns** link appears. | ☐ |
| 7.2 | **Returns** (`/admin/returns`) → the return → **Mark received** → **Inspect & restock**: resellable 1, damaged 0. | Inspected. Refund "Awaiting approval" for the unit's price. Blue on hand **4**. Movements: **Customer return** +1. | ☐ |
| 7.3 | **Approve refund** → **Record payout**: Sent by bKash, Transaction reference. | Refund Paid. Order shows partially returned / partially refunded. | ☐ |
| 7.4 | **Finance → P&L**. | Net profit falls by (refund − the unit's cost): the cost comes back, the refund goes out. | ☐ |

## 8. Damaged return (optional, order A)

Return Red from A as **damaged** (resellable 0, damaged 1).

**Expected:**

- Movements show Customer return +1 and **Damage out** −1, so on hand is unchanged.
- P&L "Profit after losses" shows the write-off.

☐

## 9. Invariants (developer, terminal)

| # | Command | Expected | Pass / Fail |
|---|---|---|---|
| 9.1 | `php artisan toymart:inventory:reconcile` | `… 0 drift(s)`, exit 0 | ☐ |
| 9.2 | `php artisan toymart:finance:verify` | "Order financials equal the ledger.", exit 0 | ☐ |
| 9.3 | `scripts/staging/smoke.sh` | `0 fail(s)` | ☐ |

## 10. Profit hand check, as a Phase 5 rehearsal

Export today's orders:

```bash
php artisan toymart:finance:order-breakdown --from=<today> --format=xlsx
```

Then follow `PROFIT-RECONCILIATION.md` for A and B. Every row should read **OK**. ☐

## 11. Sign-off and feedback

| Area | Result | Notes |
|---|---|---|
| Product entry (§1) | ☐ Pass ☐ Fail | |
| Bangla purchase (§2) | ☐ Pass ☐ Fail | |
| English purchase (§3) | ☐ Pass ☐ Fail | |
| Stock reserve / sell / release (§4–6) | ☐ Pass ☐ Fail | |
| Returns & refunds (§7–8) | ☐ Pass ☐ Fail | |
| Messages, as seen in the log (§0, §4.3) | ☐ Pass ☐ Fail | |
| Invariants (§9) | ☐ Pass ☐ Fail | |

**Feedback.** For each item, record:

- screen and URL
- what you did
- what you expected
- what happened
- severity: blocker / must-fix before launch / nice-to-have
- a phone screenshot if possible

| # | Screen | What happened | Severity |
|---|---|---|---|
| 1 | | | |
| 2 | | | |
| 3 | | | |

**Owner decisions to record here:**

- SMS provider: REVE SMS (decided 2026-11-13) — rate ৳0.26 per segment, non-masking sender (owner, 2026-11-13); sender number: ____
- WhatsApp Business provider
- real delivery charges and free-delivery threshold
- hotline number
- refund approval threshold
- first-order verification threshold

Owner: ______________________  Date: ____________  **Gate 4: ☐ Passed ☐ Passed with fixes ☐ Not passed**

Known things you may notice that are not regressions:

- An existing product's stock field says adjustments are "coming soon". The text is stale; adjustments exist under **Inventory → New adjustment**.
- No "Out for delivery" message is ever sent. The template exists, but nothing triggers it before Phase 6.
- Courier charges are not in the P&L until Phase 6 courier booking.
