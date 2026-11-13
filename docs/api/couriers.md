# Courier drivers

One section per provider. Each driver implements `App\Domain\Fulfilment\Contracts\CourierGateway`
and ships a `statusMap()` covering every documented provider status (rule 5). Booking runs in
queued jobs on the `courier` queue after commit (rule 4). See also `docs/api/shipments.md`.

<!-- Pathao section: added by the Pathao driver work (agent 2). -->

**Status meanings — owner confirmed 2026-11-13.** The mapping of every provider status onto our
`ShipmentStatus` (the Steadfast table below; `PathaoStatusMap::MAP` for Pathao) is kept exactly as
built, including the deliberate choices: `*_approval_pending` is not final (revenue waits for
`delivered`, rule 10), Steadfast `cancelled` is a courier return, and anything unrecognised goes to
`on_hold` for a human, never a guess.

**Rider.** Neither provider's official API carries the delivery rider's name or phone: Steadfast's
status responses and webhooks carry `delivery_status` only, and its `/trackings_by_invoice`
timeline has a `deliveryman_id` (an internal id, `null` in every published example) but no name or
number; Pathao's documented webhooks and `order/info` carry none either. Nothing is captured
automatically — staff record the rider on the shipment (`PATCH /shipments/{id}/rider`, see
`shipments.md`). Scraping the merchant portal for it is forbidden (rule 24a).

## Steadfast

**Status: fixture-only.** No merchant account exists and Steadfast has **no sandbox** (rule 33a).
The driver is built from Steadfast's published Postman collection
(<https://documenter.getpostman.com/view/26211192/2sAYJ1mhpk>), the official `steadfast-it`
Laravel package and a verbatim copy of its API guide — see
`docs/_research/agent3-appbyte-and-couriers.md` §6. It is tested only against the hand-authored,
unverified fixtures in `tests/Fixtures/Steadfast/` (each names its source). `courier_providers.verified`
stays `false`, and **production booking through Steadfast is refused** — by the driver itself
(`SteadfastGateway::bookingBlockedReason()`) as well as by the booking job.

| | |
|---|---|
| Driver | `app/Domain/Fulfilment/Drivers/Steadfast/SteadfastGateway.php` |
| Base URL | `https://portal.packzy.com/api/v1` (`STEADFAST_BASE_URL`; `portal.steadfast.com.bd` is dead) |
| Auth | `Api-Key` + `Secret-Key` headers on every call, from `courier_providers.credentials` (`api_key`, `secret_key`), encrypted |
| Location mapping | **None** — free-text address only (`requiresLocationMapping() = false`, §3.6) |
| Quote | **No price API.** Our stored rate card: zone base charge covers 1 kg, +`per_kg_charge` per started kg above (rule 32a — never Pathao's 2 kg). COD fee = `cod_fee_percent` × (COD − delivery charge) |
| Book | `POST /create_order` — `invoice` = our shipment ref (Steadfast enforces uniqueness, so a retry cannot double-book) |
| Bulk | `POST /create_order/bulk-order`, body `{"data": "<JSON-encoded array>"}`, ≤ 500 per request → chunked. Result is **submitted** only; ids are never taken from it (rule 24d) |
| Status | `GET /status_by_cid/{id}` |
| By invoice | `GET /trackings_by_invoice/{invoice}` (consignment id) + `GET /status_by_invoice/{invoice}` (status) — resolves a bulk-submitted shipment (`LooksUpByMerchantRef`) |
| Balance | `GET /get_balance` → `current_balance` (can be negative) |
| Fraud lookup | `GET /fraud_check/{phone}` (official). Not called until credentials exist **and** the provider is verified; until then `CourierHistoryLookup::forPhone()` answers `connected: false` (rule 33b) |
| Webhook | `Authorization: Bearer <webhook_secret>` (official package). `X-Signature` HMAC-SHA256 of the raw body is checked when present (third-party sources only). Only `notification_type = delivery_status` becomes an event — a trigger to re-query `status()`; the body's money fields are never trusted (rule 19) |

### Field handling

- `recipient_phone`: 11 national digits (`01XXXXXXXXX`) from our E.164; an invalid number is refused locally.
- `recipient_address`: our address line plus `(Landmark: …)` (rule 31). The older guide caps the address at
  250 characters; if the landmark would overflow it, the landmark moves to the head of `note`. An address
  line alone over 250 characters is refused rather than cut by the courier.
- `{ } ; < >` are replaced with spaces before sending (Steadfast does it anyway).
- `consignment_id` is an integer on the wire and a **string** everywhere in our code (§3.6).
  `tracking_code` is uppercase hex of **variable length** (8–15 seen) and is never length-validated.

### Errors

| Answer | Driver behaviour |
|---|---|
| Timeout / connection error, 5xx, 429, non-JSON or consignment-less 200 | **Thrown** (`SteadfastUnavailable`) — the job retries; the unique invoice makes it safe |
| 401 / 403 | Returned as a refusal and **not retried** — ten auth failures in five minutes lock the key for an hour |
| Other 4xx | Returned as a refusal with the provider's message |
| Bulk entry with an `error` | That invoice is left out of `refs`; the error (double-decoded JSON string) is reported |

### Status map

Every value in the guide's verbatim table, plus the return-progress values reported only by
`/status_with_return_status_by_cid` and the newer guide's `exceptional`. `in_transit` is **not** a
Steadfast value (it appears only in fabricated sources). Anything unrecognised → `on_hold` for a
human, never a guess. Source of truth in code: `SteadfastStatusMap::MAP`.

| Steadfast `delivery_status` | Steadfast's description | Our `ShipmentStatus` | Why |
|---|---|---|---|
| `in_review` | Order is placed and waiting to be reviewed | `booked` | Always the status at creation |
| `pending` | Not delivered or cancelled yet | `in_transit` | Steadfast reports no separate pickup state |
| `hold` | Consignment is held | `on_hold` | |
| `delivered_approval_pending` | Delivered, waiting for admin approval | `out_for_delivery` | Not final until Steadfast confirms; revenue waits for `delivered` (rule 10) |
| `partial_delivered_approval_pending` | Delivered partially, waiting for approval | `out_for_delivery` | As above |
| `cancelled_approval_pending` | Cancelled, waiting for approval | `on_hold` | Needs a human until confirmed |
| `unknown_approval_pending` | Unknown pending status; contact support | `on_hold` | |
| `delivered` | Delivered and balance added | `delivered` | |
| `partial_delivered` | Partially delivered and balance added | `partially_delivered` | |
| `cancelled` | Cancelled and balance updated | `returned` | A courier return (§4.4): the parcel was with Steadfast |
| `unknown` | Unknown status; contact support | `on_hold` | |
| `partial_delivered_return_processing` / `_rider_assigned` / `_received` | Return leg of a partial delivery | `partially_delivered` | |
| `cancelled_return_processing` / `_rider_assigned` / `_received` | Return leg of a cancellation | `returned` | |
| `exceptional` | Lost, damaged or off the normal path (newer guide only) | `on_hold` | Handled defensively |

`status_by_trackingcode` may answer `in review` with a space; case, spaces and hyphens are
normalised to underscores before lookup.

### Unverified until a merchant account exists

- The shape of a `create_order` validation error (our fixture guesses Laravel's 422 body; the driver does not depend on it).
- Whether `cod_amount` as a decimal string (`"1060.00"`) is accepted (the official example sends `"3010"`).
- Webhook `X-Signature`, `Idempotency-Key` and retry timing (third-party only); the 60-second status cache; rate limits.
- Return charge: Steadfast's own pages contradict each other (agent4 §3.3) — `return_charge` stays a placeholder.
- The first live run: `get_balance` to validate credentials, then a real `create_order` with `cod_amount: 0`, cancelled
  immediately (research §6.1). Only after that does `verified` flip.
