# Courier booking queue — backend contract (Phase 10, Figma B · 07 241:34046)

Admin screen: `/admin/shipments/booking` (`resources/js/admin/features/shipments/BookingQueuePage.tsx`).
Code: `app/Http/Controllers/Api/V1/Admin/BookingQueue/` (controller + `BookingQueueReadModel`),
resources in `app/Http/Resources/Admin/BookingQueue/`, routes in
`routes/api_v1_admin_booking_queue.php`.

The queue is **read-only**. It reads orders (Ordering) and shipments (Fulfilment) together, so the
read model lives in the HTTP layer — Fulfilment may not read Ordering's models (deptrac). Booking
goes through the existing endpoints in `docs/api/shipments.md`; nothing about how a booking is
executed changed:

- one order → `POST /orders/{order}/shipments` (the booking drawer, `CourierBookingPanel`);
- a selection → `POST /shipments/bulk`, **one call per courier** (the endpoint takes one
  provider), each with its own `Idempotency-Key` minted when the confirmation opens (rule 17).
  The shipments are created in their own transactions and `BookShipmentsBulk` runs on the
  `courier` queue after commit (rule 4). Bulk returns no consignment ids on either provider, so
  the rows move to "Submitted — awaiting ID" until a webhook or poll confirms them (rule 24d).

## Endpoints (`/api/v1/admin`, all need `shipments.view`)

| Method & path | Returns |
|---|---|
| `GET /shipments/booking-queue` | `BookingQueueSummary`: `production`, `cutoff_time` ("HH:MM", Dhaka), `today` (Dhaka date), `couriers[]`, `counts` |
| `GET /shipments/booking-queue/ready` | `BookingQueueOrder[]`, cursor-paginated (50, oldest id first). Any `filter[…]` or `sort` is a 400. |
| `GET /shipments/booking-queue/shipments?filter[tab]=submitted\|booked_today\|failed` | `BookingQueueShipment[]` (`shipment`: the full `Shipment`, plus `submitted_at`, `recipient_name`, `district_name`, `upazila_name`), cursor-paginated (50, newest first). Missing/unknown tab or any other filter is a 400. |
| `GET /shipments/booking-queue/unmapped` | `BookingQueueUnmappedArea[]` (not paginated) |

Booking needs `orders.update` + `shipments.book` (the existing endpoints' policy). Role-matrix tests:
`tests/Feature/BookingQueue/BookingQueueApiTest.php`.

No response carries a customer phone or street address (rule 20): only the recipient name and area.

## Tabs and counts

| Tab | Rows | Count |
|---|---|---|
| Ready to ship | orders with `status = ready_to_ship` (packed) and **no open shipment** — the same "open" rule `RequestShipment` enforces, so an order whose shipment failed or was cancelled is ready again. "Ready since" is `orders.packed_at`. | same |
| Submitted — awaiting ID | shipments `pending` (requested; booking job not yet run, or the circuit is open) or `submitted` (bulk or uncertain booking, no consignment id yet). The status chip tells the two apart. | same |
| Booked today | shipments whose `booked_at` falls on today's **Dhaka** date (rule 11, `DhakaDate::utcBounds`), whatever their status since | same |
| Failed | `Shipment::needsAttention()` — exactly what `GET /shipments/failed` lists (failed, or `completion_failed`); retry happens on that screen | same |
| Unmapped areas | one row per (courier, district, upazila) among **ready** orders where an **active** courier with `requires_location_mapping` has no explicitly mapped `courier_locations` row (`BookingLocationPreview` → `unmapped`; rule 32 — never matched by name). Up to five order numbers per area. | areas, not orders |

An order in an unmapped area stays under "Ready to ship" too — it is blocked only for that
courier and may go with another (Steadfast needs no zone ids).

## Courier cards

Per provider, from stored data only: `trust` (`verified` / `unverified` / `not_connected`, as the
booking panel), `is_active`, `blocked_reason` (`inactive` → `not_connected` →
`unverified_in_production`, rule 33a), `bookings_count` (shipments with a consignment id),
`booked_today`, `awaiting_id`, `webhook` and `balance`.

- `webhook.health`: `unsupported` (provider has no webhook), `none` (no verified receipt yet — not
  "healthy"), else from the latest `webhook_receipts` row for `courier:{code}`: `healthy` when it
  verified and processed without `error`, `failing` otherwise; `last_received_at` is its time.
- `balance` / `balance_checked_at`: the courier's own balance as stored on `courier_providers`;
  `null` when never checked, and the UI then shows nothing (never ৳0). No code writes it yet.

## Per-row preflight (`couriers[].blocked_reason`)

For each ready order and each courier, the first that applies (the same checks, in the same order,
as `BookingPreflight` and `POST /shipments/quote`):

1. provider: `inactive`, `not_connected`, `unverified_in_production`;
2. order: `missing_address` (no district), `not_serviced` (`DeliveryChargeResolver::zoneFor` = null),
   `unmapped_location`, `ambiguous_location` (several zones mapped — bulk cannot choose; the
   single-order drawer can), `missing_weight` (a line with no variant or a variant weight ≤ 0 — the
   parcel would be understated, and Steadfast's 1 kg step (rule 32a) makes that a real charge
   difference).

`suggested_provider_id` is the default courier when it can take the order, else the first that
can, else `null`. The UI pre-selects it, disables blocked couriers in the row's select with the
reason, and a row cannot be selected for bulk while its chosen courier is blocked.

**Known gap:** `missing_weight` is enforced by the queue UI only. `BookingPreflight` (and therefore
`POST /shipments/bulk` called directly) does not refuse a 0 g parcel.

Weight and COD are computed exactly as the booking snapshot (`EloquentShippableOrders`): variant
`weight_grams` × quantity; COD = the order's `due_total`, never negative.
