# Checkout settings — `GET /api/v1/admin/settings/checkout`

Store settings › **Checkout & delivery rules** (Figma B · 38, node 241:50827). A **read model**:
it reports what checkout actually does, from the same code and settings checkout reads
(`App\Domain\Ordering\Services\CheckoutRules`). It stores nothing of its own.

| | |
|---|---|
| Permission | `settings.view` (super_admin, manager). 403 for every other role — `tests/Feature/CheckoutSettings`. |
| Writes | none here. Free-delivery threshold → `PUT /settings/delivery` (`shipments.manage_rates`). Zone charges → the default courier's rate card, `PUT /settings/couriers/{provider}/rates` (`shipments.manage_rates`). One source of truth each. |
| Admin screen | `/admin/settings/checkout` — editable only with `settings.update` **and** `shipments.manage_rates`. |

## Response (`CheckoutSettings`)

```json
{
  "guest_checkout": true,
  "cash_on_delivery": true,
  "online_methods": [],
  "order_notes": true,
  "order_note_max": 500,
  "risk_advance": { "available": false, "blocker": "no_bkash_gateway", "bkash_status": "not_registered", "hold_score": 70 },
  "cod_advance": { "zones": ["outside_dhaka"], "threshold": "5000.00", "amount": "200.00" },
  "free_shipping_threshold": "3000.00",
  "delivery_courier": "Steadfast",
  "delivery_charges": [
    { "zone": "inside_dhaka_metro", "charge": "70.00" },
    { "zone": "dhaka_suburb", "charge": "100.00" },
    { "zone": "outside_dhaka", "charge": "130.00" }
  ]
}
```

Money is a `DECIMAL(14,2)` string (rule 6).

## Why each Figma row is what it is

| Figma row | Status | Evidence |
|---|---|---|
| Guest checkout (no account needed) | **Fixed — always on** | `POST /storefront/checkout` finds or creates the customer by phone (`CheckoutCustomers`). The Next.js server calls it with its storefront token, never a customer session, so "accounts only" could not be enforced. |
| Cash on delivery available everywhere | **Fixed — always on** | `CheckoutRequest::acceptedMethods()` always includes `cod` (rule 33); online methods are added only while `OnlineGateways` enables them. With no live gateway, switching COD off would make checkout impossible. |
| Require advance when risk ≥ 40 (৳300 via bKash) | **Not available yet** | No bKash driver is registered (`PaymentGatewayRegistry`), and checkout has no partial-advance payment flow. A high score (≥ `RiskSettings::highThreshold()`) holds the order for verification instead — never cancels (rules 23, 24b). The separate zone/value COD advance (`delivery.cod_advance`) holds the order until staff arrange it by phone. |
| Free delivery inside Dhaka over ৳3,000 | **Real, editable** | `delivery.free_shipping_threshold`, read by `DeliveryChargeResolver`. It applies in **every zone**, not only inside Dhaka, and at **≥** the threshold — the screen says so. |
| Allow order notes | **Fixed — always on** | `customer_note` (≤ 500) is always accepted and stored. The storefront has no switch to hide the field, so turning notes off would reject or silently drop what a customer typed. |
| Inside Dhaka / Dhaka suburbs / Outside Dhaka | **Read-only here** | The customer is quoted the default courier's rate card (`CourierProvider::defaultForQuotes()`), plus a per-kg step. Edit it in Courier settings. |

## Open owner decisions

- **Customer charge vs courier cost.** Figma's caption separates "customer-facing delivery charges" from "courier cost to us". Today they are the same columns (the default courier's rate card). Separating them needs a new customer-charge table and a resolver change.
- **Risk-based advance.** Needs a payment gateway decision (bKash or other) and a partial-advance checkout flow.
- **Free delivery inside Dhaka only.** Today the threshold applies to every zone; a per-zone threshold would be a resolver change.
- Business values (charges, threshold) are placeholders until confirmed — `UI-UX-DESIGN-SPEC.md` §10.
