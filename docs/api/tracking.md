# Server-side tracking API

## 1. Overview

PRD K-01..K-03, SYSTEM-ARCHITECTURE §8 "Server-side tracking". The browser fires gtag (GA4) and the Meta Pixel with a browser-generated `event_id`. The Next.js server relays the same event to `POST /storefront/tracking/events`, and Laravel sends a server twin to Meta Conversions API with the **same** `event_id`. Conversions (`purchase`, `refund`) start on the server, from order events. Every event is written to `tracking_events` with its dispatch result for each destination. That table feeds the admin diagnostics screen.

Code: `app/Domain/Tracking/` (Actions, `Jobs/DispatchTrackingEvent`, `Services/DispatchPolicy`, `Services/TrackingSettings`, `Listeners/`), wired in `App\Providers\TrackingServiceProvider`.

### 1.1 Flow

1. **Browser event** (`view_item`, `add_to_cart`, `begin_checkout`): the storefront posts it with the shopper's `client` context and `consent`. `RecordTrackingEvent` writes a `source = browser` row; this is idempotent on `(event_id, event_name, source)`. A new row queues `DispatchTrackingEvent` on the `tracking` queue. The client context (IP, user agent, `_ga`, `_fbp`, `_fbc`) travels **only in the job payload** and is never stored in `tracking_events`.
2. **Checkout**: `POST /checkout` may carry a `tracking` object. After `PlaceOrder` commits, the controller calls `RecordOrderTrackingContext`, never inside the transaction. A failure there is reported and never fails the checkout.
3. **Purchase**: `RecordPurchaseConversion` listens to `OrderPlaced` **and** `OrderDelivered`. It acts on exactly one of them, the one named by `TrackingSettings::purchaseTrigger()` (`order_placed` by default). The listener is queued on `tracking` after commit.
   - On `OrderPlaced` it is delayed by `tracking.purchase_dispatch_delay_seconds` (20 s), so the checkout context usually lands first. If a web order's context is still missing on a real queue, the listener releases once more, for up to 2 minutes after placement, then falls back to server context.
   - `event_id` = UUIDv5(namespace `6f1c2a9e-3b7d-4e58-9a41-0c5d8e2f7b63`, `purchase:{order_number}`). The id is deterministic, so the order confirmation resource gives the browser the same id. Combined with the idempotent ingest, a trigger switched between placement and delivery still records **one** purchase per order.
   - `value` = order grand total, `currency` = `BDT`, `transaction_id` = order number, items = order lines (`item_id` = SKU, `price` = unit price).
4. **Refund**: `RecordRefundConversion` runs on `RefundPaid`. `event_id` = UUIDv5 of `refund:{refund_id}` and `value` = the refunded amount. It goes to GA4 only: Meta has no refund event.
5. **Dispatch** (`DispatchTrackingEvent`, queue `tracking`): 3 tries with backoff of 30 s and 120 s, honouring a provider `Retry-After`. **Never past 1 h.** A row older than `tracking.stale_after_seconds` (3600) is settled `skipped_stale` without sending, because a stale conversion is worse than a missing one (SYSTEM-ARCHITECTURE §7).
   - Each destination is settled independently. If GA4 is `sent` and Meta hit a 503, the retry sends only to Meta.
   - A 4xx is a terminal `failed`. Only `RetryableTrackingFailure` (transport error, 429, 5xx, Meta throttling) is retried.
   - When the job gives up, anything still `pending` becomes `failed`.

**Staff-entered orders** (`source` = admin, phone, facebook, whatsapp) have no browser context. A web checkout that sent no `tracking` object is in the same position. These orders are sent with **server context only**: no IP, no user agent, no click ids. Their consent is `DispatchPolicy::serverConsent()`: in `notice` mode both purposes are granted (the store's notice covers it), and in `opt_in` mode nobody opted in, so the order is `skipped_consent`. This is our decision, recorded here for the owner to confirm.

### 1.2 Dedup matrix (`DispatchPolicy::applies`)

Meta deduplicates browser and server events on `event_id` + `event_name`, so the server relays every event that has a Meta mapping. GA4's Measurement Protocol has **no** deduplication, so each GA4 event has exactly one sender.

| Event | Browser → GA4 (gtag) | Browser → Meta Pixel | Server → GA4 MP | Server → Meta CAPI |
|---|---|---|---|---|
| `page_view` | yes | yes (`PageView`) | — | — (not relayed) |
| `view_item` | yes | yes (`ViewContent`) | `not_applicable` | yes |
| `add_to_cart` | yes | yes (`AddToCart`) | `not_applicable` | yes |
| `begin_checkout` | yes | yes (`InitiateCheckout`) | `not_applicable` | yes |
| `purchase` | **never** | only if `fire_browser_purchase`, with `purchase_event_id` | yes | yes |
| `refund` | — | — | yes | `not_applicable` (no Meta event) |

### 1.3 Consent and gating (`DispatchPolicy::decide`, in this order)

1. The matrix says no → `not_applicable`.
2. Server-side tracking is off (`server_side_enabled`), there is no driver, or the destination is not enabled / has no credentials → `skipped_disabled`. Nothing is sent.
3. Consent is refused → `skipped_consent`. GA4 needs `analytics`; Meta needs `ads`. A refused purpose is never sent, not even "anonymised".

Consent mode is `opt_in` by default: nothing fires before the visitor accepts. BD guidance asks for prior consent and a cookie/tracking disclosure (docs/_research/agent4-bd-market-and-payments.md §7.10 rows 3–4). `notice` is available if the owner decides otherwise. The storefront resolves the visitor's choice into `{analytics, ads, mode, policy_version}` and sends it with every event and with checkout.

### 1.4 Settings

`TrackingSettings` is stored like `MessagingSettings`. Rows live in `settings` (group `tracking`, keys `tracking.*`) and fall back to `config/tracking.php` (env); a saved value wins. The GA4 API secret and the Meta access token use `is_encrypted` and are never returned: the admin view shows only set / source / a last-4 hint.

`UpdateTrackingSettings` saves the settings, writes an `audit_sensitive` `credential_changed` row (secrets appear only as "changed" / "cleared"), and, if anything changed, dispatches `RevalidateTrackingConfig`. That action queues `RevalidateStorefrontTags(['tracking-config'])` after commit (docs/api/revalidation.md).

`SendTestTrackingEvent` handles the admin "Send test event". It sends a synthetic purchase through `TrackingDestination::validate()` synchronously. It writes no row. It refuses Meta without a test event code, which would otherwise count a real conversion.

## 2. Storefront API

All endpoints require the storefront token (`storefront:read`). The Next.js server calls them from Server Components and Route Handlers only (rule 28). The API request's own IP is the storefront server's, so the shopper's IP and user agent come in `client`.

### 2.1 `GET /api/v1/storefront/tracking/config`

```json
{ "ga4_measurement_id": "G-XXXX", "meta_pixel_id": "1234…", "gtm_container_id": null,
  "consent_mode": "opt_in", "consent_policy_version": "2026-11",
  "purchase_trigger": "order_placed", "enabled": { "ga4": true, "meta": true } }
```

Public ids and flags only. An id is `null` and its `enabled` flag `false` unless the destination is enabled and the id is set. The GA4 API secret, the Meta access token and the Meta test event code never appear. Cache it under tag **`tracking-config`**; an admin settings save revalidates it.

### 2.2 `POST /api/v1/storefront/tracking/events` → `202 {"event_id": "…"}`

| Field | Rule |
|---|---|
| `event_id` | required UUID (also used for the Pixel event) |
| `event_name` | required, one of `view_item`, `add_to_cart`, `begin_checkout`. Anything else (including `purchase`, `refund`, `page_view`) → **422** `validation-error` |
| `occurred_at` | required date (ISO 8601). A future time is clamped to now |
| `event_source_url` | required http(s) URL, ≤ 2048. Stored without its query string; the driver gets the full URL |
| `value` | optional decimal string `^\d{1,12}(\.\d{1,2})?$` (never a float) |
| `currency` | optional, `BDT` only (defaults to `BDT` when `value` is set) |
| `items[]` | optional, ≤ 50: `item_id`, `item_name`, `price` (decimal string), `quantity` (1–999), optional `item_brand`, `item_category`, `item_variant` |
| `client` | required, keys exactly `ip`, `user_agent`, `ga_client_id`, `ga_session_id`, `fbp`, `fbc` (all nullable; `ip` must be an IP) |
| `consent` | required: `analytics` (bool), `ads` (bool), `mode` (`opt_in`\|`notice`), `policy_version` (nullable) |

- **Idempotent** on `(event_id, event_name)`: a repeat returns the same 202 and is not dispatched again. `event_id` is compared lower-cased.
- **Always 202** once valid. The dispatch outcome is visible only in the admin diagnostics.
- **Rate limit** `throttle:tracking-events`: `tracking.ingest_per_minute` (120) per storefront token per `client.ip` (falls back to the request IP) → 429.

### 2.3 Checkout and confirmation

- `POST /checkout` accepts an optional `tracking` object: `{ event_source_url?, client: {…}, consent: {…} }`, with the same `client` / `consent` rules as §2.2. `client` and `consent` are required whenever `tracking` is present. A malformed object is a 422 and no order is placed. The change is additive: omitting `tracking` changes nothing.
- The order resource (`StorefrontOrder`: checkout response and `GET /orders/{number}`) gains:

```json
"tracking": { "purchase_event_id": "<uuidv5 of purchase:{number}>", "fire_browser_purchase": true }
```

`fire_browser_purchase` is true only when `purchase_trigger = order_placed`. The browser then fires the **Meta Pixel `Purchase` only**, with `eventID = purchase_event_id`, and never a gtag `purchase`: GA4's purchase comes from the server (§1.2). `tracking` is `null` on public order tracking (`POST /orders/track`).

## 3. Data model and retention

### 3.1 `tracking_events` (migration `2026_11_29_100000`)

`id` · `event_id` UUID · `event_name` · `source` (`browser`\|`server`) · `customer_id` NULL · `session_id` (GA session id) NULL · `order_id` NULL · `value` DECIMAL(14,2) · `currency` · `payload` JSON · `consent_state` JSON · `ga4_status` / `ga4_response` JSON / `ga4_attempts` · `meta_status` / `meta_response` JSON / `meta_attempts` · `occurred_at` · `dispatched_at` · `created_at`.

Indexes: `(event_id)`, `(event_name, created_at)`, `(created_at)`, and UNIQUE `(event_id, event_name, source)` for idempotent ingest.

- Status values: `pending`, `sent`, `failed`, `skipped_consent`, `skipped_disabled`, `skipped_stale`, `not_applicable` (`DispatchStatus`).
- `*_response`: `{status, http_status, excerpt (≤ 2 KB), error, at}`.
- **Append-only.** The model throws on any update outside the dispatch columns (`*_status`, `*_response`, `*_attempts`, `dispatched_at`, `payload`) and on every delete. The table is never soft-deleted or pruned.
- **No raw PII (rule 20).** `payload` holds `transaction_id`, `event_source_url` (query string stripped), `items`, and, after dispatch, `sent.{ga4|meta}`: each driver's body *as sent*, with user data already SHA-256 hashed by the driver and then passed through `SentPayloadScrubber`. The scrubber redacts `client_ip_address`, `client_user_agent`, `fbp`, `fbc`, any credential-shaped key, and raw `phone` / `email` / `address` keys. Tests assert that the raw phone, email, name, IP and user agent never appear in a row.

### 3.2 `order_tracking_contexts` (migration `2026_11_29_100100`)

`order_id` UNIQUE · `client_ip` · `user_agent` · `ga_client_id` · `ga_session_id` · `fbp` · `fbc` · `event_source_url` · `consent` JSON · `redacted_at` · `created_at`. Written once per order by `RecordOrderTrackingContext` (idempotent). Read at dispatch time by `TrackingEventDataFactory`.

### 3.3 Retention

IP, user agent, GA ids, click ids and the page URL are personal data. `toymart:tracking:prune-contexts` runs daily at 03:40 and blanks these columns once a row is older than `tracking.context_retention_days` (**90 days**), stamping `redacted_at`. The `consent` record is kept as evidence of what the shopper agreed to. After redaction, a later purchase-on-delivery or refund for that order is sent with server context only.

Browser-event client context is never persisted. It exists only in the queued job payload, for at most the 1 h dispatch window, plus Horizon's failed-job retention.

`tracking_events` holds no personal data and is kept indefinitely as the diagnostics record. **Owner decision pending:** whether to cap its age, for example 13 months.


## 4. Providers

Drivers live in `app/Domain/Tracking/Destinations/` (rule 5: nothing else knows a provider's wire format). Every field rule below was read from the official page cited next to it on **2026-11-13**. Anything not confirmed there is marked **UNVERIFIED** here and `// UNVERIFIED:` in the code. No real GA4 property or Meta pixel exists yet; every test runs on `Http::fake` with fixtures shaped from these pages (`tests/Support/TrackingFixtures.php`).

| Key | Class | Use |
|---|---|---|
| `ga4` | `Ga4MeasurementProtocolDestination` | GA4 Measurement Protocol, web (gtag) stream |
| `meta` | `MetaConversionsApiDestination` | Meta Conversions API |
| `null` | `NullTrackingDestination` | CI: accepts, no network, PII-free summary as `sentPayload` |
| `log` | `LogTrackingDestination` | local dev: one PII-free log line per event |

### 4.1 Retry and failure rules (all drivers)

| Outcome | Result |
|---|---|
| Destination disabled or credentials missing | `SkippedDisabled`, no HTTP call |
| Event has no Meta equivalent (`refund`) | `NotApplicable`, no HTTP call |
| Connection error / timeout | throws `RetryableTrackingFailure` (the job retries) |
| HTTP 429 or 5xx | throws `RetryableTrackingFailure` with `httpStatus` and `Retry-After` if sent |
| Meta Graph error code 1, 2, 4, 17 or 341, whatever the HTTP status | throws `RetryableTrackingFailure`: the error-handling guide says "wait and retry" for these ([Graph error handling](https://developers.facebook.com/docs/graph-api/guides/error-handling)) |
| Any other 4xx | `Failed` with the provider's message. Never thrown. |

**Secrets.** `api_secret` and `access_token` never appear in `DispatchResult::sentPayload`, `responseExcerpt`, `error`, an exception message or a log line. GA4 only accepts `api_secret` in the query string, so a `ConnectionException` message (which contains the URL) is never passed on. Meta's `access_token` goes in the POST body, not the URL. Every response excerpt and error goes through `SecretScrubber`, which also caps it at 2 KB. Tests assert this for 4xx, 5xx, 429 and connection errors.

**No circuit breaker.** The only one in the codebase is `App\Domain\Fulfilment\Services\CourierCircuitBreaker`, and it is courier-specific (Fulfilment domain, courier config keys). The tracking queue's 3 tries and 1-hour staleness cut-off (SYSTEM-ARCHITECTURE §7) bound the damage when a provider is down. If a generic breaker is extracted to `app/Support` later, both drivers should use it.

**Money.** `value` and `price` arrive as decimal strings (rule 6). Both providers want a JSON number, so `DecimalNumber::toJsonNumber()` converts it. PHP encodes the shortest representation that round-trips, so `"1999.90"` goes on the wire as exactly `1999.9`. The class refuses any amount that would not round-trip (more than about 15 significant digits) rather than send a different number.

### 4.2 GA4 Measurement Protocol

Endpoint: `POST {base}/mp/collect?measurement_id={G-…}&api_secret={secret}`, JSON body. `base` is `https://www.google-analytics.com`, or `https://region1.google-analytics.com` for EU collection (config `tracking.ga4.base_url`). Source: [MP reference](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference) and [user-provided data](https://developers.google.com/analytics/devguides/collection/ga4/uid-data).

> **A 2xx proves nothing.** The MP "returns a 2xx status code if the HTTP request is received" and "doesn't return an error code if the payload is malformed, or if the data is incorrect or not processed" ([reference](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference)). So `Sent` from `send()` means "received". Only `validate()` checks a payload, which is why the admin "Send test event" uses it.

**`validate()`** posts the same body to `{base}/debug/mp/collect` and parses `validationMessages[]` (`fieldPath`, `description`, `validationCode`). An empty array gives `Sent`. Anything else gives `Failed`, with each message rendered as `CODE fieldPath: description` in `error`. Validation events "don't show up in reports". The validation server "does not check `api_secret`", so a wrong secret still passes. ([Validating events](https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events))

| Body field | From `TrackingEventData` | Rule / source |
|---|---|---|
| `client_id` | `gaClientId` | Must "match the ID generated by the Google Analytics tag on your website" ([sending events](https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events?client_type=gtag)). Accepts the raw `_ga` cookie `GA1.1.123.456` (reduced to `123.456`) or the bare `123.456`. |
| `client_id` when no cookie | derived | **Our decision; the docs do not cover this.** `client_id` is required, so we send a stable id in the same `#########.##########` shape: SHA-256 of `customer:{externalId}`, or `event:{eventId}` when there is no customer. GA counts this as a separate user, so attribution is lost for that visitor but nothing errors. **UNVERIFIED:** that GA treats a server-made id exactly like a tag-made one. |
| `user_id` | `externalId` | Optional, UTF-8. GA policy forbids PII here, so `externalId` **must be our customer id, never a phone or email** (agent 1: keep it that way). |
| `timestamp_micros` | `occurredAt` | Unix **microseconds**. Events "can be backdated up to 72 hours". Our queue drops anything older than 1 h anyway. |
| `consent.ad_user_data`, `consent.ad_personalization` | `consent.ads` | `"GRANTED"` / `"DENIED"` ([reference](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference)). |
| `user_data` | email, phone, names, city | Sent **only with ads consent** (it is ad data). Fields: `sha256_email_address[]`, `sha256_phone_number[]`, `address[{sha256_first_name, sha256_last_name, city, country}]` ([uid-data](https://developers.google.com/analytics/devguides/collection/ga4/uid-data)). |
| `events[0].name` | `name->ga4Name()` | `page_view`, `view_item`, `add_to_cart`, `begin_checkout`, `purchase`, `refund` |
| `params.currency` / `params.value` | `currency` / `value` | `value` is a number. "currency is required if you set value", so it defaults to `BDT` ([events reference](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference/events)). |
| `params.transaction_id` | `transactionId` | Order number (purchase and refund). |
| `params.items[]` | `items` | `item_id`, `item_name`, `price` (number), `quantity` (int), and `item_brand`, `item_category`, `item_variant` when present. |
| `params.page_location` | `eventSourceUrl` | — |
| `params.session_id` | `gaSessionId` | Only when it matches `^\d+$` ([reference](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference)). |
| `params.engagement_time_msec` | — | The reference recommends sending it alongside `session_id`. A server event has no measured engagement, so we send `1`. **Our decision.** |
| `params.debug_mode` | test mode only | **UNVERIFIED:** a gtag parameter for DebugView. It is not listed on the MP reference pages we could fetch. |

GA4 normalisation before SHA-256 (lowercase hex) is from [uid-data](https://developers.google.com/analytics/devguides/collection/ga4/uid-data):

- **Email:** lowercase, remove spaces. For `gmail.com` or `googlemail.com`, also remove the `.`s before `@`.
- **Phone:** E.164 **with** `+`: remove every non-digit, then prefix `+`. `01712345678` becomes `+8801712345678`.
- **First and last name:** remove digits and symbols, lowercase, trim.
- **City:** remove digits and symbols, lowercase, trim. **Not hashed.**
- **Country:** ISO 3166-1 alpha-2, `BD`. **Not hashed.**

**Note on the docs:** the gtag (web) tab of the MP reference did not render when fetched; only the Firebase variant came through. The web-stream request shape above (`measurement_id` and `api_secret` as query parameters, `client_id` in the body) is confirmed by the example request on the [uid-data](https://developers.google.com/analytics/devguides/collection/ga4/uid-data) page.

### 4.3 Meta Conversions API

Endpoint: `POST https://graph.facebook.com/{version}/{pixel_id}/events`, JSON body `{data:[event], access_token, test_event_code?}`. The version is config-only (`tracking.meta.graph_version`, env `TRACKING_META_GRAPH_VERSION`) and defaults to **v27.0**, the current Graph/Marketing API version, introduced 2026-11-04 ([changelog](https://developers.facebook.com/docs/graph-api/changelog)). It is also the version in the [using-the-api](https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api) examples.

The token goes in the body, not the URL. The page's prose says to use "the `access_token` query parameter", but its own curl example sends the token as a body field (`-F access_token=…`). We follow the example so the token never sits in a URL. **UNVERIFIED** until the first real send. If Meta rejects it, move it to the query and extend the URL scrubbing.

**Response.** Success is `{events_received, messages, fbtrace_id}` ([ads-pixel/events reference](https://developers.facebook.com/docs/marketing-api/reference/ads-pixel/events/)). `events_received ≥ 1` gives `Sent`. A 2xx without it gives `Failed`. An error is `{error:{message, type, code, error_subcode, error_user_msg, fbtrace_id}}` ([error handling](https://developers.facebook.com/docs/graph-api/guides/error-handling)), and becomes `error = "Meta error {code}/{subcode}: {error_user_msg ?? message} (fbtrace_id …)"`. "If any event in a batch is invalid, the request returns an error". We always send one event per request.

**Test mode / `validate()`.** These add the top-level `test_event_code` from settings. Meta has **no dry run**: test events "also flow into Events Manager and are used for targeting and ads measurement" ([using the API](https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api)). So without a configured test code, `validate()` and a test-mode `send()` return `Failed` ("set a test event code") and **send nothing**.

**Dedup.** Meta discards a server event whose `event_id` and `event_name` match a Pixel `eventID` and `event` received within 48 h ([dedup](https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events)). So `event_id` is always the browser's id, and `event_name` comes from `metaName()`, which must equal the Pixel's event name.

| Event field | From | Rule / source ([server event](https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event)) |
|---|---|---|
| `event_name` | `name->metaName()` | `PageView`, `ViewContent`, `AddToCart`, `InitiateCheckout`, `Purchase`. `refund` is null, so the result is `NotApplicable` and nothing is sent. |
| `event_time` | `occurredAt` | Unix **seconds**. "up to 7 days before you send". |
| `event_id` | `eventId` | The dedup key. |
| `action_source` | — | `website` |
| `event_source_url` | `eventSourceUrl` | "required for website events". |

| `custom_data` | From | Rule / source ([custom data](https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/custom-data)) |
|---|---|---|
| `value` | `value` | Number. "Required for purchase events". |
| `currency` | `currency`, else `BDT` | ISO 4217. "Required for purchase events". |
| `content_ids` | `items[].item_id` | — |
| `contents[]` | items | `{id, quantity, item_price}` |
| `content_type` | — | `product` when there are items. |
| `order_id` | `transactionId` | String. |
| `num_items` | sum of quantities | "Use only with `InitiateCheckout`". |

`user_data` follows the [customer information parameters](https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters) page. Hashed values are SHA-256 lowercase hex, sent as one-element arrays (arrays are allowed).

| Field | Hashed? | Normalisation |
|---|---|---|
| `em` | yes | Trim, lowercase. Gmail dots are **kept**: this rule is GA4-only. |
| `ph` | yes | Digits only, country code included, **no `+`**, no leading zeros. `01712345678`, `+8801712345678` and `+880 1712-345678` all become `8801712345678`, through `App\Support\PhoneNumber` (rule 30). |
| `fn`, `ln` | yes | Lowercase, punctuation and symbols removed, UTF-8. Bangla script is kept as is; Meta's own examples hash non-Latin names. |
| `ct` | yes | Lowercase, no punctuation, special characters **or spaces**. `Cox's Bazar` becomes `coxsbazar`. |
| `country` | yes | `bd`. "Include it even if all records are from one country". |
| `external_id` | yes | Trimmed. Meta gives no normalisation rule, only that it should match the format sent through other channels. If the browser Pixel ever sends `external_id`, it must hash the same string. |
| `client_ip_address` | **no** | A valid IPv4 or IPv6 address. Anything else is dropped, not sent. |
| `client_user_agent` | **no** | Unchanged. |
| `fbc` | **no** | `fb.{subdomainIndex}.{creationTime}.{fbclid}`. **Case-sensitive, never lower-cased** ([fbp and fbc](https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc)). |
| `fbp` | **no** | `fb.{subdomainIndex}.{creationTime}.{random}` |

The hash vectors in `tests/Unit/Tracking/UserDataHasherTest.php` include Meta's own worked examples (`john_smith@gmail.com`, `(650)555-1212`, `Mary`, `us`). That checks our SHA-256 and normalisation against the page itself.

### 4.4 Unverified, to confirm on the first real account

1. Meta `access_token` as a JSON body field rather than the query string (see §4.3).
2. GA4 `debug_mode` on MP events for DebugView.
3. A GA4 server-generated `client_id` when the `_ga` cookie is absent.
4. Real response bodies. Every fixture is built from the documented shape, not captured.
