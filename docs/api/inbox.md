# Unified inbox API (P10, Figma B · 22 241:43115)

WhatsApp, Messenger, live chat and the storefront Contact page form (`web_form`, inbound only — see *Website contact form* below) in one admin inbox. **Every channel is UNVERIFIED**: no WhatsApp Business account, Meta app or Facebook Page exists (2026-11-15). The Meta drivers are written from Meta's published docs and tested only against hand-authored fixtures (`tests/Feature/Inbox/fixtures`). Every channel defaults to the `log` driver, which sends nothing.

Suggested reply (Figma 539:83211, P10 AI for growth): `POST /inbox/conversations/{conversation}/ai-draft` (inbox.reply; body `{style?: default|shorter|english|bangla}`) returns `{text, language, based_on[], model, seconds, window_open, reply_state}`. It drafts only — nothing is sent or stored as a message. The prompt carries the last 10 text messages (no notes, redacted), the customer's first name when Integrations › AI allows names, and — for staff with customers.view — the linked customer's orders: number, status, courier, latest tracking status and time, district name and COD amount as display text. Never the phone number, address lines or payment details. A draft quoting a figure not in those facts is retried once, then refused (503 `ai-unavailable`). Outside the WhatsApp window the draft is still returned with `window_open: false`. Still not built: the AI handover banner, AI-assistant bubbles and the AI tab/chips.

## Admin endpoints (`/api/v1/admin`, Sanctum cookie session)

| Method & path | Permission | Notes |
|---|---|---|
| `GET /inbox/conversations` | inbox.view | Cursor (`cursor`). Filters, allowlisted (anything else → 400): `filter[view]` all\|mine\|needs_human, `filter[channel]` whatsapp\|messenger\|live_chat\|web_form, `filter[status]` open\|resolved, `filter[search]` name / 4+ phone digits / order-number prefix. `meta.counts` = tab counts under every filter except `view`. |
| `GET /inbox/conversations/{id}` | inbox.view | Header + customer panel (and every list row) carries `reply: {state, window_closes_at}` — see *Reply state* below. Header + customer panel: `orders_count`, `delivered_orders_count`, `lifetime_value` (ledger), `risk` = OUR score with `source: "ours"` (rule 24; null without risk.view), `last_order`. `customer` null without customers.view. |
| `GET /inbox/conversations/{id}/messages` | inbox.view | Newest first, 50 a page, `cursor` for older. |
| `POST /inbox/conversations/{id}/read` | inbox.view | Clears `unread_count`. |
| `POST /inbox/conversations/{id}/messages` | inbox.reply | `{body}` ≤ 4096. 202, `delivery_status: queued`; sent by a queued job after commit. Allowed in reply state `open` or `human_agent` (Messenger, sent with `tag: HUMAN_AGENT`; the message carries `tag: "HUMAN_AGENT"`). **409 `conflict`** in `template_only` / `closed` — nothing queued. |
| `GET /inbox/conversations/{id}/templates` | inbox.reply | Approved utility WhatsApp templates in the conversation's language (customer's `preferred_language`, Bangla when unknown; `meta.locale`). Each: `key` (library key), `template` (Meta name), `language` (`en`\|`bn`), `locale`, `name` (a staff-made template's name, else null — the admin labels system ones by event), `body` (approved copy with `{{1}}`, `{{2}}`… and the hotline/store URL filled in), `parameters[]` `{position, name, example}`. Empty on Messenger / live chat. Any query parameter → 400. |
| `POST /inbox/conversations/{id}/template` | inbox.reply | `{template, language: en\|bn, parameters: string[]}` — exactly as many values as the template has; each non-empty, ≤ 1024, no newline/tab, no 5+ spaces in a row. Only in reply state `template_only` (WhatsApp, window closed) → else **409**. A template that is not approved / not utility / unknown, or the wrong number of values → **422**. 202 with the message: `kind: template`, `body` = our rendering of the approved copy with the values, `template: {name, language}`, `delivery_status: queued`. No Idempotency-Key (replies take none either). |
| `POST /inbox/conversations/{id}/notes` | inbox.reply | `{body}` ≤ 2000. 201. Never sent, allowed outside the window. |
| `POST /inbox/conversations/{id}/quick-replies` | inbox.reply | `{kind: product_card\|tracking_link, target_id}`. Server composes the text in the customer's language (bn by default). Product card: name + storefront link, **no price** (variant/promotion pricing lives elsewhere). Tracking link: only an order of the linked customer (409 otherwise), same short link as order notifications. Same window rule. |
| `POST /inbox/conversations/{id}/assign` | inbox.reply | `{user_id}` (active staff with inbox.reply) or `{user_id: null}`. System line. |
| `POST /inbox/conversations/{id}/resolve` · `/reopen` | inbox.reply | System line. A new customer message reopens automatically. |
| `GET /inbox/assignees` | inbox.reply | Active staff who hold inbox.reply. |
| `GET /inbox/unread-count` | inbox.view | The sidebar Inbox badge (owner decision 2026-11-15): `{count}` = **open** conversations with unread inbound messages (`unread_count > 0`) **or** waiting on a staff reply (`needs_human`), each counted once. Resolved conversations never count; a new inbound message reopens one and brings it back. Same number for every staff member (not per assignee). One COUNT, uncached. The admin polls it every 60 s and on window focus, and every inbox action refreshes it (its query key sits under the inbox list key). `App\Domain\Inbox\Queries\ConversationsNeedingAttention`. |

Phones are masked (`017****5678`) unless the viewer holds `customers.view_pii` (rule 20).

## Live updates (Laravel Reverb, 2026-11-28)

With live updates on, the Inbox refreshes the moment something changes instead of on its polling timer.

- **What is pushed.** `App\Domain\Inbox\Events\InboxConversationChanged` is broadcast on the private channel `inbox` as `conversation.changed`, with the payload `{conversation_id}` and **nothing else**.
  - It is a signal, not data. The admin refetches through the endpoints above, which apply the viewer's own permissions, including phone masking.
  - So no personal data ever crosses the socket (rule 20), and server data stays in TanStack Query (rule 25a).
- **When.** The `Conversation` and `ConversationMessage` model hooks raise it on every create, every real update and every delete:
  - a message in or out
  - a delivery status
  - read, assign, resolve or reopen
  - a note or a template.
  
  A save that changes nothing raises nothing. It is dispatched **after commit** (`ShouldDispatchAfterCommit`), so a rolled-back write raises nothing. The push to Reverb runs on the `notify` queue (rule 4).
- **Who may listen.** `POST /broadcasting/auth` (body `{socket_id, channel_name}`; `channel_name` allowlist: `private-inbox`; 422 otherwise) is inside the admin session group: `auth:sanctum`, an active staff session and two-factor enrolment.
  - The channel itself is authorised by `ConversationPolicy::viewAny` (inbox.view) in `routes/channels.php`. Without inbox.view the answer is 403 `forbidden` (role matrix: `tests/Feature/Inbox/InboxLiveUpdatesTest.php`).
  - The response is the Pusher protocol's `{auth}`, so it is not a Resource.
  - Laravel's default web `/broadcasting/auth` route is **not** registered.
- **Admin.** `lib/realtime.ts` holds the Echo client, loaded lazily. It signs subscriptions through `api()` (cookie plus X-XSRF-TOKEN) and leaves `window.fetch` alone. `features/inbox/live.ts`, mounted in the Shell for inbox.view holders, works like this:
  - Pushes within 250 ms are coalesced.
  - It then invalidates the conversation lists (and with them the nav badge) and the named thread's detail and messages.
  - After a dropped connection it refetches everything in the inbox once.
  - While the socket is live, the 30 s list, 15 s thread and 60 s badge polls drop to a 5-minute safety net.
  - When live updates are off, or the socket is down, the admin polls exactly as before.
- **Turning it on.**
  - Server: `BROADCAST_CONNECTION=reverb` and the `REVERB_*` keys, then run `php artisan reverb:start` (supervised in production, behind the TLS proxy).
  - Admin build: the `VITE_REVERB_*` values. Without `VITE_REVERB_APP_KEY` the admin never opens a socket.
  - The admin CSP adds the Reverb `ws(s)://` origin to `connect-src` only while broadcasting is Reverb.
  - `REVERB_ALLOWED_ORIGINS` defaults to `APP_URL`'s host.
  - With `null` or `log`, nothing is queued (`broadcastWhen`).

## Reply state (restarting a closed conversation)

`App\Domain\Inbox\Services\ReplyWindow` decides, for the resource, the Actions (before queueing and again under the row lock) and the send job (once more before calling Meta):

| `reply.state` | When | What may be sent | `window_closes_at` |
|---|---|---|---|
| `open` | Live chat always; WhatsApp / Messenger within 24 h of the customer's last message | A normal reply | End of the 24-hour window (null on live chat) |
| `human_agent` | Messenger, 24 h – 7 days after the customer's last message, **and** the switch `inbox.messenger.human_agent` is on | A normal reply, sent with `messaging_type: MESSAGE_TAG, tag: HUMAN_AGENT` | 7 days after the customer's last message |
| `template_only` | WhatsApp after the 24-hour window (or a thread the customer never wrote in) | Only an approved template (`POST …/template`) | When the 24-hour window ended (null if it never opened) |
| `closed` | Messenger past 24 h with the switch off, or past 7 days; **always** on `web_form` (no sending channel) | Nothing (notes still allowed) | When the 24-hour window ended (null on `web_form`) |

* **Switch:** `settings` key `inbox.messenger.human_agent` (group notification, set at Admin → Integrations → Messaging) falling back to `config('inbox.messenger.human_agent')` (env `MESSENGER_HUMAN_AGENT`), default false. Not cached: switching it off fails the next queued tagged reply (`outside_window`) without calling Meta.
* **Templates offered** come from the Notification domain's library through `App\Domain\Notification\Contracts\WhatsAppTemplateLibrary` (deptrac: Inbox → NotificationContracts only): WhatsApp channel, `whatsapp_status = approved`, active, not archived, a `provider_template` set, and category `order_updates` (utility). **Never** `security` (OTP — rule 32b) and **never** `marketing` (the library has no setting allowing marketing in the inbox). Positional parameters: a system template's `whatsapp_parameters` from `TemplateCatalog`; a custom template's body variables in order of appearance.
* **The send job** re-reads the template from the library: withdrawn approval → `failed`, `failure_code: template_unavailable`; a template in a thread whose window reopened, or on another channel → `template_not_allowed`. Nothing is sent to Meta in either case. Delivery statuses (`sent`/`delivered`/`read`/`failed`) apply to a template exactly as to a reply (matched on the wamid).
* WhatsApp payload: `POST /{version}/{phone-number-id}/messages` `{messaging_product: whatsapp, recipient_type: individual, to, type: template, template: {name, language: {code}, components: [{type: body, parameters: [{type: text, text}]}]}}` — no `components` for a template without parameters.

## Website contact form (`web_form`, owner decision 2026-11-16)

The storefront Contact page's "Send us a message" form. **Inbound only**: there is no channel to reply through, so the reply state is always `closed` (`POST …/messages` and `…/template` → 409, quick replies 409, nothing queued); internal notes work as usual. The admin composer says "Sent from the website contact form. Reply by WhatsApp or call {phone}." Its driver (`NoReplyChannelDriver`, name `none`) refuses every send, so even a queued id that reached the job could never be "sent" (the log driver is never used for it). No webhook, no service window, no credentials; `InboxChannels::summary()` lists it with `driver: none` and every readiness flag false.

**`POST /api/v1/storefront/contact-messages`** — the Next.js server's storefront token (`storefront:read`; none → 401), forwarding the shopper's IP (`X-Forwarded-For`, trusted only from `TRUSTED_PROXIES`).

| Field | Rule |
|---|---|
| `name` | required, string ≤ 80 |
| `phone` | required, a Bangladeshi mobile 013–019 (rule 30: `/^(?:\+?880|0)1[3-9]\d{8}$/`, so 010/011/012 and 06x are refused); stored E.164 |
| `topic` | required, one of `choosing_toy`, `order`, `delivery`, `returns`, `gifts_bulk`, `other` (`App\Domain\Inbox\Enums\ContactTopic`) |
| `order_number` | optional, ≤ 32, an order number as tracking accepts it (`TMB-26-00042`, `26-00042`, Bengali digits); stored canonical |
| `message` | required, 10–500 characters |
| `locale` | optional, `en` \| `bn` (accepted; not stored — the inbox has no per-thread language column) |

* **202** `{"status": "received"}`. **422** `validation-error` problem with per-field `errors[]` (an invalid phone is not echoed). **429** `too-many-requests` with `Retry-After`: at most **5 an hour per phone** and **10 an hour per client IP** (limiter `contact-message`, `ApiServiceProvider::CONTACT_MESSAGE_PER_*`).
* `App\Domain\Inbox\Actions\ReceiveContactFormMessage` hands an `InboundMessage` to `IngestInboundMessage` on `web_form`: thread id `web:{E.164}` (every message from one phone joins one conversation; a resolved one reopens), a fresh `web-{ULID}` message id, `contact_name` = name, `contact_phone` = the E.164 phone, the customer linked when one has that phone, `needs_human` set. Body: `Topic: {English label} · Order: {number}` (order part only when given), a blank line, then the message.

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
* WhatsApp template send: `type: template` with `template.name`, `template.language.code`, and body `components[].parameters[]` of `{type: text, text}` in positional order (`docs/whatsapp/cloud-api/guides/send-message-templates`); a template may be sent outside the 24-hour window, a free-form message may not.
* Messenger outside 24 h: `messaging_type: MESSAGE_TAG` with `tag: HUMAN_AGENT`, allowed up to 7 days after the user's last message, for a human agent's reply only (`docs/messenger-platform/send-messages/message-tags`).

Assumed (could not be confirmed):
* Messenger timestamps/watermarks are milliseconds (13-digit examples; unit not stated).
* The Page access token is accepted as a Bearer header (Meta's examples use `?access_token=`; we avoid putting it in a URL).
* Messenger text length limit — not found in the docs; we cap at 4096 like WhatsApp.
* **Whether the HUMAN_AGENT tag requires Meta app review (or a specific permission) is NOT confirmed** — not found in the docs read. That is why it sits behind the `inbox.messenger.human_agent` switch, off by default; turn it on only after the Meta app is cleared to use it.
* Meta's limits on template body parameters (no newline/tab, at most 4 consecutive spaces) are from memory of Meta's error 132018 text, not re-read; we enforce them in the FormRequest. Per-parameter length: not found — we cap at 1024 (Meta's template body limit).
* A WhatsApp template can be sent to a BSUID-only thread (no phone) the same way as a reply (`to` = the thread id) — not confirmed.
* Language codes `en` and `bn` (Meta's code for Bengali; owner decision 2026-11-16) are the codes the order notifications send with; they must match the languages the templates were approved in on the WhatsApp Business Account.
* "Approved" is what staff recorded in Message templates (or a sync from Meta); the inbox never asks Meta at send time. A template paused or disabled at Meta but still marked approved here fails at Meta (`provider_error:{code}`).
* Raw request bytes equal the signed bytes (Meta signs the escaped-unicode payload it sends).
* A WhatsApp call opening the window is not modelled — only inbound messages extend it.

## Credentials

Read from `settings` first (`inbox.whatsapp.{driver,phone_number_id,access_token,app_secret,verify_token}`, `inbox.messenger.{driver,page_id,page_access_token,app_secret,verify_token}`, group `notification`, secrets `is_encrypted`), falling back to `config/inbox.php` (env). Not cached: a value saved in the admin applies to the next send and the next webhook without a worker restart.

Entered at **Admin → Integrations → Messaging → Inbox channels** (`resources/js/admin/features/settings/messaging/InboxChannelsSection.tsx`):

| Method & path | Permission | Notes |
|---|---|---|
| `GET /settings/inbox-channels` | settings.manage_integrations | `{whatsapp, messenger}`, each: `driver` (`log`\|`cloud`\|`messenger`) + `driver_source`, `account_id` (phone_number_id / page_id) + `account_id_source`, `send_token` / `app_secret` / `verify_token` as `{set, hint, source}` — the value is **never** returned, `hint` is `••••` + last 4 characters (only for 12+ characters, otherwise `••••`), `source` `settings`\|`env`\|null; `webhook_url` (absolute, from `APP_URL`: `/api/v1/webhooks/inbox/{channel}`); `webhook_ready`, `send_ready`, `verified` straight from `InboxChannels::summary()` (so the Integrations hub agrees). `verified` is always false. Also `business_account_id` + `business_account_id_source` (WhatsApp; null on Messenger), `human_agent` + `human_agent_source` (Messenger; false on WhatsApp), and `last_check` (the last *Test connection*, or null — see below). |
| `PUT /settings/inbox-channels` | settings.manage_integrations | Flat body: `whatsapp_driver` (`log`\|`cloud`), `whatsapp_phone_number_id` (digits), `whatsapp_access_token`, `whatsapp_app_secret`, `whatsapp_verify_token`, `clear_whatsapp_*`; `messenger_driver` (`log`\|`messenger`), `messenger_page_id` (digits), `messenger_page_access_token`, `messenger_app_secret`, `messenger_verify_token`, `clear_messenger_*`. A blank/absent secret **keeps** the saved one; `clear_*` removes it (env applies again); a value and its clear together → 422. A blank id removes the saved id. Optional: `whatsapp_business_account_id` (digits; absent = keep, blank = remove) and `messenger_human_agent` (boolean; absent = keep) — both audited like the id. Choosing `cloud` / `messenger` needs the id and the send token to exist after the save (saved, typed or env) → otherwise 422 on that field. Returns the GET shape. |

Every change writes one `audit_sensitive` row per channel that changed: `credential_changed`, `before`/`after` with `scope: inbox_whatsapp|inbox_messenger`, driver and id before/after, and for a secret only `"changed"` / `"cleared"` — never a value or a hint. Nothing in this path logs. Saving never marks a channel verified (the drivers' `isVerified()` is false).

What the owner copies from Meta (once a Meta app, a WhatsApp Business account and/or a Facebook Page exist):

* **WhatsApp:** Phone number ID and an access token (a long-lived System User token, not the 24-hour temporary one) from the app's WhatsApp API setup; the App Secret from the app's basic settings. Invent a verify token (any long random string), save it here, then in the app's WhatsApp webhook configuration paste the callback URL shown on the card, enter the same verify token, and subscribe to `messages`.
* **Messenger:** the Page ID, a Page access token for that Page, the same app's App Secret, and a verify token you invent. In the app's Messenger webhook settings paste the Messenger callback URL, enter the verify token, and subscribe the Page to `messages`, `message_deliveries`, `message_reads`.
* Switch **Replies** to the Meta driver only once both ids and tokens are saved; until then replies are logged, not sent, while incoming messages still arrive as soon as the webhook is verified.

The Meta-console menu names above are from Meta's docs, not from a live account — check them against the real dashboard on first connection.

## Meta integrations: notification driver, delivery statuses, test connection, template sync (2026-11-16)

Still **UNVERIFIED** — no Meta app, WhatsApp Business Account or Page exists. Everything below is built from Meta's docs and tested with `Http::fake` (`tests/Feature/Notification/WhatsAppCloudNotificationsTest.php`, `WhatsAppTemplateSyncTest.php`, `tests/Feature/InboxSettings/InboxChannelConnectionTestTest.php`). A passing test or sync never flips `verified`.

### One WhatsApp number

The order notifications' `cloud` WhatsApp driver (`App\Domain\Notification\Drivers\CloudWhatsAppGateway`) and the template sync use the **Inbox's** WhatsApp credentials (`inbox.whatsapp.phone_number_id`, `access_token`, `business_account_id`). Notification may not read Inbox internals (deptrac), so it asks `App\Domain\Notification\Contracts\WhatsAppCloudAccount` (NotificationContracts layer); the Inbox implements it (`App\Domain\Inbox\Services\InboxWhatsAppCloudAccount`) and `NotificationServiceProvider` binds it. The secret is stored once.

* Driver choice: `messaging.whatsapp_driver` in `settings` (Admin → Integrations → Messaging → WhatsApp, `PUT /settings/messaging` `whatsapp_driver: log|cloud`; absent = keep), falling back to `notifications.whatsapp.driver` (`WHATSAPP_DRIVER`: `log`\|`null`\|`cloud`). Default `log`. Choosing `cloud` → 422 unless the Inbox phone number id and access token exist. The WhatsApp-first switch (`whatsapp_enabled`) stays separate and off by default.
* Send: `POST /{version}/{phone-number-id}/messages`, Bearer token, `{messaging_product: whatsapp, recipient_type: individual, to: <E.164 without +>, type: template, template: {name: provider_template, language: {code}, components: [{type: body, parameters: [{type: text, text}]}]}}` (no `components` without parameters). `messages[0].id` is stored as `notification_logs.provider_message_id`. A 4xx is a rejected `WhatsAppResult` (`provider_error:{code} {title}`) → the job marks the log `failed` and queues the SMS fallback once; a connection error, 429 or 5xx throws and the job retries. Only a masked number is ever logged; the token and URL never are.

### Delivery statuses for notifications

WhatsApp `statuses` for template sends arrive at the Inbox webhook (same number). `ApplyDeliveryStatus` (Inbox) applies a status to a conversation message as before; when the wamid is **not** an inbox message it dispatches `App\Domain\Inbox\Events\WhatsAppMessageStatusReceived` (provider message id, `sent|delivered|read|failed`, timestamp, error code/title; `ShouldDispatchAfterCommit`). Notification's listener (`ApplyWhatsAppDeliveryStatus` → `RecordWhatsAppDeliveryStatus`) updates the `notification_logs` row with that id, under a row lock:

* New columns (migration `2026_12_07_730000`): `delivery_status` (`sent`\|`delivered`\|`read`\|`failed`), `delivered_at`, `read_at`, `delivery_error`; index `(channel, provider_message_id)`. `status` (our send attempt, what spend counts) is unchanged.
* Forward only: sent → delivered → read, or failed before delivery. `read` never becomes `delivered`; a late `failed` after delivery is ignored; replays change nothing.
* `failed` (e.g. 131026 undeliverable, 131049 ecosystem limit) queues the same message over SMS once (`QueueSmsFallback`, which refuses a second fallback).

### Test connection

| Method & path | Permission | Notes |
|---|---|---|
| `POST /settings/inbox-channels/{whatsapp\|messenger}/test` | settings.manage_integrations | No body; `throttle:5,1`. One read-only Graph call with the **saved** credentials, outside any transaction: WhatsApp `GET /{phone-number-id}?fields=display_phone_number,verified_name,quality_rating`; Messenger `GET /{page-id}?fields=name`. Always 200 with `{channel, ok, checked_at, display_name, display_number, quality_rating, error_code, error_message}`. `error_code`: `not_configured` (no call made), `unreachable`, `meta_{graph code}` (e.g. `meta_190` token expired/invalid), `http_{status}`. `error_message` = Meta's `error_user_msg`/`message` with the token scrubbed, ≤ 300 chars. Stored as `inbox.{channel}.last_check` (plain JSON) and shown as `last_check` on `GET /settings/inbox-channels`. Audited: `credential_changed`, scope `inbox_connection_test`, `{channel, result: ok\|failed, error_code}` (activity log kind `connection_tested`). **Never** marks the channel verified. |

### WhatsApp template sync

| Method & path | Permission | Notes |
|---|---|---|
| `POST /notifications/templates/whatsapp/sync` | settings.update (the `NotificationTemplatePolicy::update` that gates `PUT …/whatsapp-status`) | No body; `throttle:5,1`. Reads `GET /{waba-id}/message_templates?fields=name,language,status,category&limit=100` with the Inbox access token, following `paging.next` (only to the configured Graph host) or the `after` cursor, ≤ 20 pages — all before any write. Each local WhatsApp template with a `provider_template` is matched on Meta `name` per language (`en`; Bengali as `bn` or `bn_BD`); the combined status is the **less sendable** of the two languages, a language missing on Meta reads as `draft`, an unrecognised Meta status leaves the template alone. Changes go through `SetWhatsAppApprovalStatus` (same `updated_by` trail as a manual change). 200: `{matched, updated, unknown, missing, unknown_names[≤50], missing_keys[≤50]}`. **409** when the Business Account id / token is not set, or Meta refused them (detail names `meta_{code}`); **503** when Meta is unreachable. Nothing changes on an error. |

Meta status → ours (`WhatsAppApprovalStatus`, which gains `rejected`, `paused`, `disabled`): `APPROVED` → approved; `PENDING`, `IN_APPEAL` → submitted; `REJECTED` → rejected; `PAUSED` → paused; `DISABLED`, `PENDING_DELETION`, `DELETED`, `ARCHIVED`, `LIMIT_EXCEEDED` → disabled. Only `approved` is sendable.

### Messenger HUMAN_AGENT switch

`inbox.messenger.human_agent` (boolean, plain `settings` row) falling back to `config('inbox.messenger.human_agent')` (env `MESSENGER_HUMAN_AGENT`, default false), read with `InboxChannelSettings::enabled()`. Edited as `messenger_human_agent` on `PUT /settings/inbox-channels`; the Messenger card explains Meta may require app review before the tag works. See *Reply state* above for what it changes.

### Verified vs assumed (Meta docs read 2026-11-16)

Verified:
* Template send shape (`type: template`, `template.name`, `language.code`, body `components[].parameters[]` `{type: text, text}`) — `developers.facebook.com/documentation/business-messaging/whatsapp/templates/overview`; response `messages[0].id`, accepted ≠ delivered — `…/whatsapp/messages/send-messages`.
* `GET /{waba-id}/message_templates`: System User token with `whatsapp_business_management`; response objects carry `name`, `language`, `status`, `category`, `id`; status enum APPROVED, IN_APPEAL, PENDING, REJECTED, PENDING_DELETION, DELETED, DISABLED, PAUSED, LIMIT_EXCEEDED, ARCHIVED; errors 190, 100, 200, 80008 — `docs/graph-api/reference/whats-app-business-account/message_templates/`.
* Phone number fields `display_phone_number`, `verified_name`, `quality_rating` (GREEN/YELLOW/RED/NA) — `…/whatsapp/reference/whatsapp-business-phone-number/whatsapp-business-account-phone-number-api`, `docs/whatsapp/business-management-api/manage-phone-numbers`.
* Page `name` field — `docs/graph-api/reference/page/`.
* Graph error shape `{error: {message, type, code, error_subcode, error_user_title, error_user_msg, fbtrace_id}}`; 190 = token expired/invalid — `docs/graph-api/guides/error-handling/`.
* `statuses[]` items: `id`, `status` (sent/delivered/read/failed, `played` for voice), `timestamp` (string seconds), `recipient_id`, `errors[]{code, title, message}` on failure — `…/whatsapp/webhooks/reference/messages/status`.
* **Bengali's template language code is `bn`** (and `bn_IN`); **`bn_BD` is not in Meta's supported-languages list** — `docs/whatsapp/business-management-api/message-templates/supported-languages`.

Assumed / not confirmed:
* **Order notifications and inbox templates send Bangla as `bn`** (owner decision 2026-11-16, was `bn_BD`). Template sync still accepts a Meta template approved as `bn_BD`. Submit the Bangla templates to Meta as **Bengali (`bn`)**.
* `fields` and `limit` query parameters on `message_templates`, and `paging.next` (the reference shows only `paging.cursors`) — standard Graph behaviour, not stated on the reference page.
* LIMIT_EXCEEDED's meaning (treated as not sendable).
* The phone-number read with a System User token in a Bearer header; the Page read with a Page token in a Bearer header (Meta's examples put `access_token` in the query).
* Meta does not charge a failed template (cost stays as recorded at acceptance; pricing not re-read).
* Whether HUMAN_AGENT needs app review — the message-tags page did not load as text; copy says it *may*.



## Inbox › Channels: Continue with Facebook, Disconnect, Reconnect needed (2026-11-20)

Owner request: connect channels the way SSFlows does (Figma section `623:50926`, `docs/_design/06-admin-feature-checklist.md`). Two admin screens, both `settings.manage_integrations`, reached from the Integrations submenu (moved from the Inbox submenu 2026-11-28; the old URLs redirect, query string kept): **Facebook Page** (`/admin/integrations/messenger`) and **WhatsApp** (`/admin/integrations/whatsapp`). The keys form on Integrations › Messaging stays as the manual path ("Enter keys manually", "Keys & webhook"). Still **UNVERIFIED**: no Meta app, Page or number exists; everything below is tested against hand-authored Graph answers (`tests/Feature/InboxSettings/InboxChannelFacebookLoginTest.php`).

**Why WhatsApp is a guided paste-the-keys setup and not SSFlows' one-click button (owner decision 2026-11-20).** Meta's Embedded Signup — and onboarding a number that stays on the WhatsApp Business app ("coexistence", `featureType: whatsapp_business_app_onboarding`) — require being a Solution Partner or Tech Provider (`developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/implementation`: "You must already be a Solution Partner or Tech Provider"). A shop on its own number is a *direct developer* and uses a system-user token, which needs no App Review (`…/whatsapp/solution-providers/app-review`). Bangladesh is not named in any coexistence country list read. The WhatsApp screen therefore walks the owner through four steps (app secret → phone number id + WABA id → permanent token → webhook URL and a verify token made in the browser), saving each step with `PUT /settings/inbox-channels`; the last step runs Test connection and switches replies to `cloud` only when Meta answers.

### Continue with Facebook (Messenger)

Meta's manual login flow on Toy Mart's **own** Meta app, so the admin loads no Facebook script and its CSP is unchanged. `App\Domain\Inbox\Services\MetaPageLogin`.

| Method & path | Permission | Notes |
|---|---|---|
| `POST /settings/inbox-channels/messenger/login` | settings.manage_integrations | No body; `throttle:10,1`. **409** until the App ID (`inbox.messenger.app_id`, env `META_APP_ID`) and the Messenger app secret are set. Returns `{url, redirect_uri}`: `https://www.facebook.com/{v}/dialog/oauth?client_id&redirect_uri&state&response_type=code` plus `config_id` (Facebook Login for Business configuration, `inbox.messenger.login_config_id`, env `META_LOGIN_CONFIG_ID`) or, without one, `scope=pages_show_list,pages_manage_metadata,pages_messaging`. `state` is 48 random characters cached for 10 minutes against the staff member. `redirect_uri` = `{APP_URL}/admin/integrations/messenger` — add it to the app's *Valid OAuth Redirect URIs*. |
| `POST /settings/inbox-channels/messenger/login/finish` | settings.manage_integrations | `{code, state}` from the redirect. The state must be this staff member's, unused and < 10 min old (else **409**; one try uses it up). `GET /{v}/oauth/access_token?client_id&redirect_uri&client_secret&code`, then `grant_type=fb_exchange_token` (long-lived user token), then `GET /{v}/me/accounts?fields=id,name,access_token,tasks`. Returns `{session, pages: [{id, name, can_message}]}` — **no token**; the Page tokens sit encrypted in the cache for 10 minutes under `session`. `can_message` = the person has the MESSAGING or MANAGE task on the Page. **409** with Meta's own message when it refuses the code; **503** when it cannot be reached. |
| `POST /settings/inbox-channels/messenger/connect` | settings.manage_integrations | `{session, page_id}`. `POST /{v}/{page-id}/subscribed_apps` with the Page token and `subscribed_fields=messages,messaging_postbacks,message_deliveries,message_reads` **before** the transaction (rule 4); then saves `page_id`, `page_access_token` (encrypted), `page_name`, `connected_at`, `connected_by`, sets the driver to `messenger` and drops the old `last_check`. One `credential_changed` row, scope `inbox_messenger`, `via: facebook_login`, token as `"changed"`. **409**: session expired / someone else's / used, Page not shared, `can_message` false, or Meta did not confirm the subscription — nothing saved. Returns the settings shape. |
| `POST /settings/inbox-channels/{channel}/disconnect` | settings.manage_integrations | No body. Messenger: `DELETE /{v}/{page-id}/subscribed_apps` with the app token (`{app-id}|{app-secret}`), best effort, then removes the saved Page, token, name, connection stamp and last check. WhatsApp: removes the saved phone number id, WABA id, access token and last check — **never** deregisters the number (Meta allows 10 per 72 h, and it cannot be done on a number shared with the Business app). Both set the driver to `log` and keep the app secret, verify token and App ID. Values from env stay. Audited (`via: disconnect`, `unsubscribed: true|false|null`). Conversations stay. |

`GET /settings/inbox-channels` gains per channel: `connection {state: connected|reconnect|not_connected, reason, since}`, `activity {open, waiting, unread, oldest_waiting_at, last_inbound_at, last_inbound_from, last_event_at}` (`App\Domain\Inbox\Queries\InboxChannelActivity`, from the inbox tables), and for Messenger `page_name`, `connected_at`, `connected_by`, `app_id(+_source)`, `login_config_id(+_source)`, `login_ready`, `oauth_redirect_uri`. `PUT` accepts `messenger_app_id` and `messenger_login_config_id` (digits; absent = keep, blank = remove).

**Reconnect needed** (`connection.state = reconnect`): the last Test connection failed with `meta_190` (token invalid/expired) or `not_subscribed`, or a reply failed with `provider_error:190` after the last good test / connection. Test connection on Messenger now also reads `GET /{page-id}/subscribed_apps` when the App ID is known: our app missing → `not_subscribed` (Meta unsubscribes a Page after an hour of failed webhook deliveries — `docs/messenger-platform/webhooks`).

**Verified (Meta docs read 2026-11-20):** Standard Access works without App Review for people with a role on the app (`docs/graph-api/overview/access-levels`); `config_id` replaces `scope` in Facebook Login for Business, and the app must be a Business-type app (`docs/facebook-login/facebook-login-for-business`); long-lived user token ≈ 60 days, Page tokens from it have no expiry date (`docs/facebook-login/guides/access-tokens/get-long-lived`); `POST/DELETE /{page-id}/subscribed_apps` and its permissions (`docs/graph-api/reference/page/subscribed_apps`); error 190 and its subcodes (`docs/graph-api/guides/error-handling`); Graph v27.0.

**Not confirmed:** (1) whether messages from customers who have **no role** on the app reach the webhook before App Review — the Messenger overview says no review is needed for your own Page, the webhooks page says Advanced Access (App Review) is needed for non-role users; settle it with a test message from such an account (the Facebook screen says so) and plan for App Review + Business Verification as the fallback. (2) Whether the app must be published (Live) for its own Page. (3) That the manual dialog honours `config_id` exactly as the JS SDK does — `scope` is the fallback when no configuration id is saved. (4) The Meta menu names in the WhatsApp guide are from the docs, not a live dashboard.
