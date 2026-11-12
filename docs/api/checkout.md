# Checkout, order confirmation and tracking — storefront contract (P4-ORD-02, P4-STO-09/10)

Backend: `App\Domain\Ordering\Actions\PlaceOrder` (the DATABASE-DESIGN §4.1 transaction — see
`docs/api/orders.md` "PlaceOrder as built"). The storefront never computes a price, discount,
charge or stock decision (rule 1): it sends what the customer typed plus the total it showed, and
renders what comes back.

All three endpoints sit in the storefront group: the Next.js server's token with the
`storefront:read` ability (no token → 401 `unauthenticated`, no ability → 403 `forbidden`).
Locale: `?locale=bn` (or `Accept-Language`) localises every `message`/`detail`. Money is a decimal
string (`"1930.00"`), digits are Western in both locales (rule 36), timestamps ISO 8601 UTC.
Errors are RFC 9457 `application/problem+json`; branch on `type`
(`https://toymartbd.com/problems/<type>`), never on `title`/`detail`.

| Method | Path | Guard | Rate limit |
|---|---|---|---|
| POST | `/api/v1/storefront/checkout` | `X-Cart-Token` + `Idempotency-Key` | `checkout`: 10/min per cart |
| GET | `/api/v1/storefront/orders/{number}` | `X-Cart-Token` of the cart that placed it | `order-lookup`: 30/min per IP |
| POST | `/api/v1/storefront/orders/track` | order number + phone | `order-track`: 5 per 10 min per IP + number, 20 per 10 min per number |

---

## `POST /checkout` — place the order

### Headers

| Header | Required | Notes |
|---|---|---|
| `X-Cart-Token` | yes | The cart cookie's value. Unknown / expired / already converted → 404 `not-found` (drop the cookie). |
| `Idempotency-Key` | yes (rule 17) | One key per **checkout attempt** (e.g. a UUID minted when the checkout page renders, kept in the form). A double tap, a network retry or a resubmit of the same body with the same key returns the **same** 201 with `Idempotent-Replayed: true` — never a second order. Same key + different body → 422 `idempotency-key-reused`; while the first is still running → 409 `request-in-progress` + `Retry-After: 1`. After a 4xx (nothing was written) the same key may be retried with a corrected body. |

### Body (JSON)

```json
{
  "name": "সাবরিনা আক্তার",
  "phone": "01712-345678",
  "alt_phone": null,
  "email": null,
  "division_id": 6,
  "district_id": 13,
  "upazila_id": 101,
  "area": "ধানমন্ডি",
  "address_line": "বাসা ১২, রোড ৫, ব্লক সি",
  "landmark": "আগোরার পাশে, ৩য় তলা",
  "postcode": null,
  "payment_method": "cod",
  "customer_note": null,
  "expected_total": "4320.00",
  "utm_source": null, "utm_medium": null, "utm_campaign": null
}
```

| Field | Rule |
|---|---|
| `name` | required, 2–160 chars, any script |
| `phone` | required; a Bangladeshi mobile, **013–019 only** (rule 30). Any spelling: `01712345678`, `01712-345678`, `+8801712345678`, Bengali digits. Stored E.164. Error message for a bad number: "Enter an 11-digit mobile number starting with 01 (013–019)." |
| `alt_phone` | optional, same rule |
| `email` | optional, valid email, ≤ 191 |
| `division_id` | optional; if sent it must be the district's division (the API derives it from the district anyway) |
| `district_id` | required, an existing district (`GET /districts`) |
| `upazila_id` | required, an upazila **of that district** (`GET /districts/{id}/upazilas`) — **except** when the district has `city_option: true` (Dhaka city is not an upazila): then `null` means the city itself |
| `area` | required, 2–160 (the form's area select or "other area" text) |
| `address_line` | required, 5–500 |
| `landmark` | optional, ≤ 255 — never dropped (rule 31); encourage it in the UI |
| `postcode` | optional, exactly 4 digits |
| `payment_method` | required, `cod` (the only method until an online gateway ships, P4-PAY-02) |
| `customer_note` | optional, ≤ 500 |
| `expected_total` | required, decimal string — the **grand total the customer saw** on the button (`Place order · ৳4,320`), i.e. `totals.grand_total` of `GET /cart?district_id=&upazila_id=` for the same address |
| `utm_source` / `utm_medium` / `utm_campaign` | optional, ≤ 128 each |

The coupon is the one on the cart (`POST /cart/coupon`); checkout takes no `coupon` field. Lines,
quantities and prices come from the cart, re-priced server-side.

### What the server does

Re-prices the cart through `PricingPipeline` for this address and phone (so per-phone coupon
limits apply now) → refuses if not checkout-ready → refuses if the total differs from
`expected_total` → reserves stock (all or nothing) → finds or creates the customer by phone and the
address → numbers the order `TMB-YY-NNNNN` → writes the order, items (with cost snapshots), the COD
payment, the coupon usage → risk check → `placed` timeline row. One database transaction; then the
cart is closed (its token stops resolving as a cart but still opens the confirmation).

### 201 Created — `StorefrontOrder`

The same object `GET /orders/{number}` returns (see below), plus header `Location:
/api/v1/storefront/orders/TMB-26-00042`. Redirect to the confirmation page with the number; keep
the cart token (in a short-lived cookie) to read the confirmation — the cart itself is gone.

### Errors

| Status | `type` | When | `errors[]` |
|---|---|---|---|
| 400 | `idempotency-key-missing` / `idempotency-key-invalid` | header absent / malformed | — |
| 404 | `not-found` | no open cart for `X-Cart-Token` (never issued, expired, already ordered) | — |
| 409 | `price-changed` | the re-priced total ≠ `expected_total` (a price, promotion or delivery charge changed). **Nothing was written.** Re-fetch `GET /cart?district_id=&upazila_id=`, show the new total, let the customer press again with the new `expected_total` (the same key is fine — a 4xx stores nothing). | `[{field: "expected_total", message: "The total has changed to ৳4,390.00. Please review your order and place it again."}]` |
| 409 | `insufficient-stock` | the reservation failed for at least one line (someone bought the last units). Whole order refused, never partial. | one per short line: `{field: "items.<variant_id>", message: "Only 2 left in stock."}` (or "Sorry, this item is out of stock.") |
| 409 | `request-in-progress` | same key still running | — |
| 422 | `validation-error` | a field is invalid, **or the cart is not checkout-ready** | `{field, message}` per problem. Not-ready fields: `cart` (empty), `items.<variant_id>` (no longer sold — remove it), `coupon` (the coupon no longer applies, e.g. "You have already used this coupon." for a per-phone limit — remove it via `DELETE /cart/coupon`), `upazila_id` / `district_id` ("We don't deliver to this area yet.") |
| 422 | `idempotency-key-reused` | same key, different body | — |
| 429 | `too-many-requests` | more than 10 checkouts/min for one cart | `Retry-After` header |
| 503 | `service-unavailable` | no active courier provider configured | — |

---

## `StorefrontOrder` (confirmation object)

```json
{
  "number": "TMB-26-00042",
  "stage": "placed",
  "placed_at": "2026-11-12T10:15:00Z",
  "payment": { "method": "cod", "status": "unpaid", "next_action": null },
  "contact": { "name": "সাবরিনা আক্তার", "phone_masked": "017****5678", "email": null },
  "shipping": {
    "name": "সাবরিনা আক্তার",
    "phone_masked": "017****5678",
    "division": { "id": 6, "name": "Dhaka", "name_bn": "ঢাকা" },
    "district": { "id": 13, "name": "Dhaka", "name_bn": "ঢাকা" },
    "upazila": { "id": 101, "name": "Dhanmondi", "name_bn": "ধানমন্ডি" },
    "area": "ধানমন্ডি",
    "address_line": "বাসা ১২, রোড ৫, ব্লক সি",
    "landmark": "আগোরার পাশে, ৩য় তলা",
    "postcode": null
  },
  "items": [{
    "variant_id": 40, "product_id": 9, "slug": "remote-control-car",
    "name": "Remote Control Car", "name_bn": "রিমোট কন্ট্রোল গাড়ি",
    "variant_name": "Red", "sku": "RCC-RED",
    "image": { "url": "https://…/160.webp", "alt": "…", "alt_bn": "…" },
    "quantity": 2, "unit_price": "1000.00", "discount": "200.00", "total": "1800.00"
  }],
  "coupon_code": "EID10",
  "totals": {
    "subtotal": "2000.00", "promotion_discount": "200.00", "coupon_discount": "0.00",
    "discount_total": "200.00", "shipping_charge": "130.00", "grand_total": "1930.00",
    "paid_total": "0.00", "due_total": "1930.00"
  },
  "timeline": [ { "stage": "placed", "at": "2026-11-12T10:15:00Z" } ]
}
```

- `stage` — the **customer-facing** status, never the internal one: `placed` (pending, and also
  an order held for a verification call — the customer is never told it was flagged) ·
  `confirmed` · `processing` (processing, ready_to_ship) · `shipped` · `delivered` (delivered,
  completed) · `cancelled` · `returned` (returned, partially_returned). Localise the label in the
  storefront from this code.
- `payment.status` — `unpaid` · `pending` · `partially_paid` · `paid` · `partially_refunded` ·
  `refunded` · `failed`. COD stays `unpaid` until the rider collects. `payment.next_action` is
  `null` for COD; an online method will return `{ "type": "redirect", "url": "…" }` (P4-PAY-02).
- `due_total` is what the rider collects ("Cash on delivery · ৳1,930.00 due").
- `timeline` — customer-visible events only, oldest first; `stage` codes as above.
- `image` may be `null`. `slug` may be `null` if the product was since deleted.
- Never present: cost prices, risk score/flags, internal notes, the full phone, the customer id.

## `GET /orders/{number}` — confirmation page

Headers: `X-Cart-Token` = the token of the cart that placed the order (keep it in a short-lived
httpOnly cookie after checkout; the cart is closed but the token still proves ownership of its
order). Valid for **30 days** after placement.

- 200 → `StorefrontOrder` with the **full** shipping address.
- 404 `not-found` → unknown number, wrong/missing token, or older than 30 days. Indistinguishable
  on purpose. (Fall back to the tracking form.)

## `POST /orders/track` — public tracking (rule 22)

Body: `{ "number": "TMB-26-00042", "phone": "5678" }`

| Field | Rule |
|---|---|
| `number` | required. `TMB-26-00042`; case, spaces and the `TMB-` prefix are forgiven (`26-00042`, `tmb 26 00042`, Bengali digits) |
| `phone` | required: the **last 4 digits** of the phone the order was placed with (B7.1), or the full number |

- 200 → `StorefrontOrder` **without the street address**: `shipping.address_line`, `landmark`
  and `postcode` are `null` and `shipping.name` is the first name only — a guessed 4-digit match
  must not reveal where a family lives. District/upazila/area, items, totals, timeline are present.
- 404 `not-found` → no order with that number **or** the phone does not match. The same response
  either way — never a hint which part was wrong ("That order number and those digits don't
  match. Please check the number in your SMS.").
- 422 `validation-error` → a field empty or malformed (`phone` must be 4 digits or a valid
  mobile number).
- 429 `too-many-requests` → more than 5 attempts in 10 minutes for this number from one client
  (or 20 for this number from anywhere). `Retry-After` in seconds.

## Geography for the form

Already served (docs/api/delivery.md): `GET /districts` (with `division`, `upazila_required`,
`city_option`) and `GET /districts/{id}/upazilas` (with `serviceable`). The "area" select's options
are not modelled server-side yet — the field is free text to the API.
