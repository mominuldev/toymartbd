# Courier status ingestion, customer tracking, courier performance (Phase 6)

Companion to `shipments.md` (booking, `ApplyCourierStatus`). Code: `app/Domain/Fulfilment`
(`Actions/ReceiveCourierWebhook`, `Actions/RefreshShipmentStatus`, `Jobs/ProcessCourierWebhook`,
`Jobs/PollShipmentStatuses`, `Listeners/RecordCourierTrackingEvent`,
`Reports/CourierPerformanceReport`), `app/Console/Commands/Fulfilment/PollCourierStatusesCommand`.

## Webhooks — `POST /api/v1/webhooks/couriers/{provider}`

Unauthenticated by design. In this order, without exception:

1. `{provider}` must be a registered driver code with a `courier_providers` row, else **404**,
   nothing written.
2. The driver's `verifyWebhook(headers, rawBody)` on the **raw bytes**. False → **401**
   (`unauthenticated` problem), nothing parsed, nothing written (rule 19).
3. `parseWebhook()`. `null` (Pathao's `webhook_integration` handshake, store events, Steadfast
   notification types that carry no status) → acknowledged, `{"ignored": true}`, nothing written.
4. A `webhook_receipts` row, provider `courier:{code}`, `external_event_id` = the event id the
   driver derived, or `sha256:` + hash of the raw body. UNIQUE (provider, event id): a replay of a
   processed event is a no-op (`{"replayed": true}`). The stored payload is the parsed reference
   fields plus the body's top-level scalars minus anything named phone/name/address/email (rule 20).
5. `ProcessCourierWebhook` on the `courier` queue finds the shipment (provider + consignment id,
   else provider + our merchant ref = shipment number for a bulk-`submitted` parcel) and calls
   `RefreshShipmentStatus`, which **re-queries the provider's `status()`** and applies that answer
   through `ApplyCourierStatus`. The webhook body's status is never applied (rule 24c). For a
   submitted parcel the webhook's consignment id is used only after the provider answered for it.
   Provider unreachable → receipt left open (`result = deferred`), the job retries (30 s, 2 min,
   5 min, 15 min); the poll catches up after that.

**Acknowledgement.** 200 by default. A driver implementing the optional
`Contracts\ShapesWebhookResponse` sets the status and extra headers — Pathao needs **202** and
`X-Pathao-Merchant-Webhook-Integration-Secret` on every response (research §5.5).
`PathaoGateway` implements it: 202 and the integration header on every response, a 401
included; the handshake is acknowledged (202, nothing written) after its signature checks out.

## Reconciliation poll — `toymart:couriers:poll`, every 15 minutes

Every shipment in `submitted`, `booked`, `picked_up`, `in_transit`, `out_for_delivery`, `on_hold`
or `partially_delivered` whose `updated_at` is older than `couriers.status_poll.stale_minutes`
(default 60) and that was not polled within that window. Terminal (`delivered`, `returned`,
`cancelled`) and not-with-courier (`pending`, `failed`) shipments are never polled. At most
`max_per_run` (300) per run, in jobs of `chunk` (25) per provider on `courier`. A provider whose
circuit breaker is open is skipped; a thrown `status()` counts toward the breaker.

A shipment with no consignment id (bulk `submitted`, rule 24d) is resolved by our merchant ref
when the driver implements the optional `Contracts\LooksUpByMerchantRef`
(`statusByMerchantRef(string $ref): ?CourierStatusResult`, carrying `consignmentId`). Steadfast
documents `GET /status_by_invoice/{invoice}`; **`SteadfastGateway` does not implement it yet.**
Pathao documents no such lookup, so its submitted parcels wait for the webhook.

## Courier history — `courier_tracking_events`

Appended for every `ShipmentStatusChanged` (any source) except `pending`/`failed`.
`occurred_at` = the shipment's timestamp column for that status where one exists (set from the
courier's `occurredAt`), else the time of the move. UNIQUE (shipment_id, normalised_status,
occurred_at). Append-only.

## Customer tracking

`StorefrontOrder.shipment` (see `checkout.md`) on the confirmation page, the account order page
and public tracking (`POST /storefront/orders/track`, number + phone digits, rule 22). The
storefront `OrderTimeline` (Figma 121:1558) draws six steps — placed, confirmed, packed, handed to
courier, out for delivery, delivered — with the courier line (name · consignment · Track) under
"handed to courier" and the picked-up / in-transit times beneath it.

**Out-for-delivery message.** `ShipmentStatusChanged` with `to = out_for_delivery` queues the
`order.out_for_delivery` template (Notification's `QueueOrderNotifications::onShipmentStatusChanged`),
once per order. It names the **rider's** phone (owner decision 2026-11-13), never our hotline:
`{rider_phone}` is the number staff recorded on the shipment (`PATCH /shipments/{id}/rider` —
neither provider's API returns it), read through Ordering's `OrderRiderContact` (Notification
never imports Fulfilment) and re-read at send time; `notification_logs.payload` keeps it masked.
With no rider recorded, the `order.out_for_delivery.no_rider` variant goes instead — the same
message without the rider line (SMS EN 86 chars / 1 segment, BN 66 / 1 segment; WhatsApp
`order_out_for_delivery_no_rider`, which needs its own Meta approval). Either variant counts as the
one message: recording the rider afterwards never resends it.

## Courier performance — `GET /api/v1/admin/reports/couriers` (+ `/export`, CSV)

`reports.view` + `shipments.view`; export also `reports.export`. Filters (allowlist, else 400):
`filter[from]`, `filter[to]` (Dhaka dates, inclusive; default last 7 days), `filter[provider]`.

Cohort: shipments handed to a courier in the period — `booked_at`, or `pickup_requested_at` for a
submitted parcel, on a Dhaka date in range (rule 11); pending and failed excluded. Each counted in
its **current** status. Per row (`totals`, `providers[]`, `districts[]` by the order's shipping
district):

| Field | Definition |
|---|---|
| `booked` | every shipment in the cohort |
| `delivered` | delivered + partially delivered (`partially_delivered` also alone) |
| `returned`, `cancelled`, `in_flight` | current status; in flight = submitted … on hold |
| `delivery_rate` | delivered ÷ (delivered + returned) × 100, 2 dp; null when neither |
| `return_rate` | returned ÷ (delivered + returned) × 100, 2 dp |
| `average_days_to_deliver` | mean (delivered_at − handed over) in days, 1 dp |
| `average_charge` | mean `charge` over shipments with a known charge |
| `cod_expected` | Σ `cod_amount`, cancelled excluded |
| `cod_collected` | courier-reported `cod_collected`; a fully delivered parcel without it counts its `cod_amount`; a partial without it, 0 |
| `cod_outstanding` | Σ `cod_amount` still in flight — not yet collected from a customer |

"Collected" is the courier's hand, not our bank: remittance is the settlement's `cod_received`
(rule 10). Admin screen: Analytics → Couriers (`/admin/analytics/couriers`).
