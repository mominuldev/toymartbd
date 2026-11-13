# Courier settings, booking preview and labels — admin contract (Phase 6)

Schema: `DATABASE-DESIGN.md` §3.6 `courier_providers`. Booking itself (request, bulk,
retry, cancel, the shipment list) is in `docs/api/shipments.md`.

## Settings → Integrations → Couriers (`/admin/integrations/couriers`)

| Method & path | Permission | Notes |
|---|---|---|
| `GET /settings/couriers` | `shipments.manage_rates` **or** `settings.manage_integrations` | Every provider (`CourierProviderSettings`): on/default flags, `trust` (`not_connected` while a required credential is missing, `unverified` while `verified = false`, else `verified`), `credential_fields[]` (`key`, `secret`, `set`, `hint` = `••••` + last 4 at most — **never the value, not even to super_admin**), `webhook_secret_set`, `webhook_url`, `rates`, `rate_card_updated_at`, `credentials_updated_at`, `balance`, `can.update_rates` / `can.update_credentials`. |
| `PATCH /settings/couriers/{id}` | `shipments.manage_rates` | `is_active` (bool) and/or `is_default: true` (also switches it on, clears the flag elsewhere). 422 when switching off the default (make another default first) or the last active provider — the default's rate card prices every storefront quote. `verified` cannot be set here (rule 33a). |
| `PUT /settings/couriers/{id}/rates` | `shipments.manage_rates` | The whole rate card as decimal strings (`^\d{1,5}(\.\d{1,2})?$`): `base_charge_dhaka/suburb/outside`, `per_kg_charge`, `return_charge`; `cod_fee_percent` (`^\d{1,2}(\.\d{1,2})?$`); `included_weight_grams` int 0–30000 in 100 g steps (Steadfast 1000, Pathao 2000 — rule 32a). Read by `DeliveryChargeResolver` on the next quote. |
| `PUT /settings/couriers/{id}/credentials` | `settings.manage_integrations` | `credentials` {key → value}: absent or blank **keeps** the stored value; `clear[]` removes keys; `webhook_secret` (≥ 16) / `clear_webhook_secret`. A key the provider does not use, or value + clear together, is 422. Encrypted at rest; audited (`credential_changed`, key names only). |
| `GET /settings/delivery` · `PUT /settings/delivery` | read: either permission above · write: `shipments.manage_rates` | `free_shipping_threshold` decimal string, or `null` = off (zero is refused). |

Pathao's official Merchant API issues its token from client id/secret **plus the merchant
username and password** (OAuth password grant). Storing those for that published flow is
allowed; rule 24a forbids only storing a portal login to scrape undocumented endpoints. The
screen says so next to the fields.

## Booking preview — `POST /shipments/quote`

`orders.update` + `shipments.book`. Body `{ orders: [{ id, weight_grams? }] }` (1–100). Read-only:
no transaction, no external call, no write. Response `ShipmentQuote`:

- `providers[]` — trust, `bookings_count` (shipments with a consignment id), balance, rates,
  `rate_card_updated_at`, `blocked_reason`: `inactive` | `not_connected` |
  `unverified_in_production` (rule 33a; only when `app()->environment()` is in
  `couriers.production_environments`) | null.
- `orders[]` — from `ShippableOrders` (Ordering's read seam): `can_ship`, recipient name,
  district/upazila, `zone`, `item_count`, `variant_weight_grams` (Σ variant weight × qty),
  `weight_grams` (the override if sent), `cod_amount` (what is still due), `declared_value`, and
  per provider `quotes[]`: `location_status` (`not_required` | `mapped` | `ambiguous` |
  `unmapped`, explicit `courier_locations` rows only — rule 32), `location_options[]`,
  `blocked_reason` (`missing_address` | `not_serviced` | `unmapped_location` | null) and
  `estimate` (`CourierCostEstimator`: base charge for the zone + the resolver's own
  per-started-kg surcharge, COD fee = `cod_fee_percent` of COD to the poisha, return charge,
  `total_if_delivered`, `cost_if_refused`). Estimates are from the **stored rate card, not a
  provider quote**; how each courier rounds its COD fee is unverified (no merchant account).
- `totals[]` per provider over the orders it can price.

## Shipping labels

`shipments.view` **and** `customers.view_pii` (the label carries the full phone and address the
courier needs — rule 20). One 100 × 150 mm page per shipment, mPDF + Hind Siliguri (Bangla
shaped), consignment id as text and Code 128 (mPDF built-in; no QR — `mpdf/qrcode` is not
installed), tracking code, recipient, phone, address + landmark, COD (or "prepaid"), item count,
weight, order and shipment numbers, note. `locale=en|bn` picks the captions (default `en`).

- `GET /shipments/{id}/label` — 409 `conflict` before a consignment id exists (a bulk
  `submitted` shipment has none — rule 24d).
- `POST /shipments/labels` `{ shipment_ids: [], locale? }` (≤ 100) — in request order; ids that
  do not exist or have no consignment yet are listed in `X-Shipments-Skipped`; 409 if none print.
