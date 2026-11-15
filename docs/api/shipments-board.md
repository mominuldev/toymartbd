# Shipments & tracking board (P10, Figma B · 08)

Read-only endpoints behind `/admin/shipments`. Every route needs `shipments.view` (403 role matrix in
`tests/Feature/ShipmentsBoard`). Figures come from `App\Domain\Fulfilment\Reports\ShipmentsBoard` (rule 1);
dates are Dhaka calendar dates, inclusive (rule 11). Query strings are allowlisted through `ReportQuery`:
an unlisted parameter, filter or value is a 400 `bad-request` (rule 16).

| Route | Name | Query |
|---|---|---|
| `GET /api/v1/admin/shipments/board` | `shipments.board.index` | `filter[tab\|provider\|from\|to\|search]`, `cursor` |
| `GET /api/v1/admin/shipments/board/stats` | `shipments.board.stats` | `filter[from\|to\|provider]` |
| `GET /api/v1/admin/shipments/board/issues` | `shipments.board.issues` | none |
| `GET /api/v1/admin/shipments/{id}/tracking` | `shipments.tracking` | none |

## Board cohort and tabs

The board lists shipments **handed to a courier** on a Dhaka date in the range: `COALESCE(booked_at,
pickup_requested_at)`. Default range: the last 7 Dhaka days. Pending and booking-failed shipments are not
on the board (booking queue, failed bookings). Newest first by id, 25 per cursor page.

| Tab | `shipments.status` |
|---|---|
| `all` | the cohort (cancelled-after-booking appears here only) |
| `submitted` | `submitted` — bulk booking, no consignment id yet (rule 24d) |
| `in_transit` | `booked`, `picked_up`, `in_transit`, `out_for_delivery`, `on_hold` |
| `delivered` | `delivered`, `partially_delivered` |
| `returned` | `returned` — no "returning" status exists; a parcel on its way back stays in its last in-flight status |
| `failed_attempts` | `on_hold` (drivers map pickup/delivery failures there) or `delivery_attempts ≥ 2`; overlaps other tabs |

`meta.counts` holds a count per tab under the other filters; `meta.providers` the courier filter's options.
`filter[search]` is a prefix match on shipment number, order number or consignment id.

Row `cost.basis`: `settled` (Σ charges + COD fee + return charge of a matched, non-excluded
`courier_settlement_items` line), `recorded` (`shipments.total_cost`, not settled yet), `estimate`
(`CourierCostEstimator` from the provider's rate card for the order's delivery zone; `costIfRefused` for a
returned parcel), or `null` (cancelled, or no zone because the order has no district id — never guessed).
`order.phone` is E.164 with `customers.view_pii`, otherwise masked; no address is returned (rule 20).

## Stats

- `in_transit` — the range's cohort (handed over in the range, as the board; owner decision 2026-11-15):
  count and Σ `cod_amount` of `booked … out_for_delivery, on_hold`. A parcel handed over before the
  range and still on the road is not counted.
- `submitted` — the range's cohort: count, oldest `pickup_requested_at`, its age in minutes, `overdue`
  at ≥ 360 min.
- `success` — outcome-dated in the range: delivered = `delivered|partially_delivered` with `delivered_at`
  in range; returned = `returned` with `returned_at` in range; `rate` = delivered × 100 ÷ (delivered +
  returned), 1 dp; `null` when both are 0.
- `days_to_deliver` — mean of `delivered_at − COALESCE(booked_at, pickup_requested_at)` over the same
  delivered set, 1 dp; `by_zone` by our delivery zone (`DeliveryChargeResolver::zoneFor` on the order's
  district/upazila ids); orders without a district id count overall but in no zone.

## Issues (last 30 Dhaka days, today included)

- `returned` — returned parcels with `returned_at` in the window, Σ `total_cost`, top 2 `shipping_district`.
- `failed_attempts` — shipments with an `on_hold` tracking event in the window, or `delivery_attempts ≥ 2`
  updated in the window; `latest_provider_status` is the courier's raw status on the latest hold event.
- `unmapped` — `failed` shipments with `failure_code` `unmapped_location|ambiguous_location` (rule 32).
- `by_courier` — provider × area (`dhaka` = metro + suburb, `outside`, `unknown`): delivered, returned and
  rate, outcome-dated as above. Only buckets with an outcome appear.

## Tracking

Facts, `charges` as stored, `cost`, `can_print_label` (consignment id + `customers.view_pii`, matching the
label route), `events` newest first from `courier_tracking_events` with `source` and both times
(`occurred_at`, `received_at`) and `payload_keys` — top-level keys only, never values. `outcome` is set
for `partially_delivered`: delivered and came-back lines from the order's non-rejected returns; with none
recorded, `recorded = false` and both lists are empty (providers report the amount, not the lines).
