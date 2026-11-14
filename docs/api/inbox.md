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
| `GET /inbox/unread-count` | inbox.view | The sidebar Inbox badge (owner decision 2026-11-15): `{count}` = **open** conversations with unread inbound messages (`unread_count > 0`) **or** waiting on a staff reply (`needs_human`), each counted once. Resolved conversations never count; a new inbound message reopens one and brings it back. Same number for every staff member (not per assignee). One COUNT, uncached. The admin polls it every 60 s and on window focus, and every inbox action refreshes it (its query key sits under the inbox list key). `App\Domain\Inbox\Queries\ConversationsNeedingAttention`. |

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

Read from `settings` first (`inbox.whatsapp.{driver,phone_number_id,access_token,app_secret,verify_token}`, `inbox.messenger.{driver,page_id,page_access_token,app_secret,verify_token}`, group `notification`, secrets `is_encrypted`), falling back to `config/inbox.php` (env). Not cached: a value saved in the admin applies to the next send and the next webhook without a worker restart.

Entered at **Admin → Integrations → Messaging → Inbox channels** (`resources/js/admin/features/settings/messaging/InboxChannelsSection.tsx`):

| Method & path | Permission | Notes |
|---|---|---|
| `GET /settings/inbox-channels` | settings.manage_integrations | `{whatsapp, messenger}`, each: `driver` (`log`\|`cloud`\|`messenger`) + `driver_source`, `account_id` (phone_number_id / page_id) + `account_id_source`, `send_token` / `app_secret` / `verify_token` as `{set, hint, source}` — the value is **never** returned, `hint` is `••••` + last 4 characters (only for 12+ characters, otherwise `••••`), `source` `settings`\|`env`\|null; `webhook_url` (absolute, from `APP_URL`: `/api/v1/webhooks/inbox/{channel}`); `webhook_ready`, `send_ready`, `verified` straight from `InboxChannels::summary()` (so the Integrations hub agrees). `verified` is always false. |
| `PUT /settings/inbox-channels` | settings.manage_integrations | Flat body: `whatsapp_driver` (`log`\|`cloud`), `whatsapp_phone_number_id` (digits), `whatsapp_access_token`, `whatsapp_app_secret`, `whatsapp_verify_token`, `clear_whatsapp_*`; `messenger_driver` (`log`\|`messenger`), `messenger_page_id` (digits), `messenger_page_access_token`, `messenger_app_secret`, `messenger_verify_token`, `clear_messenger_*`. A blank/absent secret **keeps** the saved one; `clear_*` removes it (env applies again); a value and its clear together → 422. A blank id removes the saved id. Choosing `cloud` / `messenger` needs the id and the send token to exist after the save (saved, typed or env) → otherwise 422 on that field. Returns the GET shape. |

Every change writes one `audit_sensitive` row per channel that changed: `credential_changed`, `before`/`after` with `scope: inbox_whatsapp|inbox_messenger`, driver and id before/after, and for a secret only `"changed"` / `"cleared"` — never a value or a hint. Nothing in this path logs. Saving never marks a channel verified (the drivers' `isVerified()` is false).

What the owner copies from Meta (once a Meta app, a WhatsApp Business account and/or a Facebook Page exist):

* **WhatsApp:** Phone number ID and an access token (a long-lived System User token, not the 24-hour temporary one) from the app's WhatsApp API setup; the App Secret from the app's basic settings. Invent a verify token (any long random string), save it here, then in the app's WhatsApp webhook configuration paste the callback URL shown on the card, enter the same verify token, and subscribe to `messages`.
* **Messenger:** the Page ID, a Page access token for that Page, the same app's App Secret, and a verify token you invent. In the app's Messenger webhook settings paste the Messenger callback URL, enter the verify token, and subscribe the Page to `messages`, `message_deliveries`, `message_reads`.
* Switch **Replies** to the Meta driver only once both ids and tokens are saved; until then replies are logged, not sent, while incoming messages still arrive as soon as the webhook is verified.

The Meta-console menu names above are from Meta's docs, not from a live account — check them against the real dashboard on first connection.
