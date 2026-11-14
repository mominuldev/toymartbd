# Delivery charge and quote (P4-CRT-03, P4-STO-06)

One implementation computes the delivery charge: `App\Domain\Fulfilment\Services\DeliveryChargeResolver`
(DATABASE-DESIGN §5.1). The storefront estimator, the cart and checkout all call it, and checkout writes
its result to the order — so the figure on a product page and the charge on the order cannot disagree.
No client computes a charge, date or free-delivery figure (CLAUDE.md rule 1).

## Resolution order

1. A free-shipping promotion or coupon qualifies → **0** (`FreeShippingPromotion` seam; no promotions
   exist yet — Marketing binds its implementation in Phase 5, the resolver does not change).
2. Zone from district / upazila → `inside_dhaka_metro` | `dhaka_suburb` | `outside_dhaka`, or **not
   serviced**:
   - an upazila in `delivery.not_serviced_upazila_ids` → not serviced (no charge, no ETA; the storefront
     never blocks add-to-cart);
   - `upazilas.is_inside_dhaka_metro` → metro; `upazilas.is_dhaka_subcity` → suburb;
   - a district in `delivery.suburb_district_ids` → suburb;
   - a district in `delivery.metro_district_ids` **with no upazila** → metro (Dhaka city is not an upazila);
   - otherwise → outside.
3. Base charge for the zone from the **default active courier provider's** rate card
   (`courier_providers.base_charge_dhaka|suburb|outside`).
4. Weight surcharge: `per_kg_charge` for each **started** kilogram above `included_weight_grams`, on
   Σ `variant.weight_grams × quantity`. The allowance is per provider (Steadfast 1 kg, Pathao 2 kg — rule 32a).
5. Subtotal ≥ `delivery.free_shipping_threshold` → **0**.

ETA: an order placed before `delivery.cutoff_time` (Asia/Dhaka) leaves the same working day, otherwise the
next; the window is `delivery.eta_days[zone]` working days from dispatch. `delivery.non_working_weekdays`
(Friday) and `delivery.holidays` are skipped. Dates are Dhaka calendar dates.

All numbers are data: the `delivery.*` settings (seeded by `DeliverySeeder` from
`DeliverySettings::DEFAULTS`) and the `courier_providers` rate card. **PROVISIONAL** — the design's
placeholder values (৳70 / ৳100 / ৳130, free over ৳3,000, zone membership) await the owner.

## Admin editors

| Endpoint | Read | Write | Settings |
|---|---|---|---|
| `GET/PUT /api/v1/admin/settings/delivery` | `shipments.manage_rates` or `settings.manage_integrations` | `shipments.manage_rates` | `delivery.free_shipping_threshold` |
| `GET/PUT /api/v1/admin/settings/delivery-areas` (Store settings › Delivery areas & timing) | `settings.view` | `settings.update` **and** `shipments.manage_rates` | `delivery.cutoff_time`, `delivery.eta_days`, `delivery.metro_district_ids`, `delivery.suburb_district_ids`, `delivery.not_serviced_upazila_ids`, `delivery.cod_advance` |

`PUT /settings/delivery-areas` takes the whole document (`DeliveryAreas` schema): `cutoff_time` "HH:MM"
(Dhaka), `eta_days.{zone}.{min,max}` (0–30 working days, max ≥ min), the three id lists (ids must exist; a
district cannot be both metro and suburb) and `cod_advance` `{enabled, zones, threshold, amount}` (decimal
strings, advance < threshold; `enabled: false` stores `null` = no rule). `UpdateDeliveryAreaSettings` writes
only the keys that changed, in one transaction, and dispatches `DeliveryReferenceDataChanged`
(`delivery_areas`) only when something changed. It writes exactly what `DeliverySettings` reads, so the
next quote, the checkout COD-advance hold and `GET /settings/checkout` follow the save. Not editable here
yet: `delivery.non_working_weekdays`, `delivery.holidays`, and the per-upazila `is_inside_dhaka_metro` /
`is_dhaka_subcity` flags (geography data).

## Endpoints (storefront token, `storefront:read`)

| Endpoint | Cache | Returns |
|---|---|---|
| `GET /api/v1/storefront/delivery-quote?district_id=&upazila_id=&variant_id=&quantity=` | never (moves at the cut-off) | `DeliveryQuote` |
| `GET /api/v1/storefront/delivery-rates` | tag `delivery` | `DeliveryRates` — the published table |
| `GET /api/v1/storefront/districts` | tag `delivery` | 64 districts, Dhaka division first, with `upazila_required` and `city_option` |
| `GET /api/v1/storefront/districts/{id}/upazilas` | tag `delivery` | the district's upazilas by name, each with `serviceable` |

`delivery-quote` query: `district_id` required; `upazila_id` must belong to that district; `variant_id` an
active variant (optional — omitted, the parcel is empty); `quantity` 1–99 (default 1). Invalid → **422**
`validation-error`. No active courier provider → **503** `service-unavailable`.

```json
{
  "serviceable": true,
  "zone": "outside_dhaka",
  "district": { "id": 8, "name": "Chattogram", "name_bn": "চট্টগ্রাম" },
  "upazila": null,
  "upazila_required": false,
  "courier": { "code": "steadfast", "name": "Steadfast", "name_bn": "স্টেডফাস্ট" },
  "subtotal": "2450.00",
  "weight_grams": 1400,
  "base_charge": "130.00",
  "weight_surcharge": "20.00",
  "charge": "150.00",
  "standard_charge": "150.00",
  "free_shipping": { "applied": false, "reason": null, "threshold": "3000.00", "remaining": "550.00", "progress_percent": 81 },
  "eta": { "dispatch_date": "2026-11-16", "earliest": "2026-11-19", "latest": "2026-11-21", "min_days": 3, "max_days": 4,
           "cutoff_time": "17:00", "cutoff_passed": false, "timezone": "Asia/Dhaka" },
  "cod_advance": null
}
```

- Money is a decimal string. `charge` is what the customer pays (`"0.00"` when free); `standard_charge`
  is base + surcharge, struck through when free. `free_shipping.reason` is `threshold` or `promotion`.
- `serviceable: false` → `zone`, `courier`, the charges and `eta` are null.
- `upazila_required: true` → the district alone does not settle the charge (some upazila resolves to a
  different tier or is not serviced): ask for the upazila. `city_option` on a district means an empty
  upazila is the district's city.
- `cod_advance`: the advance a COD order of this value needs in this zone (`delivery.cod_advance`), or null.
  Stated on the product page; enforced at checkout (P4-CHK).

## Storefront

`components/delivery/DeliveryEstimator.tsx` (server) loads the cached reference data and renders
`DeliveryEstimatorClient`. Without JavaScript the estimator is a GET form to `/delivery-charge`, which
renders the quote server-side; with JavaScript it fetches `/api/delivery-quote` (a Route Handler holding
the token, rule 28) and remembers the district in `localStorage` (`tm_delivery`).
