# Toy Mart BD — Product Requirements

**Status:** Draft for owner approval · **Date:** 2026-11-07
**Evidence base:** `docs/_research/` — nine competitor sites audited, AppByte reverse-benchmarked from its own bundle, both courier APIs reconstructed from primary sources, BD payment and operational research with cited sources. Where something could not be verified, it is marked as such and not quietly assumed.

---

## 1. Product Vision

> Toy Mart BD is the Bangladesh toy store that helps a parent decide **what is right for their child**, delivers on exactly what it promised, and gives its owner the truth about what the business actually earned.

The competitor audit found a market where the shopping experience and the back office are both weak, so the plan attacks both. Two propositions, one platform:

**For parents.** Shop by what a child needs — age in months, developmental skill, occasion — not by scrolling an undifferentiated grid. See the real delivery cost before the cart. Know whether it is genuinely in stock. Read reviews that exist. Get a tracking link that works.

**For the owner.** One click books the courier. Repeat fake-order callers are flagged before a parcel is paid for twice. Stock reflects reality. And the profit figure accounts for courier charges, COD fees, returns, discounts and refunds — not price minus cost.

### Why this is winnable
The verified competitor set is weaker than its size suggests. Of the toy stores audited: none offers a brand or gender filter, none runs a flash sale, none has meaningful review volume, none has a working blog, none lists a functioning digital payment method, and only one names its courier. One competitor is COD-only yet **collects no phone number at all**. Another leaves its delivery charge at zero. A third states its free-delivery threshold three different ways on the same site. These are not hard problems — they are unattended ones.

### What we are not
Not a marketplace. Not a price leader — Daraz will always undercut us. Not a general baby store. We compete on confidence: the right toy, honestly described, actually in stock, delivered when promised.

---

## 2. Users

| User | Context | What they need |
|---|---|---|
| **Parent buyer** (primary) | Mid-range Android, 4G, evening, one-handed, comparing against Facebook pages and Daraz in other tabs. Often burned by a Facebook seller before. | Age-appropriate suggestions, honest stock, delivery cost up front, COD, a reason to trust an unfamiliar site |
| **Gift buyer** | Relative or friend; knows the child's age, not their interests; deadline-driven | Gift finder, price bands, occasion merchandising, gift wrap and message, fast delivery |
| **Educational buyer** | Parent or teacher seeking developmental outcomes | Skill and milestone taxonomy, specifications, safety and standards information |
| **School / institution** | Bulk purchase, needs paperwork | Wholesale pricing, quotation, proper invoice (P2) |
| **Owner** | Runs the business; non-technical; needs truth more than dashboards | Real P&L, stock reality, courier control, fake-order protection, homepage edits without a developer |
| **Order operator** | Confirms orders, calls customers, books couriers all day | Fast keyboard-driven order board, phone lookup with history, bulk booking |
| **Inventory staff** | Receives stock, counts, adjusts | Purchase receipt, barcode entry, adjustment with reason |
| **Accountant** | Monthly close | P&L, expenses, COD receivables, supplier payables, exports |
| **Content editor** | Banners, blog, pages | CMS that does not need a developer |

---

## 3. Functional Requirements

Priority: **M** = MVP · **P2** = after launch. Each requirement notes the evidence behind it where the audit informed it.

### 3.1 Catalog & discovery

| ID | Requirement | Pri | Why |
|---|---|---|---|
| C-01 | Simple and variable products; variants carry SKU, barcode, cost, price, stock, weight | M | Two of three audited toy stores show no stock state on variable products |
| C-02 | Categories to 3 levels, nested-set, products in multiple categories with one canonical parent | M | Competitor depth ranges 1–3 levels; one has a single flat level |
| C-03 | Brands with pages and filtering | M | **No audited competitor offers a brand filter** |
| C-04 | **Age stored in months**, with filter bands and a PDP age badge | M | Only one competitor offers an age filter; months matter in the 0–36 range where parents shop most precisely |
| C-05 | Developmental-skill / learning-goal taxonomy, filterable | M | The two strongest competitors prove demand (10 therapy goals; 16 skills); neither makes it fully filterable, combinable and URL-addressable |
| C-06 | Gender filter | M | **No audited competitor has one** |
| C-07 | Price filter and bands | M | One competitor has none at all |
| C-08 | Filters combinable, URL-addressable, back-button safe, with result counts | M | Shareable filtered URLs are both a UX and an SEO asset no competitor holds |
| C-09 | Sorting: relevance, newest, price, best-selling, rating | M | Parity |
| C-10 | Search with autocomplete, typo tolerance, and **Banglish/Bangla/English equivalence** | M | Competitor search is uniformly weak; one has no autocomplete at all |
| C-11 | Zero-results page with corrections, nearest category, best-sellers | M | Parity gap |
| C-12 | Gallery with zoom, multiple images, video slot | M | Parity |
| C-13 | Variant selector; unavailable combinations disabled **with the reason**, never hidden | M | Hiding them is the common failure |
| C-14 | Explicit stock state per variant: in stock / low stock / out of stock | M | One competitor shows none |
| C-15 | Reviews with ratings, moderated, `is_verified_purchase` proven from the order item | M | **No competitor has meaningful review volume; none emits `aggregateRating`** — so none earns stars in search results |
| C-16 | Related products, frequently-bought-together, recently viewed | M | Partial parity |
| C-17 | Safety and standards information: materials, choking-hazard warning, certification | M | **No competitor does this systematically** — and it is the single strongest trust signal available in a category where quality is unverifiable |
| C-18 | Bundles and play-progression kits | P2 | The most imitable commercial idea found in the audit |
| C-19 | Gift finder by age, price band and occasion | P2 | **No competitor offers any gift mechanic** in a category driven by birthdays and Eid |
| C-20 | Wholesale pricing and quotations | P2 | Institutional buyers |

### 3.1a Upsell, cross-sell and recommendations

Four mechanics that admins routinely conflate, kept deliberately distinct because they belong in different places and behave differently:

| | What it is | Direction | Where it belongs |
|---|---|---|---|
| **Upsell** | A better, larger or premium **alternative** to the item being viewed | *Replaces* the item | Product page only |
| **Cross-sell** | A **complement** that goes with the item | *Adds to* the item | Product page, cart, order confirmation |
| **Frequently bought together** | Computed from actual co-purchase history, not curated | Adds | Product page |
| **Bundle** | A fixed set sold at one combined price | Replaces the separate items | Product page, campaign pages |

| ID | Requirement | Pri | Why |
|---|---|---|---|
| X-01 | Curated **upsell** relations per product, max 2 shown, displayed as "Upgrade to" with the price difference stated, not hidden | M | **Verified: no audited competitor has any upsell mechanic.** Stating the delta rather than just a higher price is what stops it reading as a bait |
| X-02 | Curated **cross-sell** relations per product, max 4, displayed as "Goes well with" | M | Only EduToyZone has anything comparable (its combo offer) |
| X-03 | **Frequently bought together** on the product page, computed from real co-purchase, with add-all-to-cart and the combined total | M | Daraz has this; no niche competitor does |
| X-04 | **Cart cross-sell rail only — never upsell in the cart** | M | A buyer in the cart has already decided. An upsell there asks them to reconsider a decision, which costs more orders than it earns |
| X-05 | **Nothing on checkout. No upsell, no cross-sell, no "customers also bought".** | M | Checkout exists to complete one task. Every competitor failure mode in the audit was friction at this exact step; we will not add our own |
| X-06 | Order-confirmation cross-sell | M | The one post-decision moment where a suggestion costs nothing — the order is already placed |
| X-07 | **Age-progression upsell**: when a child outgrows a toy, suggest the next developmental stage in the same category or skill | M | **The single most valuable recommendation available in this category, and no competitor has it.** Age is already stored in months and skill taxonomy already exists, so the data is there. It is also the strongest repeat-purchase lever the business has |
| X-08 | **Accessory and consumable cross-sell**: batteries, storage, refill packs, expansion sets for track and block systems | M | Genuine value, not padding — a parent who gets an RC car home without batteries blames the shop |
| X-09 | Admin relation management on the product form: type, position, and a short **reason note** so a future staff member knows why the pair exists | M | Undocumented curation rots within months |
| X-10 | **Guardrails enforced server-side**, not left to the admin's care: never suggest an out-of-stock product · never suggest the product itself · an "upgrade" must actually cost more · respect the per-placement cap · never suggest an archived or unpublished product | M | A recommendation block showing sold-out items is worse than no block |
| X-11 | **Attribution**: units and revenue attributed per placement, and the source recorded on the order line | M | Without it the owner cannot tell whether any of this earns money. It feeds the P&L module, so upsell revenue appears as a real figure rather than a belief |
| X-12 | Auto-linking **rules** so 500 products need not be hand-paired — e.g. "every product in Art & Craft cross-sells refill packs", "age band 12–24m upsells 24–36m in the same skill" | P2 | MVP is curated; rules are the scale answer once the catalogue is large enough to need them |
| X-13 | Per-pair conversion reporting, so a weak pairing can be retired | P2 | Needs traffic before it says anything |
| X-14 | Post-delivery replenishment and progression messaging over WhatsApp | P2 | Depends on WhatsApp templates (M-06) and delivery data |
| X-15 | Recently viewed and personalised recommendations | P2 | Needs behavioural data worth using |

**One deliberate restraint.** Recommendations are capped and placed, never sprayed. A product page carries at most 2 upsells, 4 cross-sells and one frequently-bought-together block; the cart carries one rail; checkout carries none. The audit found competitors whose homepage was an 80-item alphabetical dump — breadth without judgement is what makes a store feel cheap, and the same applies to recommendations.

### 3.2 Cart, checkout, orders

| ID | Requirement | Pri | Why |
|---|---|---|---|
| O-01 | Server-side cart, httpOnly token, guest cart merges on login | M | Totals must never be client-computed |
| O-02 | **Guest checkout is the default**; registration offered after purchase | M | Forcing an account is the largest avoidable drop-off |
| O-03 | **Phone required and validated** against live operator prefixes; email optional | M | One audited COD-only competitor collects **no phone number** — an unworkable position |
| O-04 | Single-page checkout, three accordion sections | M | The best competitor does single-page; the worst does three steps |
| O-05 | Division → district → upazila → area cascading selects, searchable, last address remembered | M | Dhaka-subcity granularity is what the strongest competitor gets right |
| O-06 | Free-text address line plus a **landmark** field | M | Directly reduces failed deliveries; how BD addresses actually work |
| O-07 | **Delivery charge shown on the product page**, before the cart, from the same code path as checkout | M | One competitor computes no delivery charge at all; another hides it until checkout |
| O-08 | Zone-based delivery pricing: inside Dhaka / Dhaka suburb / outside Dhaka, plus weight surcharge, admin-editable | M | Flat national rates lose money outside Dhaka |
| O-09 | Free-delivery threshold, **stated consistently everywhere** | M | One competitor contradicts itself three ways |
| O-10 | COD first-class and pre-selected | M | COD dominates; all three audited toy stores are COD-only in practice |
| O-11 | One online gateway (SSLCommerz — see §5) | M | Aggregator covers cards + all major wallets in one integration |
| O-12 | Coupon field, collapsed behind "Have a coupon?" | M | An open empty coupon field sends buyers away to hunt |
| O-13 | Idempotent order placement; a double-tap creates one order | M | Flaky 4G makes this a real failure, not a theoretical one |
| O-14 | Stock **reserved** at placement, consumed at dispatch, released on cancel | M | Decrementing at placement makes available stock permanently wrong in a high-cancellation market |
| O-15 | Order confirmation page, SMS and email | M | Parity |
| O-16 | **Order tracking by order number + phone digits** | M | Only one competitor has working tracking; none exposes shipment-level status |
| O-17 | Order history and detail in the account | M | Parity |
| O-18 | Manual / phone order creation with `source` recorded | M | A large share of BD orders arrive by phone and Messenger |
| O-19 | Order edit after placement, with stock and total recalculation and full audit | M | BD customers phone in changes constantly; no competitor-grade system handles this cleanly |
| O-20 | Bulk order processing: confirm, print, mark packed, book courier — reporting per-order results | M | AppByte parity |
| O-21 | Cancellation, return, refund with approval threshold and restocking | M | AppByte parity |
| O-22 | **Partial delivery** handled: partial COD settled, remainder restocked | M | Both couriers support it; **AppByte has no such state** — a verified gap where money goes missing |
| O-23 | Gift wrap and gift message | P2 | Unclaimed in this market |

### 3.3 Inventory & purchasing

| ID | Requirement | Pri | Why |
|---|---|---|---|
| I-01 | Real-time stock per variant; `available = on_hand − reserved` | M | Foundation |
| I-02 | **Append-only stock movement ledger** with every movement typed and referenced | M | Makes "why is my stock wrong?" answerable |
| I-03 | Suppliers with contact, terms and balance | M | **AppByte has no supplier module at all** — a verified gap |
| I-04 | Purchase orders with partial receipt | M | Same gap |
| I-05 | **Landed cost**: inbound freight and other costs apportioned by line value | M | Ignoring inbound freight overstates margin 5–15% on imported toys |
| I-06 | Weighted-average costing maintained on receipt | M | Simple, stable, adequate for this catalog |
| I-07 | Stock adjustment with reason and approval | M | Parity |
| I-08 | Damaged and lost stock tracked and written off to the ledger | M | Otherwise losses are invisible |
| I-09 | Low-stock and out-of-stock alerts with reorder points and a daily digest | M | Parity |
| I-10 | Inventory valuation report | M | Parity |
| I-11 | Returned stock updates inventory correctly for customer and courier returns | M | The expensive failure mode |
| I-12 | Product- and category-wise margin | M | Parity |
| I-13 | Barcode entry for receipt and stock-take | M | Speeds real warehouse work |
| I-14 | Multi-warehouse, batch tracking, stock transfer | P2 | Schema-ready; AppByte offers these, we do not need them at launch |

### 3.4 Courier

| ID | Requirement | Pri | Why |
|---|---|---|---|
| F-01 | Pathao and Steadfast behind **one `CourierGateway` interface**; add a provider by writing a driver | M | Terms and APIs change; RedX and Carrybee are plausible additions |
| F-02 | One-click booking from the order; consignment id and tracking code stored | M | Core AppByte parity |
| F-03 | Bulk booking — **shown as "submitted" until confirmed**, because neither provider returns ids on bulk | M | Verified: Pathao returns 202 with no ids; Steadfast takes up to 500 with a JSON-string body |
| F-04 | Charge preview before booking, from the provider's quote where available and the stored rate card where not | M | Verified: Steadfast has no price-calculation endpoint |
| F-05 | `courier_locations` mapping for providers that need it; unmapped area **blocks the booking with a clear message, never a guessed zone** | M | Verified: Pathao uses its own city/zone/area ids; Steadfast has no zone model at all |
| F-06 | Status sync by webhook **plus** a 15-minute reconciliation poll; all providers normalised to one status enum | M | Pathao's webhook signature is a plain shared secret, not an HMAC — treat it as a trigger to re-query |
| F-07 | Customer-facing tracking timeline | M | No competitor has this |
| F-08 | Failed-booking retry queue; circuit breaker per provider | M | A provider outage must not silently strand orders |
| F-09 | Label and sticker printing | M | AppByte parity |
| F-10 | Return shipment handling with return charge recorded | M | The costliest failure mode must be visible |
| F-11 | Courier performance report: delivery rate, return rate, transit time, cost per provider | P2 | Informs which provider to default to |
| F-12 | **COD settlement reconciliation** matching remittances to consignments, flagging variances | M | **AppByte has no settlement matching.** Steadfast's `/payments` endpoints make this automatable; it is usually the largest receivable on the books |

### 3.5 Order risk

| ID | Requirement | Pri | Why |
|---|---|---|---|
| R-01 | Risk profile keyed on **normalised phone**, so a repeat caller is recognised as a new guest | M | Phone is the real identity in BD |
| R-02 | Counters: orders, delivered, cancelled, returned, failed delivery, refused | M | Parity |
| R-03 | Weighted score 0–100 with a **visible breakdown** of which signals contributed | M | Staff decide whether to call; an opaque number does not help them |
| R-04 | Verified courier data stored and displayed **separately and timestamped**, never blended into our score | M | Mixing our heuristic with the courier's facts misleads the person making the call |
| R-05 | Courier history from **officially published APIs only** — currently Steadfast `GET /fraud_check/{phone}` | M | The market standard is to store the merchant's courier-portal password and scrape undocumented endpoints. We will not. See §6 |
| R-06 | High-risk orders auto-**held** for verification; verification queue; never auto-cancelled | M | A false positive loses a real parent and earns a bad review; a held order costs one phone call |
| R-07 | Blacklist and whitelist with reason, actor and timestamp | M | Parity |
| R-08 | Phone-lookup screen usable before confirming any order | M | The operator's most-used screen |
| R-09 | Configurable thresholds and weights | P2 | Tune once real data exists |

### 3.6 Finance

| ID | Requirement | Pri | Why |
|---|---|---|---|
| N-01 | **Cost price snapshotted on the order item**, written once, never recalculated | M | Without it, changing a cost rewrites last quarter's profit |
| N-02 | **Append-only financial ledger**, with revenue recognised on delivery and cash on remittance | M | The core correctness requirement of the whole module |
| N-03 | **Revenue, cash collected, receivables, expenses and profit kept separate** | M | Conflating them is how a BD COD business misreads itself |
| N-04 | Expenses with categories, attachments and approval | M | Parity |
| N-05 | Net profit accounting for courier charge, COD fee, return charge, gateway fee, packaging, discounts, refunds, write-offs | M | Explicit brief requirement; price-minus-cost is not profit |
| N-06 | P&L daily, weekly, monthly, yearly — **bucketed by Dhaka date** | M | UTC bucketing silently misreports daily revenue |
| N-07 | Profitability by product, category and order | M | Parity |
| N-08 | Courier-wise expense breakdown | M | Parity |
| N-09 | COD receivables outstanding, by provider and age | M | Usually the largest asset; untracked by competitors |
| N-10 | Supplier payables and payments | M | Parity |
| N-11 | Projected profit from in-transit parcels, clearly labelled a projection | P2 | Useful AppByte idea; cheap once the ledger exists |
| N-12 | Marketing cost allocated across delivered orders, shown separately as an estimate | P2 | Honest about what is measured vs. apportioned |
| N-13 | Invoices: customer and supplier, PDF, bulk print, sequential numbering, company details snapshotted | M | Reprinting an old invoice must show the details current then |
| N-14 | VAT/Mushak-compliant invoice format | P2 | **AppByte does not do this.** Requires accountant confirmation first — see §7 |

### 3.7 Customers, marketing, content, SEO

| ID | Requirement | Pri |
|---|---|---|
| U-01 | Customer records keyed on phone; **guest orders auto-attach**, so lifetime value works without registration | M |
| U-02 | Multiple addresses; order history; notes; lifetime value, order count, AOV | M |
| U-03 | Phone + OTP login, rate limited | M |
| U-04 | Children's profiles (opt-in, consented) driving age-appropriate recommendations and birthday reminders | P2 |
| U-05 | Segmentation and SMS/WhatsApp campaigns | P2 |
| M-01 | Coupons: fixed, percentage, free shipping, with limits keyed on phone, scope and exclusions | M |
| M-02 | Promotions engine with **priority and stacking rules**, resolved breakdown written to the order | M |
| M-03 | Flash sales with countdown | M — **no competitor runs one; one has the capability and disabled it** |
| M-04 | Abandoned-cart detection, with recovery measurable via `recovered_order_id` | M |
| M-05 | Buy-X-get-Y, bundles, tiered discounts | P2 |
| M-06 | Transactional SMS, email and WhatsApp with per-message cost logged | M |
| T-01 | CMS: typed homepage blocks, ordered, schedulable, with live preview | M |
| T-02 | Static pages, menus, banners, media library | M |
| T-03 | Blog with categories, tags, authors | M — **every competitor's blog is empty or absent; all parenting and gift-guide search intent is uncontested** |
| T-04 | Testimonials, landing-page builder | P2 |
| S-01 | Per-entity SEO: title, description, canonical, robots, OG, Twitter, with SERP preview | M |
| S-02 | Structured data: Product + Offer + **AggregateRating**, BreadcrumbList, Organization, Article, FAQPage, WebSite+SearchAction | M — **no competitor emits AggregateRating, so none earns review stars** |
| S-03 | SEO-friendly slugs with **automatic 301s from slug history** | M — preserves ranking through renames; permanently invisible advantage |
| S-04 | Redirect manager, XML sitemap, robots.txt editor, pagination SEO, indexing controls | M |
| S-05 | Image alt text in both languages, admin-editable | M |
| S-06 | Search Console and GA4 integration | M |
| S-07 | **Core Web Vitals as CI budgets**, not aspirations | M — every WooCommerce competitor is slow; this is the one ranking factor fully under our control |
| K-01 | Server-side tracking: GA4 Measurement Protocol + Meta Conversions API, deduplicated with browser events by shared `event_id` | M — one audited competitor runs **zero analytics**; another has a pixel but no GA4 |
| K-02 | Consent state checked before dispatch | M |
| K-03 | Event log and a tracking diagnostics screen | M — so under-reported conversions are diagnosable, not guessed at |
| K-04 | Configurable IDs and credentials in secure admin settings | M |

### 3.8 Platform

| ID | Requirement | Pri |
|---|---|---|
| P-01 | Roles and granular permissions; 7 launch roles | M |
| P-02 | Activity log on every model write, with actor, IP and before/after diff | M |
| P-03 | Immutable audit trail for price, cost, refund, role and credential changes | M |
| P-04 | Store, courier, payment, notification and tracking settings in the admin | M |
| P-05 | Bangla and English **content fields** throughout (schema level) | M |
| P-06 | **Full Bangla UI at launch**, English and Bangla both complete | **M** |
| P-06a | Locale-prefixed routing: English at the root, Bangla at `/bn/`, with `hreflang` pairs and `x-default` | M |
| P-06b | Language toggle in the header, persisted per visitor; Bangla **suggested** on a first visit from Bangladesh, never force-redirected | M |
| P-06c | Per-locale SEO: separate title, description, OG tags and sitemap entries for each locale | M |
| P-06d | Bangla notification templates for SMS, email and WhatsApp | M |
| P-06e | Bangla admin UI | P2 — staff work the admin daily and learn it once; storefront customers do not |
| P-07 | BDT, Asia/Dhaka, BD phone validation, BD address hierarchy | M |
| P-08 | Admin dark mode | M |
| P-09 | Storefront dark mode | P2 |

---

## 4. Non-functional Requirements

| Area | Requirement |
|---|---|
| Performance | LCP ≤ 2.5s and CLS ≤ 0.05 on throttled mobile for home, category and PDP; checkout LCP ≤ 2.0s; JS ≤ 140KB per route. CI-gated. |
| Scale | 500 orders/day and 10,000 SKUs without architectural change; 10× launch concurrency verified by load test |
| Availability | 99.5% target; RPO 1 hour; RTO 4 hours; **restore rehearsed monthly** |
| Security | OWASP Top 10; webhook signature verification before any write; encrypted credentials; PII access gated by role; masked phones in list views |
| Accessibility | WCAG 2.2 AA, axe-gated in CI, verified in both languages |
| Observability | Error tracking, structured logs with request-id correlation, uptime checks, and **business alerts** (zero orders in 3 daytime hours, courier failure rate, queue depth, courier balance low) |
| Maintainability | All business logic in Laravel; clients are view layers; domain boundaries enforced by `deptrac` |
| Correctness | Two invariants asserted on every build: stock movements sum to stock levels; order financials equal the ledger |

---

## 5. Bangladesh Decisions

| Decision | Choice | Basis |
|---|---|---|
| **Online payment gateway** | **SSLCommerz** | The only one of five with complete public primary-source docs, a vendor-maintained Laravel library, a documented signature **plus** a server-to-server validation API **plus** published source IPs, a self-serve sandbox, and published pricing (2.5%, 3.5% AMEX). One integration covers cards and all major wallets. |
| ⚠️ Gateway cost caveat | **BDT 25,500 non-refundable setup fee** (observed 2026-11-07) | Material for a startup. **Get written quotes from SSLCommerz and aamarPay before committing.** aamarPay is the credible cheaper alternative — public sandbox, documented verify call, aggregator coverage — but no official Laravel SDK and no published pricing. The `PaymentGateway` abstraction makes this a configuration choice, so it does not block the build. |
| Direct bKash / Nagad | **Not at launch** | Each gives one wallet for a full integration. Revisit bKash-direct only if bKash becomes a large share of prepaid volume and the saving over 2.5% exceeds build and maintenance cost. Nagad has no public developer documentation at all. |
| COD | **Primary tender, first-class driver** | All three audited toy stores are COD-only in practice |
| Couriers | **Pathao + Steadfast**, abstracted | Both have usable APIs; RedX and Carrybee are the natural third and fourth |
| Courier fraud data | **Officially published APIs only** | See §6 |
| Addresses | Division → district → upazila → area + line + **landmark** | Landmark reduces failed deliveries materially |
| Phone | 11-digit `01XXXXXXXXX` normalised on write, validated against live operator prefixes, `+880`/`880`/`0` all accepted | A COD store that cannot reach the buyer cannot deliver |
| Language | **Full Bangla and English UI at launch.** English at the root, Bangla at `/bn/`, `hreflang` pairs both ways plus `x-default` | Owner decision. No audited competitor has `hreflang` or a real Bangla locale; both marketplaces' Bangla is machine-translated |
| Numerals | **Western digits (0–9) everywhere**, both locales | Prices must never cost the reader a hesitation; mixed scripts break tabular alignment |
| Bangla notification cost | **WhatsApp utility ≈৳0.45 preferred over Bangla SMS** for order updates | Bangla consumes 70 characters per SMS segment versus 160 for Latin, so a Bangla order update is 2–3× the cost of an English one. Bangla UI makes this a live budget line, not a footnote. OTP stays on SMS (§6a) |
| Timezone | Asia/Dhaka for display and **all report bucketing**; UTC in storage | — |
| Search | Meilisearch with Bangla + Banglish synonyms | Competitor search is uniformly poor; cheap, visible win |

---

## 6. Deliberate Trade-offs

Recorded so they are not silently reversed later.

1. **No courier-portal credential scraping.** The BD market standard — AppByte included — is to store the merchant's courier portal email and password and call undocumented internal endpoints to build a fraud history across four couriers. We will not: it is a credential liability, it breaks without warning, and it is outside the providers' terms. We use Steadfast's officially published `fraud_check` endpoint plus our own order history. **Cost: thinner signal on a genuinely first-ever customer.** Accepted. If the owner wants broader coverage, the route is to request API access from each provider.

2. **Three applications instead of one.** A Next.js storefront on a Laravel API buys CDN-served HTML and the best achievable Core Web Vitals, which is the whole organic-search thesis. **Cost: more surface area and two languages.** Mitigated by a generated type contract and by keeping every piece of logic in Laravel. Re-evaluated honestly at Gate 3, where the fallback is a Laravel Blade storefront with everything else unchanged.

3. **Weighted-average costing, not FIFO.** Simpler, stable, adequate for a toy catalog. FIFO is addable later from `purchase_items` without a schema change.

4. **Bangla UI ships at launch** (owner decision, 2026-11-08 — this reverses the earlier recommendation to defer it). The schema already carried `*_bn` fields, so the data side was ready; what this adds is the full interface, routing, SEO and notification surface. **Cost: ≈16 dev-days plus ≈3 designer-days, and a real content obligation on the owner — every product name, description and category label now needs a Bangla version before launch, which is the larger half of the work and is not a development task.** Accepted, and it is defensible: no audited competitor has `hreflang` or a true Bangla locale, and Daraz's Bangla is visibly machine-translated (`Interest` rendered as `সুদ`, financial interest; `ছাগল`, goat, in its navigation). Hand-written Bangla against that is a genuine differentiator rather than a checkbox.

4a. **Numerals stay Western (0–9) in both locales**, including prices, phone numbers, quantities, order numbers and dates. Bengali numerals (০–৯) are used for words-as-numbers only where editorial copy calls for it. Reasoning: Bangladeshi shoppers read prices in Western digits routinely, mixed-script numerals break tabular alignment in carts and admin tables, and a price is the one element where a moment's hesitation costs an order. This is a deliberate choice, not an oversight — if the owner disagrees it is a formatter change, not a redesign.

4b. **Slugs stay ASCII-transliterated in both locales.** One product has one slug, served under both locale prefixes. Bangla-script slugs percent-encode into unreadable strings when pasted into Messenger or WhatsApp, which is how BD shoppers actually share links.

5. **Risk scoring holds, never cancels.** A false positive costs a real customer and a public review; a held order costs one phone call. The asymmetry is not close.

6. **Multi-warehouse and batch tracking deferred** although AppByte offers them. One seeded location row keeps the schema ready.

---

## 6a. Two Findings That Need the Owner's Attention Now

These came out of the Bangladesh market research and are business matters, not technical ones. Neither changes the build plan much; both change the business.

### ⚠️ Toys became subject to mandatory BSTI certification in 2026
R.O. 232/233-Act/2026, gazetted **23 June 2026**, effective **23 August 2026**, covering BDS ISO 8124-2/-3/-6/-12:2025 (flammability, migration of certain elements, chemical and mechanical safety). **This post-dates every compliance guide currently online**, which is why it is easy to miss.

What is unresolved from public sources: whether an importer needs a full CM (Certification Mark) licence or whether consignment-wise port clearance is sufficient. That distinction materially changes import cost and lead time.

**Action: confirm with BSTI and a C&F agent before committing to an import plan.** This is the single highest-impact unknown surfaced by the research, and it sits outside the software project.

**It also creates a product opportunity.** No audited competitor presents safety or certification information systematically. If certification is becoming mandatory anyway, displaying it — per product, as structured data — converts a compliance cost into the strongest available trust signal in a category where a parent cannot otherwise verify quality. The schema already carries `safety_note`; add certification fields when the requirement is confirmed.

### ⚠️ Do not put OTP on WhatsApp
Bangladesh sits on Meta's **authentication-international** pricing tier at roughly **26× the domestic authentication rate**. Meta moved to per-message pricing on 1 July 2025 and resumed charging for service and in-window utility messages on 1 October 2026, and Bangladesh left the "Rest of APAC" band on that date.

**Utility** messages, however, are cheap — about **৳0.45** — which is *less* than a masked SMS and two to three times cheaper than a multi-segment Bangla SMS, because Bangla consumes 70 characters per segment rather than 160. So: **order updates and shipping notifications over WhatsApp, OTP over SMS.** Confirm current rates before launch; Meta's pricing moves.

---

## 6b. Assumptions the Risk and Finance Modules Are Built On

Both of these are **tunable parameters, not constants**, because the research found that the commonly-quoted figures are not supported by primary sources. This matters: hard-coding folklore into a profit model produces confident wrong answers.

| Assumption | Planning value | What the research actually found |
|---|---|---|
| **COD share of orders** | **85–90%** | A verified negative finding: Bangladesh Bank's *Payment Systems Report 2025* was full-text searched and **contains no COD data whatsoever**. The ubiquitous ">90%" figure traces to an undated e-CAB claim about user *preference* that could not be located. The best available citation is a single named industry quote (Financial Express, April 2025); PCMI puts it at 75% by volume. **Treat as an assumption to be replaced by our own data within 60 days of launch.** |
| **Return / RTO rate** | **15–30%; 40%+ for unverified first-time buyers** | **No Bangladesh figure exists from any primary source** — couriers do not publish it. The planning range is inferred from Indian comparables (Qikink: 30.1% COD vs 1.5% prepaid) plus local "75–80% safe success rate" heuristics. **Stored as a configurable parameter feeding the risk thresholds and the P&L**, and recalculated from our own shipments monthly. |
| **COD service charge** | **0.5–1%**, per provider | Pathao's own page contradicts itself (FAQ says 0.5% in Dhaka, every table row says 1%) — resolved at runtime from its `price-plan` response. Steadfast charges 1% of (collected − delivery charge), a cheaper base than Pathao's product-price base. **Read from config, never hard-coded.** |

The practical consequence for the build: because no reliable national RTO figure exists, **gating COD on Steadfast's official `fraud_check` response at checkout is the highest-leverage integration available** — it substitutes real per-customer evidence for a national average nobody can supply.

---

## 7. Open Questions Blocking Full Scope

| # | Question | Blocks | Needed by |
|---|---|---|---|
| 1 | **The supplied Figma file is empty** — share the right file, approve the written spec, or supply references | All of Phase 2, then all UI | Gate 1 |
| 2 | Expected order volume at launch and at 12 months | Infrastructure sizing | Gate 1 |
| 3 | Does the business hold its own stock with supplier invoices, or dropship? | The entire inventory and purchasing module | Gate 1 |
| 4 | Who operates the admin daily, and how many staff need separate restricted logins? | Roles and permission design | Phase 3 |
| 5 | Existing catalog, customer list or order history to migrate, and in what format? | Migration scope, currently **not** in any estimate | Phase 4 |
| 6 | Trade licence, TIN/BIN, bank account — do they exist? | Payment-gateway onboarding, which has the longest lead time of anything in the plan | **Week 1** |
| 7 | Do Pathao and Steadfast merchant accounts with API credentials exist? | Phase 6, and the fixture capture that validates every driver detail | **Week 1** |
| ~~8~~ | ~~Bangla UI at launch, or P2?~~ | — | **Resolved 2026-11-08: Bangla UI ships at launch.** See §6 item 4 |
| 8a | **Who writes the Bangla content?** Every product name, description, category label and policy page needs a human Bangla version. Machine translation is explicitly rejected — it is the thing we are beating Daraz on. **This is now the critical-path content dependency, ahead of photography** | Launch readiness | Gate 1 |
| 8b | Confirm Western numerals for prices and all figures in the Bangla UI (§6 item 4a) | Formatting | Phase 3 |
| 9 | Is a VAT/Mushak-compliant invoice required? **Confirm with an accountant** — sources conflict on the registration threshold (Tk 50 lakh vs Tk 3 crore) | N-14 | Phase 8 |
| 9a | **Does BSTI certification require a CM licence or consignment-wise clearance for imported toys?** Confirm with BSTI and a C&F agent | Import economics, and the product-safety fields | **Before any import commitment** |
| 9b | Get **Steadfast's return-charge policy in writing** — its own Terms and Business pages contradict each other. At 15–30% RTO this line can outweigh the forward tariff | P&L accuracy, provider choice | Phase 6 |
| 9c | Digital Commerce Operation Guidelines: the refund deadline (7 vs 10 days) and the advance-payment trigger (48 vs 72 hours) **conflict between sources**, and the gazette PDF is in legacy Bijoy encoding and machine-unreadable. **Verify with a lawyer** — do not treat our reading as authoritative | Return/refund policy copy and timers | Phase 7 |
| 10 | Who shoots product photography, and against what standard? | Launch catalog readiness — the owner's largest non-development workload | Phase 2 |
| 11 | Logo, wordmark, existing brand colours? | Design system | Gate 1 |

---

## 8. Success Measures

Measured from launch; targets to be agreed with the owner, not asserted here.

**Commercial** — conversion rate · average order value · repeat-purchase rate within 90 days · organic sessions and organic revenue share · cart-abandonment rate · revenue per visitor.
**Operational** — COD delivery success rate · courier return rate · **fake/failed orders prevented by the risk module** (the clearest ROI figure in the system) · time from order to courier booking · stock accuracy at monthly count · days from delivery to COD remittance.
**Technical** — Core Web Vitals field data on all three key templates · indexed page coverage · products earning review stars in search results · uptime · error rate · queue health.
**Qualitative** — can the owner answer "what did I earn last month, after everything?" without a spreadsheet, and change the homepage without a developer? If not, the project has not delivered its main promise.
