# Unified inbox API (P10, Figma B · 22 241:43115)

WhatsApp, Messenger and live chat in one admin inbox. **Every channel is UNVERIFIED**: no WhatsApp Business account, Meta app or Facebook Page exists (2026-11-15). The Meta drivers are written from Meta's published docs and tested only against hand-authored fixtures (`tests/Feature/Inbox/fixtures`). Every channel defaults to the `log` driver, which sends nothing.

No AI: the frame's AI handover banner, AI-assistant bubbles, "Suggested reply" and the AI tab/chips are not built — no AI provider is chosen.

## Admin endpoints (`/api/v1/admin`, Sanctum cookie session)

| Method & path | Permission | Notes |
|---|---|---|
| `GET /inbox/conversations` | inbox.view | Cursor (`cursor`). Filters, allowlisted (anything else → 400): `filter[view]` all\|mine\|needs_human, `filter[channel]` whatsapp\|messenger\|live_chat, `filter[status]` open\|resolved, `filter[search]` name / 4+ phone digits / order-number prefix. `meta.counts` = tab counts under every filter except `view`. |
| `GET /inbox/conversations/{id}` | inbox.view | Header + customer panel: `orders_count`, `delivered_orders_count`, `lifetime_value` (ledger), `risk` = OUR score with `source: "ours"` (rule 24; null without risk.view), `last_order`. `customer` null without customers.view. |
| `GET /inbox/conversations/{id}/messages` | inbox.view | Newest first, 50 a page, `cursor` for older. |
| `POST /inbox/conversations/{id}/read` | inbox.view | Clears `unread_count`. |
| `POST /inbox/conversations/{id}/messages` | inbox.reply | `{body}` ≤ 4096. 202, `delivery_status: queued`; sent by a queued job after commit. **409 `conflict`** outside the 24-hour window — nothing queued. |
| `POST /inbox/conversations/{id}/notes` | inbox.reply | `{body}` ≤ 2000. 201. Never sent, allowed outside the window. |
| `POST /inbox/conversations/{id}/quick-replies` | inbox.reply | `{kind: product_card\|tracking_link, target_id}`. Server composes the text in the customer's language (bn by default). Product card: name + storefront link, **no price** (variant/promotion pricing lives elsewhere). Tracking link: only an order of the linked customer (409 otherwise), same short link as order notifications. Same window rule. |
| `POST /inbox/conversations/{id}/assign` | inbox.reply | `{user_id}` (active staff with inbox.reply) or `{user_id: null}`. System line. |
| `POST /inbox/conversations/{id}/resolve` · `/reopen` | inbox.reply | System line. A new customer message reopens automatically. |
| `GET /inbox/assignees` | inbox.reply | Active staff who hold inbox.reply. |

Phones are masked (`017****5678`) unless the viewer holds `customers.view_pii` (rule 20).

## Webhooks (`/api/v1/webhooks/inbox/{whatsapp|messenger}`)

* `GET` — Meta's subscription handshake: `hub.mode=subscribe`, `hub.verify_token` must equal the configured verify token → the `hub.challenge` is echoed as `text/plain`; otherwise 403.
* `POST` — `X-Hub-Signature-256: sha256=<hex HMAC-SHA256 of the raw body, keyed with the App Secret>` is verified on the raw bytes **before** anything is parsed or written; missing/invalid, or no app secret configured → 401, nothing written. A verified body is recorded once (`inbox_webhook_receipts`) and processed by a queued job. Message idempotency: the provider message id.

## What is verified vs assumed (Meta docs read 2026-11-15)

Verified against developers.facebook.com:
* Handshake params and challenge echo; `X-Hub-Signature-256` = `sha256=` + HMAC-SHA256 with the app secret — WhatsApp `documentation/business-messaging/whatsapp/webhooks/create-webhook-endpoint`, Messenger `docs/messenger-platform/webhooks`.
* WhatsApp inbound `messages` and `statuses` shapes (`docs/whatsapp/cloud-api/webhooks/payload-examples`, `…/webhooks/reference/messages/status`); BSUID users may arrive without `from`/`wa_id`.
* WhatsApp send `POST /{version}/{phone-number-id}/messages`, Bearer token, `messages[0].id` = accepted only (`…/whatsapp/messages/text-messages`, `docs/whatsapp/cloud-api/guides/send-messages`); text ≤ 4096; version in examples v27.0.
* 24-hour customer-service window; outside it templates only; error 131047 = "More than 24 hours have passed…" (`docs/whatsapp/cloud-api/support/error-codes`, `docs/whatsapp/pricing`).
* Messenger `object: page`, `entry[].messaging[]`, delivery `mids`/`watermark`, read `watermark`; Send API `POST /{page-id}/messages` with `messaging_type: RESPONSE`; 24-hour standard window; HUMAN_AGENT tag = 7 days (`docs/messenger-platform/send-messages`, `…/policy/policy-overview`).

Assumed (could not be confirmed):
* Messenger timestamps/watermarks are milliseconds (13-digit examples; unit not stated).
* The Page access token is accepted as a Bearer header (Meta's examples use `?access_token=`; we avoid putting it in a URL).
* Messenger text length limit — not found in the docs; we cap at 4096 like WhatsApp.
* Whether HUMAN_AGENT needs approval — not found; the tag is not used, Messenger replies stop at 24 h.
* Raw request bytes equal the signed bytes (Meta signs the escaped-unicode payload it sends).
* A WhatsApp call opening the window is not modelled — only inbound messages extend it.

## Credentials

Read from `settings` first (`inbox.whatsapp.{driver,phone_number_id,access_token,app_secret,verify_token}`, `inbox.messenger.{driver,page_id,page_access_token,app_secret,verify_token}`, group `notification`, secrets `is_encrypted`), falling back to `config/inbox.php` (env). No admin screen writes them yet.
