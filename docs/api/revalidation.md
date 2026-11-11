# Storefront cache revalidation contract

Laravel tells the Next.js storefront which cached pages are stale after a write. The storefront
renders catalog pages with ISR and cache tags; this call drops exactly the affected tags.

## Request (Laravel → Next.js)

```
POST {STOREFRONT_URL}/api/revalidate
Content-Type: application/json
X-Revalidate-Timestamp: 1762871234            (unix seconds, set by the sender)
X-Revalidate-Signature: sha256=<hex>          (HMAC-SHA256 over "<timestamp>.<raw body>")

{"tags": ["brands", "brand:lego"]}
```

- The secret is `STOREFRONT_REVALIDATE_SECRET`, identical on both sides. It never reaches a browser.
- The signature covers the timestamp and the raw body bytes exactly as sent.

## Receiver rules (Next.js route handler)

1. Read the raw body **before** parsing. Recompute the HMAC and compare in constant time.
   A missing or invalid signature is a **401** — nothing is parsed or revalidated.
2. Reject a timestamp more than **300 seconds** from now (either direction) with **401** (replay guard).
3. Body must be `{"tags": string[]}`, 1–50 tags, each matching `^[a-z0-9:_-]{1,100}$`. Otherwise **400**.
4. Call `revalidateTag(tag)` for each tag, then answer **200** `{"revalidated": [...tags]}`.

## Sender rules (Laravel)

- Sent from a **queued job after the database commit** (CLAUDE.md rule 4), never inside a transaction.
- Retries with backoff on network errors and 5xx. A 4xx is a bug and is not retried.
- If `STOREFRONT_URL` or the secret is empty (local without the storefront), the job logs at debug level and does nothing.

## Tag vocabulary

| Tag | Meaning |
|---|---|
| `brands` | any brand list |
| `brand:{slug}` | one brand page |

New tags are added here first, then on both sides.
