# Abandoned-cart recovery (P7)

One SMS reminder per abandoned cart, only with consent, linking back to the cart. Never shown on the
checkout UI (rule 25b spirit: checkout completes one task).

## Detection — `toymart:carts:recover` (every 10 minutes)

`Notification\Actions\DetectAbandonedCarts`. A cart is abandoned when it is open (not converted, merged
or expired), has items, has a **phone** (`carts.phone`, left through `PUT /cart/contact`) **or a customer**,
and neither it nor its contact changed for `cart_recovery.after_minutes` (default 60) — but within
`max_age_hours` (48). Nothing runs inside the Dhaka quiet hours (21:00–09:00). Config:
`config/cart_recovery.php` (all values are PLACEHOLDERS for the owner).

Each abandoned cart gets exactly one `cart_recoveries` row (UNIQUE `cart_id` — at most one message per
cart, ever, however often detection runs): `messaged` (an SMS log row is queued and
`SendCartRecoveryMessage` dispatched after commit) or `skipped` with a reason:

| reason | when |
|---|---|
| `no_phone` | neither the cart nor its customer has a valid phone |
| `no_consent` | a guest did not tick the reminder box and the customer never opted in to marketing SMS |
| `opted_out` | the customer explicitly turned SMS marketing off — **wins over a tick on the cart** |
| `cooldown` | this phone was reminded within `phone_cooldown_hours` (72) |
| `already_ordered` | the phone placed an order after the cart last changed |
| `below_minimum` | goods value below `min_cart_value` |
| `template_inactive` | `cart.recovery` SMS template switched off |
| `converted` | the cart became an order (or consent was withdrawn) before the job sent |

Consent: a cart reminder is **marketing** (NotificationCategory, off by default). Allowed only with an
explicit opt-in — the guest's `marketing_consent` on this cart or the customer's SMS-marketing
preference — and never against an explicit opt-out. Both are re-checked by the job just before sending.

## The message

Template `cart.recovery` (SMS only, EN + BN, measured into segment counts on sync; migration
`2026_11_28_500500`). Variables: `name`, `item_count`, `restore_url`, `store_url`. The log row
(`notifiable_type = cart_recovery`) records provider, segments, encoding and **cost**; recipient masked
and hashed; the payload holds no phone, address or token (rule 20).

**Link:** `{storefront}/cart/restore/{token}` (`/bn/…` for Bangla). The 24-character token is minted by
the job, stored only as a SHA-256 hash on the recovery row, valid `restore_ttl_days` (7). It is not the
cart token and carries no PII.

## Storefront API

| Method | Path | Body | Notes |
|---|---|---|---|
| PUT | `/storefront/cart/contact` | `{phone, marketing_consent, locale?}` + `X-Cart-Token` | 204. `marketing_consent: false` withdraws |
| POST | `/storefront/cart/restore` | `{token}` | the priced cart incl. `token` (the Next server sets its cookie); unknown/expired link or an ordered cart → 404 |

**Not wired yet (owner decision):** no storefront screen collects the phone + consent before checkout —
this must not be the checkout page. Until it exists only signed-in customers who opted in to SMS marketing
can be reminded.

## Recovered

`OrderPlaced` → `MarkCartRecovered`: a **messaged** cart that becomes an order records
`recovered_order_id`. The admin report (`GET /admin/cart-recoveries/summary?from&to`, Dhaka dates) counts
abandoned, messaged, skipped by reason, sent, SMS cost, links opened, recovered, and **revenue recovered
from delivered (or completed) orders only** (rule 10). `GET /admin/cart-recoveries` lists them
(`filter[status]=messaged|skipped|recovered`), phones masked. Both need marketing.view.
