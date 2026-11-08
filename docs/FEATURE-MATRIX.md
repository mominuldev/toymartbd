# Toy Mart BD — Feature Matrix & Gap Analysis

**Date:** 2026-11-07 · **Legend:** `Yes` observed in a fetched response · `No` **verified absent** (searched where it would have to appear) · `NV` **not verified** (client-rendered, gated or unreachable) · `—` not applicable

Sources: `docs/_research/agent1-competitors.md` (SK Toy, Krystal, Toyshop, Kids Republic, Kids Zone BD), `agent2-competitors.md` (Toy House, Baby Bazar, EduToyZone, Daraz, Othoba), `agent3-appbyte-and-couriers.md` (AppByte, courier APIs).

Abbreviations: **SK** SK Toy · **KR** Krystal · **TS** Toyshop · **KRe** Kids Republic · **KZ** Kids Zone BD · **TH** Toy House · **BB** Baby Bazar · **ETZ** EduToyZone · **DZ** Daraz (marketplace) · **OT** Othoba (marketplace)

---

## 1. Platform & Rendering

| | SK | KR | TS | KRe | KZ | TH | BB | ETZ | DZ | OT |
|---|---|---|---|---|---|---|---|---|---|---|
| Platform | Next.js + Node/Mongo | Astro + headless Woo | WP/Woo + Elementor | WP 7.1.4 / Woo 11.2.2 | Angular (saleecom) | Woo + ShopEngine | Woo + Woodmart | **nopCommerce** | Lazada/Alibaba | **nopCommerce** |
| Products (sitemap floor) | 81 | **820** | 126 | 71 | unknown | **12** | 666 | 510 | 4,080 in toys | **1** in `/kids-toys` |
| Server-rendered home | Yes | Yes | Yes | Yes | **No** | Yes | Yes | Yes | No (JS shell) | Yes |
| Server-rendered PLP | **No** | Yes | Yes | Yes | **No** | Yes | Yes | Yes | **No** | Partial (cards hydrate) |
| Server-rendered PDP | **No** | Yes | Yes | Yes | **No** | Yes | Yes | Yes | Partial | Yes |
| TTFB measured (PDP) | 1.52s | 1.05s | **0.48s** | **2.27s** | 0.19s (shell) | NV | NV | NV | — | — |
| HTML weight (gzip / raw) | 18 / 81 KB | **18 / 73 KB** | **90 / 421 KB** | 32 / 155 KB | 8 / 53 KB | NV | NV | NV | 182 KB JSON | — |
| Scripts / stylesheets on PDP | 27 / 2 | 15 / 4 | 28 / 2 | **91 / 21** | 2 / 3 | NV | NV | NV | — | — |
| Category depth | 3 | **1 (flat)** | 2 | 2 | NV | **1** | 3 | 3 | 3 | **5** |

---

## 2. Discovery & Merchandising

| | SK | KR | TS | KRe | KZ | TH | BB | ETZ | DZ | OT |
|---|---|---|---|---|---|---|---|---|---|---|
| **Age filter on PLP** | **No** (tiles only) | **No** | **No** | **No** (taxonomy only) | NV | **No** | **No** (categories only) | **Yes** (7 bands + counts) | **Yes** but free-text chaos † | **No** |
| **Brand filter** | NV | **No** | **No** | **No** | NV | **No** | **No** | **No** | Yes | Yes |
| **Gender filter** | NV | **No** | **No** | **No** | NV | **No** | **No** | **No** | Yes (7 values) | **No** |
| **Skill / interest filter** | **No** | **No** | **No** | **No** | NV | **No** | Categories only (10 goals) | **Yes** (16 skills) | Yes (15 interests) | **No** |
| Price filter | NV | Yes | Yes | Yes | NV | Yes (min/max) | **No** | Yes (4 bands) | Yes (min/max) | **No** |
| Availability filter | NV | Yes | **No** | **No** | NV | NV | NV | NV | Yes | **No** |
| Rating filter | NV | **No** | **No** | **No** | NV | **No** | **No** | **No** | Yes | Yes |
| Sort options | NV | 4 (ships `disabled`) | 5 | 5 | NV | 5 | 5 | 5 (+ best-selling) | **Only 3** ‡ | 8 (raw enum names) |
| Search autocomplete | NV | Yes | Yes (AJAX) | **No** | NV | **No** | Yes | **Yes** + popular searches | Yes | Typesense + Algolia |
| **Typo tolerance** | NV | NV | **No** | **No** | NV | NV | NV | NV | NV | NV |
| Bangla/Banglish search equivalence | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **No** | **No** |

† **Daraz's exploitable flaw.** `Recommended Age` is a **seller-typed free-text attribute**, producing ~30 overlapping, unsortable buckets on one page: `"3 years"`, `"3 years and above"`, `"2 years and above"`, `"From 3 months +"`, `"From 0 month +"`, `"4 Months"`, `"18 months +"`, `"0-5 Years"`, `"0 - 12 years"`, `"4-10 years"`, `"5-7 years"`, `"6-18 Years"`, `"10 and Up"`, `"14 Years and above"`, `"Other"`. `Warranty Period` is worse — raw database IDs surface as labels (`"26"`, `"42"`, `"112"`) beside `"1 Month"`. **A single-merchant store with age stored in months beats the market leader on its own best facet.**

‡ Daraz offers only `Best Match`, `Price low→high`, `Price high→low`. **No newest, no top-rated, no best-selling sort** across 4,080 toy products.

---

## 3. Product Detail

| | SK | KR | TS | KRe | KZ | TH | BB | ETZ | DZ | OT |
|---|---|---|---|---|---|---|---|---|---|---|
| Gallery with zoom | NV | **Yes** | NV | NV | NV | Yes | Yes | Yes | Yes | Yes |
| Product video | NV | NV | NV | NV | NV | **No** | NV | **Yes** | NV | NV |
| Variants | NV | Yes | **No** | **Yes** | NV | **No** | Yes (dropdown) | **Yes** (priced per variant) | Yes (image-backed SKUs) | NV |
| **Per-variant stock** | NV | **Yes** (+ low-stock count) | Yes (binary) | Yes | NV | Yes (binary) | **No** — even on variable products | **Yes** | Yes (`Only N left`) | NV |
| Delivery info on PDP | NV | **Yes** (Bangla) | **No** | **No** | NV | **No** | **No** | Yes | Yes (`Get by {time}`) | **Yes — richest observed** ◆ |
| Structured age guidance | **No** | **No** | Free text only | Free text only | NV | **No** | **No** | **Yes** (badge, self-contradictory) | Free text | Bangla text (`বয়স উপযোগী (Ages 3+)`) |
| Reviews with ratings | NV | Form NV, **0 reviews** | Form yes, **0 reviews** | **Yes — 1 review** | NV | **No** | **No** | Yes (very sparse) | Yes (real volume) | Tab exists, `(0)` |
| Q&A | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **Yes** | Yes (login-gated) |
| Related products | NV | Yes | Yes | Yes | NV | **No** | Yes (8) | Yes | Yes | Yes |
| Frequently bought together | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **Yes** (combos) | **No** |
| Recently viewed | NV | **No** | Plugin (cookie) | **No** | NV | Yes (empty) | **No** | Yes | NV | NV |
| Bundles / combos | NV | **No** | **No** | **No** | NV | **No** | **No** | **Yes** | **Yes** | **No** |
| Trust badges | NV | Yes | **No** | **No** | NV | NV | NV | Yes | Yes | Yes (seller ratings) |
| Wishlist | Yes | Yes | CSS only, **no button** | Yes (YITH) | Yes (bundle) | Yes | Yes | Yes | Yes | Yes |
| Compare | NV | **No** | **No** | **No** | Yes (bundle) | Yes | Yes | Yes | NV | NV |
| **Safety / certification info** | **No** | **No** | **No** | **No** | NV | **No** | **No** | Ad-hoc in descriptions | Seller-dependent | Dev-benefit bullets |

◆ Othoba's delivery block is the single best PDP element found anywhere: `Available Delivery Area` · a location selector (`Dhaka | Dhaka City North`) with `Change` · `Delivery Time: Inside Dhaka 1–4 Working Days, Outside Dhaka 4–7 Working Days` · `Shipping Charge: Tk 60` · `Cash on Delivery Available`. **This is the pattern Toy Mart BD should match or beat.**

---

## 4. Cart, Checkout, Payment

| | SK | KR | TS | KRe | KZ | TH | BB | ETZ | DZ | OT |
|---|---|---|---|---|---|---|---|---|---|---|
| Cart page works | NV | **No — `/cart/` 404s** | Yes | NV | NV | Yes | Yes | Yes | Yes | Yes (location gate first) |
| **Guest checkout** | NV | **Yes** | Yes | NV | NV | Yes | Yes | **Yes** (phone-first) | NV | **No — OTP mandatory** |
| Checkout steps | NV | **1 page / 2 sections** | **3** | NV | NV | **3** | 1 | **1** | NV | 4 |
| **Phone collected** | NV | Yes | Yes | NV | NV | **No — while COD-only** | Yes (required) | **Yes (first field)** | Likely (SMS module) | **Yes (identity)** |
| Email required | NV | **No (optional)** | **Yes** | NV | NV | Yes | Yes | **No (optional)** | NV | NV |
| District dropdown | NV | **Yes (64)** | Yes — labelled "State / County" | NV | NV | Yes (64) | Yes | **Yes (36+ with Dhaka subcities)** | NV | Yes (location selector) |
| Dependent thana/area | NV | **Yes** | **No** | NV | NV | NV | NV | Yes | NV | NV |
| Captcha friction | NV | **No** | **Yes — `2 + 2 = ?`** | NV | NV | **No** | **No** | **No** | — | — |
| Coupon field | NV | Yes | Yes | NV | NV | Yes | Yes | Yes (cart + checkout) | **Yes — richest** | Yes |
| **COD** | **Yes** | **Yes** | **No** | NV | NV | Yes (only) | Yes (only) | Yes (only) | **Yes** | **Yes** |
| Automated online gateway | **No** (bKash `enabled:false`) | **Yes — SSLCommerz** | **No — manual transfer** ★ | NV | NV | **No** | **No** | **No** | Yes + installments | Yes (COD/Visa/MC/Amex/bKash) |
| **Delivery charge published** | Yes ৳80/৳130, free >৳2,500 | Yes ৳70/৳115, free >৳1,500 | **No — hidden behind address** | **No** | NV | **No — none computed at all** | Flat ৳130 national | **Yes ৳70/৳100/৳120 (3-tier)** | NV | `Tk 60` on PDP |
| Free-delivery threshold consistent | **No — 3 values** | **No — ৳10,000 vs ৳1,500** | — | — | NV | — | Footer claim only | **No — 3 values** | NV | — |
| EMI / installments | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **Yes** | **Yes** (≥ Tk 5,000) |

★ **Toyshop is the worst purchase experience found.** Verified: the only gateways are `woo_bkash`/`woo_rocket`/`woo_nagad`, all **manual** — *"Bkash করুন এই নাম্বারেঃ 01941111000… You need to send us ৳3350.00"*, after which the buyer types their own account number and transaction ID. A stranger is asked to send money before an order exists.

---

## 5. Post-purchase & Operations

| | SK | KR | TS | KRe | KZ | TH | BB | ETZ | DZ | OT |
|---|---|---|---|---|---|---|---|---|---|---|
| Order tracking page | Yes `/track` | Account + AI assistant | Yes `/track-order/` | **No — 404s** | Route only | **No** | Page full of demo content | **Yes `/findorder` (phone)** | **No public page** | **No — login required**, footer link is `href="#"` |
| Shipment-level status | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **No** |
| **Courier named** | **No** | **Yes (4)** ● | **No** | **No** | **No** | **No** | **Yes (Pathao)** | **No** | — | — |
| Return policy published | NV | Yes (contradictory) | NV | NV | NV | NV | NV | Yes | **NV** (shell) | **Yes — zero-day window** ▲ |
| WhatsApp | Yes (1 ref) | **Yes** | **No** | Yes | **No** | **No** | **No** | **Yes (3 places)** | — | REVE Chat |
| Messenger | **No** | **Yes** | **No** | **No** | **No** | **No** | **No** | **Yes** | — | — |
| AI chat assistant | **No** | **Yes** | **No** | **No** | **No** | **No** | **No** | **No** | — | — |
| Mobile app | **No** | **No** | **No** | **No** | **No** | **No** | **Yes (iOS + Android)** | **No** | Yes | Yes (app-only pricing) |

● Krystal names PaperFly, Sundarban, SA Poribohon and "SteadTas" — the last misspelled on a live page.
▲ Othoba, verbatim: *"After you accept the package, we are unable to receive claims for missing, wrong or defective items."* An effectively **zero-day, inspect-at-the-door return window**. A clear, honest 7-day return policy is a genuine differentiator against both marketplaces.

---

## 6. SEO & Analytics

| | SK | KR | TS | KRe | KZ | TH | BB | ETZ | DZ | OT |
|---|---|---|---|---|---|---|---|---|---|---|
| `rel=canonical` | Yes | **No** | Home + PDP only | **Duplicated ×2** | **No** | NV | NV | NV | Yes | NV |
| Open Graph | Partial (no `og:image` on home) | **No** | PDP only (2 tags) | Yes | **No** | Partial (2 tags) | Yes (complete) | PDP yes / **home none** | Yes | NV |
| Meta description on PDP | Yes | **No** | **No** | Yes | **No** | **No** | Yes | Yes (Bangla) | Yes (stale "2024") | NV |
| JSON-LD `Product` | Yes — **`price: 0`** | **No** | Yes | Yes | **No** | **No** | Yes (+ Offer, BDT) | Yes (+ Offer) | Yes (PDP) | Yes |
| JSON-LD `Offer` | Broken | **No** | Yes | Yes | **No** | **No** | Yes | Yes | **No on PDP** | NV |
| **JSON-LD `AggregateRating`** | **No** | **No** | **No** | **Yes** | **No** | **No** | **No** | **No** | PLP only, **not PDP** | **No** |
| JSON-LD `BreadcrumbList` | Yes | **No** | PDP only | Yes | **No** | **No** | NV | NV | **Empty** | Yes |
| JSON-LD `Organization` | Yes | **No** | PDP only | Yes | **No** | NV | NV | NV | NV | NV |
| **JSON-LD `FAQPage`** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **Yes ×2** | **No** | **No** |
| Working `sitemap.xml` | Yes (131) | Yes (946) | Yes | Yes (Yoast) | **No — serves SPA shell** | Yes (12) | Yes (666) | Yes (510) | Yes, **but no `Sitemap:` in robots.txt** | NV |
| **`hreflang` / bn-BD** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **No** (`hreflang_data: "[]"`) | **No** |
| GA4 | **Yes** `G-R9C6P60JKN` | **No** | **No** | **No** | **No** | **No** | **No** | **Yes** `G-ZSFE8WM8TS` | **No** | NV |
| GTM | **No** | Yes `GTM-PHRQWT93` | **No** | Yes `GTM-NX5CJB4G` | **No** | **No** | **No** | **Yes** `GTM-W7DGWV43` | **No** | NV |
| **Meta Pixel** | **Yes** `1516765536845697` | Wired, no `fbq` in HTML | **No** | **No** | **No** | **No** | **Yes** `229240508811973` | **Yes** `1071219174683903` | **No** | NV |
| TikTok Pixel | **Yes** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | NV |
| Server-side conversion API | **No** | **No** | **No** | **No** | **No** | **No** | **No** | **No** | first-party only | NV |
| Blog with real posts | Yes (7) | 88 (off-market) | 4 (dormant) | 27 (**2019 demo cruft**) | Route only | Route, **no posts** | Page, **no posts** | Page, **no posts** | — | — |
| robots.txt blocks AI crawlers | **Yes** | **Yes** | **Yes** | **No** | **No** | NV | NV | NV | **No** | NV |

---

## 7. Promotions & Urgency

| | SK | KR | TS | KRe | KZ | TH | BB | ETZ | DZ | OT |
|---|---|---|---|---|---|---|---|---|---|---|
| **Flash sale / countdown** | **No** | **No** | **No** | **No** | NV | **No** | **No — capability disabled** (`sale_countdown:0`) | **No** | **Yes — live + pre-launch** | NV |
| Collectible / copy vouchers | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **Yes** | Yes |
| Buy-X-get-Y / multi-buy | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **Yes** | NV |
| Free-delivery progress bar | **No** | **No** | **No** | **No** | NV | **No** | **No** | **Yes** | **No** | NV |
| Loyalty / coins | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **Yes** | NV |
| **Gift wrap / message / finder** | **No** | **No** | **No** | **No** | NV | **No** | **No** | **No** | **No** | **No** |

**Zero of eight niche toy stores runs any urgency mechanic.** Baby Bazar has the capability and explicitly disabled it. Daraz's voucher and countdown stack is the richest observed anywhere in this study and is what trains BD shoppers' expectations — a niche store cannot match its budget, but a scheduled flash sale with a countdown is a one-week build and nobody in the category has one. **Gift mechanics are absent from all ten properties, including both marketplaces**, in a category driven by birthdays and Eid.

---

## 8. Back-office Benchmark — AppByte vs. Toy Mart BD

AppByte's column is **claimed capability recovered from its JS bundle**, not observed working software: `appbyte.net` is a single-page React site where all 16 probed paths return the same 634,483-byte shell, and no demo, documentation or independent review exists.

| Capability | AppByte (claimed) | Toy Mart BD | Note |
|---|---|---|---|
| Order statuses | 6 | 3 orthogonal dimensions (business / payment / fulfilment) | A COD order can be delivered, paid, and the cash still not remitted. One status column cannot express that |
| Order sources + ad click IDs | Yes, 90-day retention | Yes | Parity |
| Manual / phone order entry | Yes | Yes | Parity |
| Round-robin staff assignment | Yes | **P2** | Only useful above a few staff |
| Bulk order processing | Yes | Yes, **with per-order result reporting** | Silent partial failure is worse than none |
| **Partial delivery** | **No** | **Yes** | Both couriers support it. A verified AppByte gap where money goes missing |
| Inventory: SKU, variants | Yes | Yes | Parity |
| Batch tracking | Yes | **P2** | Not needed for toys at launch |
| Multi-warehouse | Yes | **P2** (schema-ready, one seeded location) | — |
| Low-stock alerts | Yes (SMS) | Yes (+ digest, reorder points) | Parity |
| Dead-stock + valuation reports | Yes | Yes | Parity |
| **Suppliers** | **No** | **Yes** | Verified AppByte gap |
| **Purchase orders** | **No** | **Yes**, with partial receipt | Verified AppByte gap |
| **Landed cost (inbound freight apportioned)** | **No** | **Yes** | Ignoring inbound freight overstates margin 5–15% on imported toys |
| **Append-only stock ledger** | Not claimed | **Yes** | Makes "why is my stock wrong?" answerable |
| Couriers supported | 4 (Steadfast, Pathao, RedX, Carrybee) ⚠ | 2 at launch, **driver-based** | Adding a provider is a driver, not a refactor |
| One-click + bulk booking | Yes | Yes, **shown as "submitted" until confirmed** | Verified: neither provider returns ids on bulk |
| Courier location mapping | Not claimed | **Yes, per-provider** | Pathao needs it; Steadfast has no zone model at all |
| Failed-booking retry + circuit breaker | Not claimed | **Yes** | A provider outage must not strand orders silently |
| **COD settlement → consignment matching** | **No** | **Yes** | Verified AppByte gap. Steadfast's `/payments` makes it automatable. Usually the largest receivable on the books |
| Fake-order check | Yes, 4 couriers by phone | Yes, phone-keyed | — |
| …data source | **Undisclosed**; category standard is storing the merchant's courier portal password to scrape undocumented endpoints | **Officially published APIs only** (Steadfast `fraud_check`) + our own history | Deliberate trade-off: thinner first-order signal, no credential liability |
| …our score vs. courier data | Blended into one gauge | **Separate, labelled, timestamped panels** | Staff need to know whose evidence it is and how old |
| …action above threshold | **Auto-holds above 70%** | **Auto-holds**, never auto-cancels | Agreed on hold; a false-positive cancel loses a real parent |
| Risk score breakdown visible | Not claimed | **Yes** | An opaque number doesn't help the operator decide |
| Accounting: gross sales, net profit | Yes | Yes | Parity |
| **Revenue vs. cash vs. receivable separated** | Not claimed | **Yes** (`is_recognised_revenue` / `is_cash_movement`) | The core correctness requirement of a COD P&L |
| Net profit after courier, COD fee, returns, gateway, packaging, refunds | Partial (ads + courier) | **Yes, all terms** | Explicit brief requirement |
| Projected profit from in-transit parcels | Yes | **P2**, clearly labelled a projection | Good idea; cheap once the ledger exists |
| Order-wise / product-wise / category-wise profit | Partial | Yes | — |
| Invoices | 10+ templates, 3-up, barcode/sticker | Yes + **supplier invoices** + company snapshot | Reprinting an old invoice must show the details current then |
| **Mushak / VAT-compliant invoice** | **No** | **P2**, pending accountant confirmation | Verified AppByte gap |
| CRM: OTP login, WhatsApp, reviews | Yes | Yes | Parity |
| Customer LTV / AOV / segments | Analytics claimed | Yes (LTV in MVP, segments P2) | Guest orders auto-attach by phone, so LTV works without registration |
| RBAC | 3 roles, unlimited staff | **7 roles, granular permissions** | — |
| Immutable audit of price/cost/refund changes | Not claimed | **Yes** | Insider-risk control |
| Notifications: Bengali SMS, push | Yes (VAPID push) | SMS + email + WhatsApp (push **P2**) | **Per-message cost logged into the P&L** |
| Meta CAPI + TikTok Events | Yes | **Meta CAPI + GA4 MP with browser/server dedup, consent gating, diagnostics screen** | TikTok **P2** |
| Landing-page builder | Yes | **P2** | CMS block builder covers launch |
| B2B quotations | Yes | **P2** | — |
| **Storefront** | Not its product | **Included** | AppByte is back-office only — it does not give you a shop |
| Pricing | ৳7,000–30,000/yr prepay, **"contact us" only — no signup, trial or checkout** | Owned asset | Market clears at ~৳400–1,000/month (Easy Order Manager ৳390/mo; Bonik AI ~৳590/mo) |

⚠ AppByte's own pricing table claims data from "৭ টি কুরিয়ার" (7 couriers) while its FAQ says 4 and its UI renders an array of length 4.

---

## 9. Gap Analysis

### 9.1 Table stakes — everyone has it, so must we
Clean slug URLs · working sitemap · related products on the PDP · a wishlist · coupon field at checkout · `৳` pricing · declared mobile viewport · Cloudflare or equivalent in front · guest checkout (7 of 8 niche stores) · COD (8 of 8 in practice).

### 9.2 Verified absent across the category — our cheapest wins
Ranked by (impact ÷ effort):

| # | Gap | Who lacks it | Effort | Why it wins |
|---|---|---|---|---|
| 1 | **Combinable age + brand + gender + price faceting, URL-addressable** | **All 8 niche stores.** Daraz has the facets but its age data is seller-typed free text | Low | The way parents actually shop, unserved by the entire market. Also generates indexable landing pages nobody else has |
| 2 | **Accurate stock, prices and one consistent delivery policy** | Violated by at least 5 sites, twice each | Low | ৳0 prices marked in stock; three contradictory thresholds on one site; a hotline printed as `xxxx-xxxxx`. Being merely correct is a differentiator here |
| 3 | **Delivery charge on the PDP, before the cart** | 6 of 8 niche stores. Othoba does it best and is a marketplace | Low | A hidden charge found at checkout is a top abandonment cause. Toyshop hides it; Toy House computes none at all |
| 4 | **`AggregateRating` + real review volume** | 7 of 8. One review across ~1,100 products in agent 1's set. Even Daraz omits it from the PDP | Low (needs post-delivery SMS/WhatsApp) | Star ratings in Google results that no competitor earns |
| 5 | **Typo-tolerant Bangla ↔ Banglish ↔ English search** | **All 10.** Both testable sites fail on a one-character typo | Low (Meilisearch) | Visible on the first visit |
| 6 | **`FAQPage` schema + a working blog** | FAQ: 9 of 10. Blog: every one is empty, dormant or demo cruft | Low | All informational intent uncontested — and 3 sites additionally block AI crawlers, conceding AI citations too |
| 7 | **Named courier + real shipment tracking** | **Shipment-level tracking: 10 of 10.** Only 2 name a courier | Low (falls out of the courier integration we need anyway) | Direct trust signal, zero marginal cost |
| 8 | **Flash sale with countdown** | **All 8 niche stores.** Baby Bazar disabled its own | Low | Daraz trains the expectation; nobody in the category meets it |
| 9 | **Gift wrap, gift message, gift finder** | **All 10, marketplaces included** | Medium | A birthday- and Eid-driven category with no gift mechanic anywhere |
| 10 | **Systematic safety / certification information** | **All 10** | Low (content discipline) | The strongest trust signal available where quality is unverifiable |
| 11 | **Server-rendered, CI-gated fast pages** | 2 sites concede product search entirely; one PDP has 91 scripts and 2.27s TTFB | Medium (architectural) | The one ranking factor fully under our control |
| 12 | **Server-side conversion tracking with dedup** | **All 10.** Two niche sites have no analytics at all | Medium | Recovers the 20–40% of conversions ad-blockers and iOS suppress |
| 13 | **Clear 7-day return policy** | Othoba's is effectively zero-day; most niche stores publish nothing coherent | Low | Directly answers the "burned by a Facebook seller" objection |
| 14 | **Bundles / play-progression kits** | 9 of 10 (EduToyZone has them) | Medium | A marketplace structurally cannot assemble cross-SKU developmental kits |
| 14a | **Upsell ("Upgrade to") with the price delta stated** | **10 of 10 — verified absent everywhere, marketplaces included** | Low | Nobody in this market runs an upsell mechanic at all |
| 14b | **Age-progression recommendation** — the next developmental stage for a child who has outgrown this toy | **10 of 10** | Low (the data already exists: age in months + skill taxonomy) | The highest-value recommendation available in this category and the strongest repeat-purchase lever. Structurally impossible for a marketplace whose age data is seller-typed free text |
| 14c | **Accessory / consumable cross-sell** (batteries, storage, refills, expansion sets) | 9 of 10 | Low | A parent who gets an RC car home without batteries blames the shop |
| 14d | **Recommendation attribution** — revenue and units per placement | **10 of 10** | Medium | Turns "do recommendations work?" from an opinion into a line in the P&L |
| 15 | **Proper `bn-BD` locale + full Bangla UI** — *now MVP* | **All 10 lack `hreflang`.** Bangla on the niche sites appears "almost always only in blog posts, trust strips or product descriptions, never in the UI chrome or checkout". Daraz's Bangla is machine-translated badly — `Interest` → `সুদ` (financial interest), `ছাগল` (goat) in navigation | Medium | Human-written Bangla against machine-translated marketplace Bangla, with `hreflang` nobody else has. Moved into MVP on 2026-11-08 |

### 9.3 Where we deliberately do not compete
Price, catalog breadth, same-day delivery, voucher budget, installments, loyalty coins, a mobile app. Daraz wins all of these structurally. Trying to match them wastes the budget that buys §9.2.

### 9.4 Back-office gaps we fill that no competitor's platform does
Six of eight competitors run WooCommerce; one runs nopCommerce. **None has a BD-specific operational back end.** Supplier and purchase-order management with landed costing · an append-only stock ledger · one-click and bulk courier booking across providers · per-provider location mapping · partial-delivery handling · COD settlement-to-consignment matching · phone-keyed risk screening from official APIs with a visible score breakdown · and a profit figure that survives courier charges, COD fees, return charges, gateway fees, packaging, discounts, refunds and write-offs.

AppByte sells roughly this back office, without a storefront, for ৳7,000–30,000/year — and does it without suppliers, purchase orders, landed cost, partial delivery, settlement matching or VAT invoicing, while disclosing no data source for its headline fraud feature.

---

## 10. Recommended Feature Set

Full requirement-by-requirement detail with IDs in `PRODUCT-REQUIREMENTS.md` §3. Summary:

**MVP, storefront** — **full Bangla and English UI** with locale-prefixed routing and `hreflang` · age-in-months + skill + brand + gender + price faceting, combinable and URL-addressable · typo-tolerant trilingual search · **upsell, cross-sell, frequently-bought-together and age-progression recommendations, capped and placed — and nothing on checkout** · per-variant stock · delivery charge on the PDP from the same code path as checkout · one-page phone-first COD checkout with district→upazila→area and a landmark field · moderated reviews with verified-purchase proof and `AggregateRating` · systematic safety information · order tracking by number + phone · named courier with a real shipment timeline · flash sales with countdown · complete schema including `FAQPage` · slug-history 301s · server-side tracking with a diagnostics screen · a real blog.

**MVP, admin** — catalog with variant matrix and bulk import · append-only stock ledger · suppliers and purchase orders with landed cost · order board with bulk processing and order edit · Pathao + Steadfast one-click and bulk booking behind one abstraction · per-provider location mapping · failed-booking retry · partial delivery · phone-keyed risk screening with a visible breakdown and separately-labelled official courier data · COD settlement reconciliation · revenue/cash/receivable-separated P&L with product-wise profitability · invoices · CMS homepage builder · full SEO module · 7 roles with immutable audit of price, cost and refund changes.

**Also in MVP (added 2026-11-08)** — full Bangla and English UI, locale-prefixed routing with `hreflang`, per-locale SEO metadata and sitemaps, Bangla notification templates, and a persisted language toggle.

**Deferred (P2)** — Bangla *admin* UI · gift finder, wrap and message · bundles and buy-X-get-Y · children's profiles and birthday reminders · segmentation and campaigns · courier performance deep-dive · multi-warehouse and batch tracking · B2B quotations · landing-page builder · TikTok Events API · browser push · VAT/Mushak invoicing (pending accountant confirmation) · additional couriers and gateways.
