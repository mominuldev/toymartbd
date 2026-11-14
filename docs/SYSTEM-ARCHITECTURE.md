# Toy Mart BD — System Architecture

**Status:** Draft for review · **Date:** 2026-11-07 · **Owner:** Technical Lead

---

## 1. Architectural Goals

| Goal | Why it matters for this business | How the architecture serves it |
|---|---|---|
| Organic search wins | Every verified competitor runs WooCommerce/Shopify with weak technical SEO. Search is the cheapest durable channel versus paid Facebook traffic. | Server-rendered storefront, full schema.org coverage, slug-history 301s, ISR-cached product pages |
| Mobile-first speed | Majority of BD traffic is mid-range Android on 4G | CDN-cached HTML, AVIF/WebP pipeline, <100KB critical JS budget |
| Operational truth | The owner's real pain is not the shop front — it is knowing actual profit after courier fees, returns and COD losses | Append-only stock + financial ledgers, order-level P&L, COD receivables tracked separately from revenue |
| Courier independence | Pathao/Steadfast terms and APIs change; RedX/Paperfly may be added | Courier Gateway abstraction — one interface, per-provider drivers, zero changes to order code to add a provider |
| Small-team maintainability | One or two developers will own this | All business logic in Laravel services; clients are view layers only; no logic duplicated across apps |

### Non-goals for MVP
Multi-vendor marketplace, multi-warehouse, multi-currency, native mobile apps, AI recommendations, loyalty points. The schema leaves room for each (see `DATABASE-DESIGN.md`) but none ship in MVP.

---

## 2. System Topology

```
                         ┌──────────────────────────────┐
                         │   Customers (mobile 4G)      │
                         └───────────────┬──────────────┘
                                         │ HTTPS
                         ┌───────────────▼──────────────┐
                         │  STOREFRONT                  │
                         │  Next.js 16 (App Router)     │
                         │  SSR + ISR, cache tags       │
                         │  Server Components only for  │
                         │  data; no API keys in browser│
                         └───────────────┬──────────────┘
                                         │ server-to-server REST (private)
                                         │
┌─────────────────┐      ┌───────────────▼──────────────┐      ┌──────────────────┐
│  ADMIN SPA      │      │  LARAVEL 13 APPLICATION      │      │  Meilisearch     │
│  React 19 +Vite │─────▶│  ┌────────────────────────┐  │─────▶│  product index   │
│  TypeScript     │ REST │  │ HTTP layer (API v1)    │  │      │  Bangla+typo     │
│  Sanctum cookie │      │  │  Controllers, FormReq, │  │      └──────────────────┘
└─────────────────┘      │  │  Resources, Policies   │  │
                         │  ├────────────────────────┤  │      ┌──────────────────┐
┌─────────────────┐      │  │ Domain services        │  │─────▶│  MySQL 8.4       │
│  Courier APIs   │◀────▶│  │  Catalog, Cart,        │  │      │  InnoDB, utf8mb4 │
│  Pathao,        │      │  │  Checkout, Inventory,  │  │      └──────────────────┘
│  Steadfast      │      │  │  Fulfilment, Risk,     │  │
└─────────────────┘      │  │  Finance, Courier GW   │  │      ┌──────────────────┐
                         │  ├────────────────────────┤  │─────▶│  Redis 7         │
┌─────────────────┐      │  │ Eloquent models        │  │      │  cache, queue,   │
│  Payment GW     │◀────▶│  └────────────────────────┘  │      │  session, locks  │
│  (SSLCommerz)   │      └───────────────┬──────────────┘      └──────────────────┘
└─────────────────┘                      │
                                         │ dispatch
┌─────────────────┐      ┌───────────────▼──────────────┐
│  SMS / WhatsApp │◀─────│  QUEUE WORKERS (Horizon)     │
│  Meta CAPI/GA4  │◀─────│  courier, notify, index,     │
└─────────────────┘      │  finance, tracking, default  │
                         └──────────────────────────────┘
```

---

## 3. Decision: Storefront Rendering Strategy

This is the highest-leverage and least reversible decision in the project, so it is argued in full.

### Options evaluated

| Criterion (weight) | A. Laravel Blade + Livewire | B. React SPA + SSR (custom) | C. Next.js consuming Laravel API |
|---|---|---|---|
| SEO / crawlability (25%) | Excellent — HTML by default | Good but fragile to configure | Excellent — plus ISR means crawlers always hit cached HTML |
| Core Web Vitals (20%) | Good TTFB from one region; LCP limited by PHP render per request | Good | Best — static HTML from CDN edge, 0ms origin on cache hit |
| Interactivity (filters, cart, variant switch) (15%) | Livewire round-trips add latency on 4G | Excellent | Excellent — client components, no round trip |
| Build + maintain cost (20%) | Lowest — one app, one deploy | Highest | Medium — two deploys, one shared contract |
| Hosting cost (10%) | Lowest — single VPS | Medium | Low–medium — Vercel Hobby/Pro + VPS |
| Future reuse of API (10%) | API exists but unexercised by the storefront, so it rots | Exercised | Exercised daily — the API is proven by real traffic |

### Recommendation: Option C — Next.js 16 App Router storefront on Vercel, consuming the Laravel REST API

**Reasoning.** The business thesis is to beat slow WooCommerce competitors on search and speed. With ISR plus cache tags, a product page is static HTML served from a CDN edge — no PHP execution, no database query, no origin hop on a cache hit. Neither Option A nor any competitor's stack can match that, and Core Web Vitals is the one ranking factor we fully control. Equally important: because the storefront is the API's largest consumer, the API is continuously proven by production traffic rather than existing only for the admin panel.

**Honest cost of this choice.** Three deployable units instead of one. Two languages. Cart, session and auth must be designed deliberately rather than inherited from Laravel's session. A developer must be comfortable in both PHP and TypeScript. This is a real tax and it is the main schedule risk in the roadmap.

**How the cost is contained.**
1. **Laravel owns 100% of business logic.** The storefront never computes a price, a discount, a delivery charge or a stock decision. It renders what the API returns. Any logic in the storefront is a bug.
2. **One generated contract.** Laravel publishes an OpenAPI 3.1 document; `openapi-typescript` generates `packages/api-types`. A breaking API change fails the storefront's typecheck in CI.
3. **Server-only API access.** The storefront calls the API from Server Components and Route Handlers using a server-side token. No browser ever holds an API credential, and no CORS configuration is needed for catalog traffic.
4. **Server-driven cart.** The cart lives in MySQL, keyed by an opaque `cart_token` in an httpOnly cookie. Totals, stock checks and delivery charges are always the API's answer. No cart logic is duplicated client-side.

**Plan B and its trigger.** If, at the end of Phase 3, the team is one PHP developer with no TypeScript confidence, switch the storefront to Laravel + Blade + Livewire 3 and keep everything else identical. The API, admin SPA, database and all domain services are unchanged — only the view layer differs. Deciding this at the end of Phase 3 costs roughly two weeks; deciding it in Phase 7 costs six. **This trigger must be evaluated explicitly at the Phase 3 gate.**

### Caching and revalidation model
- Product, category, CMS and blog pages: `revalidate` by tag, not by time.
- Laravel fires `POST {STOREFRONT_URL}/api/revalidate` with a shared secret from a queued listener on `ProductSaved`, `PriceChanged`, `StockBecameZero`, `CmsPublished`.
- Tag scheme: `product:{id}`, `category:{id}`, `home`, `cms:{slug}`, `blog:{slug}`.
- Cart, checkout, account and order-tracking pages: fully dynamic, `no-store`, never cached.
- Price and stock are the only fields allowed to be re-fetched client-side on a cached page, via a small uncached endpoint. This keeps HTML cacheable without ever showing a stale price at checkout.

---

## 4. Laravel Application Structure

Modular-monolith by domain. Not a package-per-module setup — that overhead is not justified at this size — but strict directory and dependency boundaries that a package split could later follow.

```
app/
├── Domain/
│   ├── Catalog/          Product, Variant, Category, Brand, Attribute
│   │   ├── Models/  Services/  Actions/  Events/  DTOs/
│   ├── Inventory/        StockLevel, StockMovement, Purchase, Supplier
│   ├── Cart/             Cart, CartItem, pricing pipeline
│   ├── Ordering/         Order, OrderItem, state machine, order events
│   ├── Payment/          Gateway contract, drivers, webhooks, refunds
│   ├── Fulfilment/       Shipment, CourierGateway contract, drivers
│   ├── Risk/             RiskProfile, scoring rules, courier lookups
│   ├── Finance/          Ledger, expenses, P&L projections, COD settlement
│   ├── Customer/         Customer, addresses, segments
│   ├── Marketing/        Coupons, promotions, campaigns, abandoned cart
│   ├── Content/          CMS blocks, pages, blog, media
│   ├── Seo/              SeoMeta, slug history, redirects, sitemap, schema
│   └── Tracking/         Server-side events, GA4 MP, Meta CAPI, dedup
├── Http/
│   ├── Controllers/Api/V1/{Admin,Storefront,Webhook}/
│   ├── Requests/         FormRequest per endpoint — the only validation site
│   ├── Resources/        API shape; never leaks an Eloquent model directly
│   └── Middleware/
├── Support/              Money, PhoneNumber, Slug, IdempotencyKey
└── Providers/
```

### Dependency rules (enforced in CI by `deptrac`)
- `Http` may depend on `Domain`. `Domain` must never depend on `Http`.
- A domain may depend on `Catalog`, `Customer` and `Support` freely.
- Cross-domain reads go through the owning domain's service, never another domain's model.
- Cross-domain writes go through **events only**. Example: `Fulfilment` never writes stock; it emits `ShipmentDispatched`, and `Inventory` listens.
- `Finance` is read-mostly: it listens to events and writes its own ledger. Nothing writes to `Finance` directly.

### Layer responsibilities
- **Controller** — authorise, validate via FormRequest, call one action/service, return a Resource. No business logic, no queries.
- **Action** — a single use case with a transaction boundary (`PlaceOrder`, `BookShipment`, `ReceivePurchase`, `RefundOrder`). This is where `DB::transaction` lives.
- **Service** — reusable domain logic with no transaction of its own, so it composes inside an action.
- **Repository** — introduced only for `Risk` and `Finance`, where reporting queries are complex and need to be swappable/testable. Everywhere else Eloquent scopes are sufficient; adding repositories uniformly would be ceremony without benefit.

---

## 5. API Design

- Base paths: `/api/v1/storefront/*`, `/api/v1/admin/*`, `/api/v1/webhooks/*`.
- Versioning in the URL. `v1` is frozen on first production deploy; additive changes only. Breaking changes go to `v2` with both served during migration.
- Contract: OpenAPI 3.1, generated from code — routes, FormRequests, Resources and their PHPDoc — by `dedoc/scramble`, so there is no second hand-written copy to drift. Exported to `docs/api/openapi.json` with `composer openapi:export` (a test fails CI when it is stale) and served at `/api/docs` (open locally; elsewhere needs `settings.view`). `packages/api-types` is generated from that file (`npm run api-types:generate`), and CI fails if it is stale.
- Standard envelope: `{ data, meta, links }` for collections; bare resource object for singles.
- Errors: RFC 9457 Problem Details — `{ type, title, status, detail, errors[] }`. Every error carries a stable machine-readable `type` so clients branch on it rather than on message text.
- Filtering/sorting/includes via `spatie/laravel-query-builder` with an explicit allowlist per endpoint. An unlisted filter is a 400, never a silent pass-through — this prevents a filter becoming an unindexed table scan.
- Pagination: cursor pagination for admin tables and product listings (stable under writes); offset pagination only where a page number must be user-visible.

### Authentication
| Client | Mechanism | Rationale |
|---|---|---|
| Admin SPA | Sanctum **cookie** session, admin served from `admin.toymartbd.com` with `SESSION_DOMAIN=.toymartbd.com` | No token in JS, so XSS cannot exfiltrate a long-lived credential. CSRF token required on writes. |
| Storefront server (Next.js) | Static server token in `Authorization: Bearer`, abilities limited to public catalog reads | Never reaches the browser |
| Customer account | Sanctum personal access token, issued by the API, stored by a Next.js Route Handler in an httpOnly+Secure+SameSite=Lax cookie | Token never readable by JS; storefront proxies account calls server-side |
| Couriers / gateways | Unauthenticated routes with signature verification + IP allowlist where published | See §8 |

### Rate limiting
`login` 5/min per IP+phone · `otp` 3/10min per phone (+ `otp-ip` 30/hour per shopper IP) · storefront reads 120/min per IP (the Next server's own cached reads: 2,400/min) · `checkout` 10/min per cart · admin 300/min per user · webhooks exempt from throttling but idempotency-guarded.

**Shopper IP behind the Next.js server.** Every storefront call reaches Laravel from the Next server, so its uncached calls (`noStore` / `send` in `lib/api.ts`) forward the shopper's IP as `X-Forwarded-For` — taken from `X-Real-IP`, else the rightmost `X-Forwarded-For` entry its own reverse proxy appended. Laravel honours that header only when the TCP peer is listed in `TRUSTED_PROXIES` (`config/trustedproxy.php`: comma-separated IPs/CIDRs, **default none, wildcards refused**), and takes only the client IP and scheme from it, never the host. Production must list the Next server (and any load balancer) there, or every per-IP limit is keyed on one address. Cached (ISR) reads never forward it, so they share one cache entry. Those server-originated reads (trusted proxy, no `X-Forwarded-For`) get their own whole-shop budget (`STOREFRONT_SERVER_PER_MINUTE`, 2,400/min) rather than one shopper's 120/min: they are every cache miss on the site, and a crawler walking listing URLs or a revalidation burst would otherwise 429 them and the storefront would serve 500s (Phase 9 SEO audit).

### Idempotency
`POST /checkout`, `POST /shipments`, and all payment captures require an `Idempotency-Key` header. Keys are stored with the response hash for 24h in Redis; a replay returns the original response. This is what prevents a double-tap on a flaky 4G connection from creating two orders and double-decrementing stock.

---

## 6. Admin SPA

React 19 · TypeScript strict · Vite · Tailwind v4 · React Router v7 (data mode) · TanStack Query v5 (server state) · TanStack Table v8 (data grids) · React Hook Form + Zod (forms) · Recharts (charts) · Zustand only for genuine cross-page client state (sidebar, theme, active-shift context).

**State rule.** Server data lives in TanStack Query and nowhere else. Zustand holds no server data. The most common failure mode in admin dashboards is a second copy of server state in a global store that silently goes stale; this rule forbids it.

**Shared validation.** Zod schemas for admin forms are generated from the OpenAPI document, so client validation cannot drift from Laravel's FormRequest rules. Laravel remains the authority — client validation is purely a latency optimisation.

**Structure.** Feature-first: `src/features/orders/{api,components,hooks,routes,schemas}`. Shared primitives in `src/components/ui`. No feature imports from another feature's internals; cross-feature sharing is promoted to `src/shared`.

**Performance.** Route-level code splitting; the orders table and courier booking screen must stay usable on a 1366×768 laptop over a slow connection, because that is what the operations staff will actually use.

---

## 7. Background Processing

Queues on Redis, supervised by Horizon. Separate queues so a slow courier API never blocks order confirmation.

| Queue | Workers | Work | Retry policy |
|---|---|---|---|
| `critical` | 3 | Order confirmation side-effects, payment webhook processing, stock reservation release | 3 tries, 10s backoff, alert on failure |
| `courier` | 2 | Shipment booking, bulk booking, status polling, courier risk lookups | 5 tries, exponential 30s→8m, circuit breaker per provider |
| `notify` | 2 | SMS, WhatsApp, email, push | 3 tries, then dead-letter with admin visibility |
| `tracking` | 2 | GA4 Measurement Protocol, Meta CAPI | 3 tries; dropped events logged, never retried past 1h (stale conversions are worse than missing ones) |
| `index` | 1 | Meilisearch sync | 3 tries |
| `reports` | 1 | P&L rollups, exports, scheduled reports | 2 tries |

**Scheduled tasks.** Courier status reconciliation (every 15 min, for providers whose webhooks are unreliable) · abandoned-cart detection (hourly) · low-stock digest (daily 09:00 Asia/Dhaka) · daily financial rollup (01:00) · sitemap regeneration (03:00) · stale cart cleanup + reservation release (every 10 min) · database backup (02:00).

**Circuit breaker.** Each external provider is wrapped in a breaker: 5 consecutive failures opens it for 5 minutes, during which jobs are re-queued with backoff and the admin sees a provider-down banner. Without this, a Pathao outage floods the queue and starves everything else.

---

## 8. External Integration Architecture

### Courier Gateway
```php
interface CourierGateway {
    public function quote(ShipmentQuote $q): Money;
    public function book(Shipment $s): BookingResult;      // → consignment id, tracking code
    public function bookBulk(Collection $shipments): BulkBookingResult;
    public function status(Shipment $s): ShipmentStatus;
    public function cancel(Shipment $s): bool;
    public function balance(): Money;
    public function normaliseWebhook(Request $r): ?CourierEvent;
    public function resolveLocation(Address $a): CourierLocation;
}
```
Drivers: `PathaoGateway`, `SteadfastGateway`, plus `NullGateway` for local development and tests. Resolved from a `courier_providers` table so a provider can be enabled, disabled or re-credentialled from the admin UI without a deploy.

Each provider maps its own vocabulary into one internal `ShipmentStatus` enum (`pending, booked, picked_up, in_transit, out_for_delivery, delivered, partially_delivered, hold, cancelled, returning, returned, lost`). Raw provider payloads are stored verbatim in `courier_tracking_events.payload` so a mapping bug can be replayed and corrected without data loss. **`partially_delivered` is first-class**, not an afterthought: both providers support partial delivery, and AppByte's lack of that state is a verified gap worth not repeating — a partly-delivered multi-item parcel must settle partial COD and restock the remainder.

#### Verified provider asymmetries that the abstraction must absorb

Established in `docs/_research/agent3-appbyte-and-couriers.md`. These are the reasons the interface looks the way it does, and each one would otherwise leak into order code.

| Concern | Pathao | Steadfast | Consequence for the design |
|---|---|---|---|
| Base URL | sandbox `courier-api-sandbox.pathao.com`, prod `api-hermes.pathao.com`, path prefix `/aladdin/api/v1` | `https://portal.packzy.com/api/v1` — the README's `portal.steadfast.com.bd` has no DNS | Base URL is per-provider config, never a constant |
| Sandbox | Yes | **None exists.** The "sandbox.api.steadfast.io" host that appears in third-party docs is fabricated | `NullGateway` plus recorded fixtures are the only safe way to test Steadfast. Live verification needs a real merchant account and a disposable low-value parcel |
| Auth | OAuth-ish `POST /issue-token`, ~5-day token, needs refresh. **Auth failure returns HTTP 400, not 401** | Static `Api-Key` / `Secret-Key` headers | Token lifecycle belongs in the driver. Never branch on 401 to detect an expired credential |
| Address model | Structured city → zone → area lookups, numeric ids | **No zone model at all — address is free text** | `courier_locations` mapping applies to **Pathao only**. Steadfast bookings pass the address through. Building one shared location requirement would wrongly block Steadfast |
| Price quote | `POST /merchant/price-plan` | **No price-calculation endpoint** | Quoting must fall back to the stored rate card in `courier_providers` when the provider offers no quote API |
| Bulk booking | `POST /orders/bulk` returns **202 with no consignment ids** | `POST /create_order/bulk-order`, **max 500**, body is `{"data": "<json-encoded array>"}` — a JSON *string*, not an array | `bookBulk` cannot promise ids. It returns an accepted-batch handle; ids arrive by webhook or reconciliation poll. The order UI must show "submitted", not "booked", until confirmed |
| Idempotency | Provider-side unknown | `invoice` must be unique | Use our `shipments.number` as the provider idempotency key on both |
| Tracking code | — | Variable-length hex, **8–15 chars** | Never fix the column width or validate a length |
| Webhooks | `X-PATHAO-Signature` is a **plain shared secret, not an HMAC**, and the response must echo a specific `X-Pathao-Merchant-Webhook-Integration-Secret` header within 10s. Events are dotted-lowercase (`order.delivered`, `order.partial-delivery`, `order.paid`) | `Authorization: Bearer`; payload uses `notification_type` with the value in `status` | Signature verification is per-driver. Because a plain shared secret is weak, Pathao webhooks are treated as a **trigger to re-query**, never as authoritative |
| COD settlement | — | `GET /payments` and `/payments/{id}` give true settlement-to-consignment matching | Steadfast reconciliation can be automated; Pathao's starts as statement import. `courier_settlements` is designed for both |
| Status suffix | — | The real value is `_approval_pending`, not `_approval` | Status mapping is driven by fixtures captured from live traffic, not from prose docs |
| **Weight step** | +per kg above 2 kg | **Step at 1 kg, not 2 kg** | Two separate rate-card shapes in `courier_providers`. Copying Pathao's rule onto Steadfast mis-quotes every boxed toy in the 1–3 kg band — which is most of them |
| COD fee basis | % of **product price**; its own page contradicts itself (FAQ 0.5% Dhaka, tables 1%) — resolve at runtime from `price-plan`'s `cod_percentage` | 1% of (**collected − delivery charge**) — a cheaper base | COD fee is a per-provider formula, not a shared percentage |
| Return charge | Published | **Its own Terms and Business pages contradict each other** ("does not charge for return parcels" vs "return charges apply separately") | Stored as config and **must be confirmed in writing with the provider.** At 15–30% RTO this single line can outweigh the forward tariff |

#### Development without merchant accounts — the sandbox reality

The owner confirmed on 2026-11-08 that **no Pathao or Steadfast merchant account exists yet**, and asked to develop against sandboxes. Only one of the two can honour that.

| | Pathao | Steadfast |
|---|---|---|
| Sandbox exists | **Yes** — `courier-api-sandbox.pathao.com`, prefix `/aladdin/api/v1` | **No. None exists.** The `sandbox.api.steadfast.io` host that appears in third-party documentation is fabricated and does not resolve |
| Credentials without a merchant account | Sandbox credentials are referenced in Pathao's own published plugin source. **Confirm on Phase 6 day one**; if they are not usable, a merchant-portal registration is required | **Impossible.** The static `Api-Key` / `Secret-Key` pair is issued only on merchant-account creation. There is no public or test credential of any kind |

**Consequence for the plan.** Both drivers are still written now, against the same interface, because the interface is what protects the order code. But they reach different levels of confidence before launch:

- **`PathaoGateway`** — developed and verified against the real sandbox. Response fixtures captured from live sandbox traffic.
- **`SteadfastGateway`** — developed against **hand-authored fixtures** derived from Steadfast's own published Postman collection and Laravel package. It is marked `verified: false` in `courier_providers` and **cannot be enabled in production until a two-day live-verification task runs against a real account.** The admin shows an explicit "unverified provider" banner so nobody books a real parcel through untested code.
- **`NullGateway`** becomes load-bearing rather than a convenience: it is how the whole fulfilment flow — booking, status transitions, stock consumption, financial entries — is exercised end to end in CI without touching either provider.

**This is why the gateway abstraction earns its cost.** Launching Pathao-only and enabling Steadfast later is a `courier_providers` row update and a credential entry. No order, stock or finance code changes.

#### ⚠️ What the missing Steadfast account costs the risk module

Steadfast's `GET /fraud_check/{phone}` is the **only officially published courier-history endpoint in the Bangladesh market**, and it requires a merchant account. Without one:

- The risk score at launch is computed **entirely from our own order history**. For a repeat customer that is actually the better evidence. For a genuinely first-time caller it means **no external signal at all** — exactly the case the feature exists to catch.
- Compensating control, in MVP: any **first order from an unknown phone above a configurable value threshold** is routed to the verification queue for a confirmation call, regardless of score. A phone call is cheap; a round-trip courier charge on a fake order is not.
- `courier_fraud_lookups` and its UI panel are still built, and sit empty with an explicit "no courier data — provider not connected" state rather than a misleading zero.

**Recommendation to the owner:** open both merchant accounts before launch rather than after. They are ordinarily free, Steadfast's is the precondition for the single highest-leverage integration available in this market, and no amount of code substitutes for it.

**Default provider once both are live: Steadfast.** It is cheaper outside Dhaka (৳155 vs ৳170 for 2 kg), it has the only openly accessible API documentation, it is the only provider with an official fraud-check endpoint, and its `/payments` endpoints make COD settlement reconciliation automatable. Pathao is the secondary provider — slightly cheaper inside Dhaka city (৳70 vs ৳75) and useful as a fallback when Steadfast's balance or coverage fails. **Until a Steadfast account exists, Pathao is the default by necessity rather than by merit.** Both are configured from day one; the default is a setting, not a code path.

Both providers' official documentation is login-gated or JavaScript-only. The facts above come from Pathao's own published plugin source, Steadfast's own Postman collection and Laravel package, and live endpoint probes — **no authenticated round-trip was made, so response bodies are documented rather than observed.** The first task of Phase 6 is therefore to capture real responses from both providers with live credentials and commit them as test fixtures. Until that happens, every driver detail is provisional.

#### Order-risk data: policy decision

The BD market's "fake order check" products, AppByte included, do not disclose their data source. The verified category mechanism is that vendors **store the merchant's courier-portal email and password** and call undocumented internal endpoints (`merchant.pathao.com/api/v1/user/success`, `steadfast.com.bd/user/frauds/check/{phone}`, RedX and Carrybee equivalents).

**Toy Mart BD will not do this.** Storing a merchant's portal password to scrape an unsupported endpoint is a credential-handling liability, breaks without warning, and is plainly outside the providers' terms. Instead:

1. **Steadfast's official fraud endpoint** — `GET /fraud_check/{phone}`, published in its own Postman collection, returning `total_parcels` / `total_delivered` / `total_cancelled` / `total_fraud_reports`. This is supported, documented, and uses our normal API credentials. A newer `/fraud_check/score/{phone}` exists but currently returns `score` and `level` as `null` with `scoring_disabled: true`, so **we must not depend on its score** — we consume the ratios and compute our own.
2. **Our own order history**, which for a repeat customer is better evidence than any third party's.
3. If the owner later wants broader courier history, the correct route is to ask each provider for API access, not to scrape with their password. This is recorded as a deliberate trade-off: we accept slightly thinner first-order signal in exchange for not holding credentials we have no right to.

One further distinction. AppByte states that an order is automatically held above a 70% risk score. **Auto-*hold* for human verification is sound and we do the same. Auto-*cancel* is not, and we never will** — a false positive on a real parent is a lost customer and a bad review, while a held order costs one phone call.

### Payment Gateway
Same shape: `PaymentGateway` with `initiate`, `verify`, `refund`, `verifyWebhook`. All five BD gateways evaluated share one flow — server-side session → hosted redirect → server-side re-validation — so the abstraction costs almost nothing and makes the launch choice a configuration decision. COD is implemented as a first-class driver so it flows through identical status transitions rather than being a special case scattered through the order code.

**Launch driver: SSLCommerz** (reasoning in `PRODUCT-REQUIREMENTS.md` §5). Its `verify_sign` algorithm is **not in the HTML documentation** and was recovered from SSLCommerz's own PHP library: take the fields named in `verify_key`, add `store_passwd => md5(password)`, `ksort`, join as `k=v` with `&`, `md5`, and compare. Signature verification is step one; the authoritative amount and status still come from the Order Validation API.

### Webhook security — non-negotiable rules
1. Verify signature or hash **before** any parsing or database write. An unverified payload is logged and dropped with 401.
2. Re-verify the authoritative amount and status by calling the provider's query API. Never trust the webhook body as the source of truth for money.
3. Idempotency on the provider's event id, stored in `webhook_receipts`; duplicates return 200 without re-processing.
4. Respond 200 fast; do all work in a queued job.
5. Store every raw inbound payload for 90 days for dispute resolution.
6. IP allowlist where the provider publishes ranges, as defence in depth only — never as the primary control.

### Server-side tracking
Browser fires GA4 and Meta Pixel with a generated `event_id`; the server fires the same event with the **same** `event_id` to GA4 Measurement Protocol and Meta Conversions API. Meta deduplicates on `event_id` + `event_name`. Server-side events are the reliable channel (ad-blockers and iOS suppress ~20–40% of browser events); the browser event supplies the `fbp`/`fbc` cookies that improve match quality. Consent state is stored per visitor and checked before dispatch. Every event is written to `tracking_events` with its dispatch result so the admin has a diagnostics screen instead of guessing why conversions are under-reported.

---

## 9. Data Layer

- MySQL 8.4, InnoDB, `utf8mb4_0900_ai_ci` — required for Bangla text and emoji in product names and customer notes.
- Money: `DECIMAL(14,2)` with a `Money` cast object. Never float. All arithmetic in PHP via the cast or in SQL via DECIMAL, so aggregation stays exact.
- **Timestamps stored UTC: `APP_TIMEZONE=UTC`.** Display and report day-boundaries use a separate `APP_DISPLAY_TIMEZONE=Asia/Dhaka`, read through the `DhakaDate` helper. Setting `app.timezone` itself to `Asia/Dhaka` would make Eloquent *write* Dhaka-local datetimes, which defeats UTC storage — the two settings are not interchangeable. **All "daily" reports must bucket by Dhaka date, not UTC date**; an order placed at 23:30 UTC belongs to the next Dhaka day. This is a classic source of wrong daily revenue figures.
- Soft deletes only on `products`, `categories`, `customers`, `cms_*`. Orders, stock movements, financial transactions and tracking events are **never** soft-deleted; they are append-only or status-changed.
- Indexing and transaction boundaries: see `DATABASE-DESIGN.md` §6 and §7.
- Full-text search delegated to Meilisearch, not MySQL FULLTEXT: Bangla tokenisation, typo tolerance, Banglish/transliteration synonyms and faceted filtering all matter, and competitors' search is uniformly poor — this is a cheap, visible win.

---

## 10. Security Posture

| Risk | Control |
|---|---|
| Mass assignment | `Model::preventSilentlyDiscardingAttributes()` and explicit `$fillable`; writes only through FormRequest-validated DTOs |
| Broken authorisation | Policy on every model; `Gate::before` for super-admin only; `authorizeResource` in controllers; a feature test per role asserts 403 on every admin endpoint |
| Credential exposure | Gateway keys in `encrypted` cast columns, decrypted only at call time; `APP_KEY` from the host secret store, never in the repo; secrets redacted in logs and in the activity log |
| File upload abuse | Validate real MIME by content not extension; re-encode all images through Intervention (strips EXIF and any embedded payload); store outside webroot on S3-compatible storage; serve via CDN. Built in P4-CAT-07: `docs/api/media.md` |
| SQL injection | Eloquent/query builder only; `DB::raw` requires bindings and a code-review tag |
| XSS | React escapes by default, `dangerouslySetInnerHTML` banned by lint rule except for CMS HTML which is sanitised server-side with an allowlist before storage |
| CSRF | Sanctum cookie + `X-XSRF-TOKEN` on all admin writes |
| Enumeration / scraping | Rate limits per §5; order tracking requires order number **plus** last 4 digits of phone, never order number alone |
| Insider risk | `spatie/laravel-activitylog` on every model write with actor, IP and before/after diff; price, cost and refund changes additionally recorded in an immutable audit table |
| PII exposure | Customer phone and address visible only to roles with `customers.view_pii`; staff list views show masked phones (`017****5678`) |
| Fraudulent refunds | Refunds require a second approver above a configurable amount; every refund writes a ledger entry and an audit row |

Dependency and static analysis in CI: `composer audit`, `npm audit`, Larastan level 6+, Pint, ESLint, TypeScript strict, `deptrac` boundary check.

---

## 11. Observability

- **Errors:** Sentry on all three apps, releases tagged to git SHA, source maps uploaded.
- **Logs:** JSON to stdout, shipped to the host's log store. Correlation: a `X-Request-Id` generated at the edge, propagated to the API, attached to every log line and every queued job, and returned in responses. A support question about one order can then be traced across storefront, API and worker.
- **Uptime:** external checks on storefront home, `/api/v1/health`, and a synthetic add-to-cart every 15 min.
- **Business alerts** (these catch the failures that error monitoring misses): zero orders in 3 daytime hours · courier booking failure rate >10% in 15 min · payment webhook failures >3 in 10 min · queue depth >500 · failed jobs >20/h · Meilisearch index lag >5 min · courier account balance below threshold.
- **Health endpoint** reports database, Redis, queue depth, Meilisearch, and last successful courier sync, so a load balancer and a human get the same truth.

---

## 12. Environments & Deployment

| Environment | Purpose | Data |
|---|---|---|
| Local | Development — Laravel Sail or Herd; Meilisearch and Redis in Docker; `NullGateway` couriers; gateway sandboxes | Seeded, synthetic |
| Staging | QA, courier/payment sandbox integration, SEO validation, UAT | Anonymised production clone |
| Production | Live | Live |

**Deployment.** GitHub Actions. On PR: Pint, Larastan, PHPUnit/Pest, ESLint, tsc, Vitest, Playwright smoke, `deptrac`. On merge to `main`: build and deploy API + admin to the VPS via a zero-downtime release-directory swap (`php artisan down` only for destructive migrations), run migrations, restart Horizon gracefully; storefront deploys to Vercel with preview URLs per PR.

**Migration safety.** Expand-and-contract for every schema change: add nullable column → backfill in a job → start writing both → switch reads → drop old column in a later release. No `ALTER TABLE` that locks `orders` or `order_items` during business hours.

**Backups.** Automated nightly `mysqldump` plus binlog retention for point-in-time recovery; media to versioned object storage; backups encrypted and stored off the application host. **A restore is rehearsed on staging monthly** — an untested backup is not a backup. Target RPO 1 hour, RTO 4 hours.

---

## 13. Testing Strategy

| Layer | Tool | Coverage target | Must-cover cases |
|---|---|---|---|
| Domain unit | Pest | High on pricing, risk scoring, P&L, stock math | Discount stacking order; delivery-charge rules; risk score boundaries; profit with partial refund + courier return |
| Feature/API | Pest + database transactions | Every endpoint, every role | Guest checkout; stock oversell under concurrency; idempotent double-submit; 403 matrix per role |
| Integration | Pest with recorded HTTP fixtures | Each courier and payment driver | Booking success/failure; webhook signature valid/invalid/replayed; provider timeout; malformed payload |
| Admin unit | Vitest | Pricing/calculation components, form schemas | — |
| E2E | Playwright | Golden paths only | Browse → variant → cart → guest COD checkout → order confirmation; admin confirm → book courier → mark delivered → P&L reflects it |
| SEO regression | Playwright + schema validator | All page templates | Title/meta present; JSON-LD validates; canonical correct; sitemap reachable; no `noindex` on indexable pages |
| Performance | Lighthouse CI in PR | Budget gate | LCP < 2.5s and CLS < 0.1 on mobile throttling for home, category and product templates; build fails on regression |

Two correctness invariants are asserted by dedicated tests on every build, because they are the two ways this system could silently corrupt the business's records:
1. For every variant, `sum(stock_movements.quantity) == stock_levels.on_hand`.
2. For every order, `order_financials.net_profit` equals the sum of its ledger entries.

---

## 14. Risk Register (technical)

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| T1 | Three-app complexity overruns a small team's capacity | High | High | Phase-3 gate decision on Plan B (§3); generated types; Laravel holds all logic |
| T2 | Courier API undocumented changes or outages | High | Medium | Gateway abstraction, circuit breaker, raw payload retention, 15-min reconciliation poll, manual-entry fallback for consignment IDs |
| T3 | Stock oversell under concurrent checkout | Medium | High | `SELECT … FOR UPDATE` on stock rows ordered by variant id inside the order transaction; reservation model; nightly ledger-vs-level reconciliation test |
| T4 | Wrong profit figures erode trust in the whole system | Medium | High | Cost price snapshotted on the order item; append-only ledger; COD receivable separated from revenue; invariant test #2 |
| T5 | Payment webhook spoofing | Low | Critical | Verify-then-query-then-write; idempotency on provider event id; amount re-verification |
| T6 | ISR serves a stale price at checkout | Medium | High | Price and stock re-fetched uncached on the product page and re-validated server-side at checkout; a price mismatch blocks the order with an explicit message |
| T7 | Meilisearch index drifts from catalog | Medium | Low | Queued sync on save, nightly full reindex, index-lag alert |
| T8 | Bangla text corruption | Low | Medium | `utf8mb4_0900_ai_ci` enforced in a migration test; Bangla fixtures in the seed data and in E2E |
| T9 | Courier zone ids diverge from the address dataset | High | Medium | Dedicated `courier_locations` mapping table with an admin screen for unmapped areas; booking blocked with a clear message rather than guessing a zone |
| T10 | Single VPS is a single point of failure | Medium | High | Documented rebuild runbook, infrastructure as code, monthly restore rehearsal; move to managed database when order volume justifies it |

---

## 15. Open Questions for the Business Owner

1. Expected order volume at launch and at 12 months — this decides whether a single VPS is adequate.
2. Does the business hold its own stock with purchase invoices from suppliers, or dropship? The inventory and purchase modules assume owned stock.
3. Who will operate the admin daily, and how many staff need separate logins and restricted roles?
4. Is there an existing product catalog, customer list or order history to migrate, and in what format?
5. Existing trade licence, TIN/BIN and bank account — these gate payment-gateway onboarding and determine whether COD-only launch is forced.
6. Is Bangla UI required at launch or acceptable in Phase 2? This materially affects design and content effort.
7. Current courier accounts — are Pathao and Steadfast merchant accounts with API credentials already held?
