# Shipments and courier booking — backend contract (Phase 6)

Schema: `DATABASE-DESIGN.md` §3.6 (`shipments`, as-built notes there), transaction outline
§4.2–4.4. Code: `app/Domain/Fulfilment` (shipments, drivers, booking jobs), with the order
side in `app/Domain/Ordering/Listeners/Fulfilment` and the ledger side in
`app/Domain/Finance/Listeners/Courier`.

## Driver contract (rule 5)

`App\Domain\Fulfilment\Contracts\CourierGateway` — `code()`, `requiresLocationMapping()`,
`supportsBulk()`, `quote()`, `book()`, `bookMany()`, `status()`, `verifyWebhook()`,
`parseWebhook()`. DTOs in `app/Domain/Fulfilment/DTOs/`. Drivers are listed in
`config/couriers.php` → `drivers` and resolved by `CourierRegistry::for($code)` /
`forProvider($provider)`.

- A transport failure (timeout, connection error, 5xx) is **thrown**; a definite provider
  refusal is **returned** as `BookingResult::refused($error)`.
- Drivers are only ever called from queued jobs on the `courier` queue, never inside a
  database transaction (rule 4).
- `NullCourierGateway` (`app/Domain/Fulfilment/Drivers/`) is the CI fixture driver;
  `tests/Support/CourierFixtures.php` installs it under any provider code.

## Shipment number and merchant reference

`SHP-YY-NNNNNN` — two-digit **Dhaka** year, per-year counter (`shipment_number_sequences`,
locked `FOR UPDATE` inside RequestShipment). The shipment number is the
`ShipmentRequest::$merchantOrderRef` sent to the courier, so a retried booking of one
shipment always carries the same reference. A bulk-submitted shipment (no consignment id
yet) is found by `number` when the webhook/poll reports its id.

## Shipment status (`ShipmentStatus`)

`pending → booked | submitted | failed | cancelled` · `failed → pending (retry) | cancelled`
· `submitted → booked | picked_up | in_transit | out_for_delivery | on_hold | delivered |
partially_delivered | returned | failed | cancelled` · in the courier's hands (`booked`,
`picked_up`, `in_transit`, `out_for_delivery`, `on_hold`) → any later state and the outcomes
`delivered | partially_delivered | returned` · `partially_delivered → returned` ·
`delivered`, `returned`, `cancelled` are terminal. Couriers skip states, so every later state
is reachable directly. A move the table refuses is ignored by `ApplyCourierStatus` (a late or
replayed event never moves a delivered parcel backwards).

An order has at most **one open shipment** (anything but `failed` or `cancelled`).

## Flow

1. **Request** (`RequestShipment`, one transaction): lock the order; it must be `confirmed`,
   `processing` or `ready_to_ship` and have no open shipment; insert the shipment `pending`;
   commit; dispatch `BookShipment` on `courier`.
2. **Book** (`BookShipment` job, outside any transaction):
   - provider circuit open → the job is released back to the queue;
   - provider inactive → `failed` (`inactive_provider`);
   - production and `courier_providers.verified = false` → `failed` (`unverified_provider`,
     rule 33a);
   - provider `requires_location_mapping` and no explicitly mapped `courier_locations` row for
     the order's upazila (or several, and staff chose none) → `failed`
     (`unmapped_location` / `ambiguous_location`, rule 32 — never guessed);
   - order no longer shippable (cancelled meanwhile) → `failed` (`order_not_shippable`);
   - `book()` refused → `failed` (`provider_refused`, provider's message in `error_message`);
   - `book()` threw → retried with backoff; after the last try → `failed` (`transport`).
   - `book()` threw an exception implementing `ReportsUncertainBooking` with
     `bookingMayHaveSucceeded() = true` (Pathao `malformed`: an unreadable 2xx) → **never
     re-posted**: the shipment goes to `submitted` and is resolved by
     `LooksUpByMerchantRef::statusByMerchantRef()` when the driver has it, else by the
     webhook/poll (same as bulk).
   - accepted → `CompleteShipmentBooking` (one transaction): shipment `booked` (consignment
     id, tracking code, charge) → `ShipmentBooked` → the order is marked shipped through the
     same step as manual dispatch (`OrderDispatcher`: stock consumed via Inventory's
     `ConsumeReservedStock`, `sale_out`, `order_events`) → Finance writes `courier_charge`
     (out, recognised, cash; source `shipment`) after commit.
   - If marking the order shipped fails after the courier accepted, the consignment is kept
     on the shipment (`booked`, `failure_code = completion_failed`) and it appears in the
     retry queue; retry completes it **without** booking again.
3. **Bulk** (`RequestShipments` + `BookShipmentsBulk`): per-order request results; one
   `bookMany()` per provider; success → each shipment `submitted` with **no** consignment id
   (rule 24d). The order stays as it was until a webhook/poll confirms: `ApplyCourierStatus`
   with a result carrying `consignmentId` completes the booking then (order shipped, charge).
4. **Courier status** (`ApplyCourierStatus::handle(Shipment, CourierStatusResult, string $source)`
   — the entry point for webhook/poll processing):
   - same status → only `provider_status_raw` refreshed; refused move → ignored (returns the
     shipment unchanged);
   - a submitted (undispatched) shipment reaching any courier state is first completed as
     in step 2;
   - `delivered` → `ShipmentDelivered` → the order is delivered through the existing
     `MarkOrderDelivered` (COD collected, Finance recognises revenue on `OrderDelivered`) →
     Finance writes `courier_cod_fee` (out, recognised, cash; source `shipment`);
   - `returned` → `ShipmentReturned` → `RecordCourierReturn` (Ordering): `courier_return_in`
     movements at the snapshot cost, `quantity_returned`, order `returned`, pending COD
     payment cancelled → Finance writes `courier_return_charge` (source `shipment`) and,
     only if revenue had been recognised, reverses it (source `order_reversal`);
     `courier_charge` stays as a sunk loss (§4.4);
   - `partially_delivered` → recorded on the shipment and flagged on the order timeline for
     staff; settling the delivered lines is a staff step (no per-line data from providers).

## Admin endpoints (`/api/v1/admin`, Sanctum cookie session)

| Method & path | Permission | Notes |
|---|---|---|
| `POST /orders/{order}/shipments` | `orders.update` + `shipments.book` | **Idempotency-Key**. Body: `provider` (code, optional → default active provider), `courier_location_id` (optional; required when the upazila maps to several zones), `weight_grams` (optional override), `note`. 202 + `ShipmentResource` (`pending`). 409 `conflict` when the order is not shippable or already has an open shipment. |
| `POST /shipments/bulk` | `orders.update` + `shipments.book` | **Idempotency-Key**. Body: `order_ids[]` (≤ 100), `provider` (optional). 200, one result per id: `{order_id, result: requested|conflict|not_found, shipment_id, message}`; `meta.requested` / `meta.failed`. Requested shipments go to the courier in one `bookMany()` (→ `submitted`), or one by one when the driver has no bulk. |
| `GET /shipments` | `shipments.view` | Cursor-paginated. Filters (allowlist, else 400): `filter[status]`, `filter[provider]`, `filter[order_id]`, `filter[needs_attention]=1`. Sort: `-created_at` (default), `created_at`, `booked_at`, `-booked_at`. |
| `GET /shipments/failed` | `shipments.view` | The retry queue: `failed` shipments plus `completion_failed`. Same shape as the list. |
| `GET /shipments/{shipment}` | `shipments.view` | `ShipmentResource`, with `order` (`id`, `number`, `status`). |
| `POST /shipments/{shipment}/retry` | `orders.update` + `shipments.book` | **Idempotency-Key**. `failed → pending` and re-dispatches `BookShipment` for the same shipment (same number/reference); a `completion_failed` shipment is completed locally without a courier call. 409 otherwise. |
| `POST /shipments/{shipment}/cancel` | `shipments.cancel` | **Idempotency-Key**. Only `pending` or `failed` (nothing left the warehouse, no courier holds it). A booked/submitted parcel is cancelled at the courier and comes back as a courier return — the driver contract has no cancel call yet. 409 otherwise. |

`ShipmentResource`: `id`, `number`, `order_id`, `order` (when loaded), `provider`
(`{code, name}`), `status`, `provider_status_raw`, `consignment_id`, `tracking_code`,
`tracking_url`, `weight_grams`, `item_count`, `cod_amount`, `declared_value`, `charge`,
`cod_fee`, `return_charge`, `cod_collected`, `failure_code`, `error_message`,
`booking_attempts`, `needs_attention`, `dispatched`, `booked_at`, `dispatched_at`,
`picked_up_at`, `delivered_at`, `returned_at`, `cancelled_at`, `created_at`. Money as
`"1234.00"` strings. The UI must show `submitted` as "submitted", never "booked" (rule 24d).

## Circuit breaker

Per provider (`CourierCircuitBreaker`, cache-backed): `couriers.circuit_breaker.failure_threshold`
consecutive transport failures open it for `open_minutes`; while open, booking jobs release
themselves back to the queue instead of calling out. A provider answer (accepted or refused)
closes it.

## Idempotency

The `Idempotency-Key` middleware replays the HTTP response. Independently, a `BookShipment`
job that runs twice never books twice: it runs `WithoutOverlapping` per shipment, and the
shipment row is locked and its status re-checked (`pending`) before the courier is called;
the call itself is outside the transaction. Known gap: if a worker dies after the courier
accepted but before the result is written, a retry would book again — mitigated by sending the
same merchant reference (shipment number) every time; whether each provider rejects a
duplicate reference is not yet verified (no merchant account, rule 33a).
