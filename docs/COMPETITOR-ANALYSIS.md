# Toy Mart BD — Competitor Analysis

**Date of audit:** 2026-11-07 · **Method:** direct HTTP fetches with a desktop Chrome user-agent, redirects followed, raw HTML and JS bundles inspected. Homepage, a category listing, a product detail page, cart, checkout, `/sitemap.xml`, `/robots.txt` and a blog page were fetched per site where reachable.

**Evidence discipline.** `Yes` means observed in a fetched response. `No` means **verified absent** — searched for in the place it would have to appear, and not found. `NV` means **not verified** — the page was client-rendered, gated or unreachable. Nothing in this document is inferred from "most e-commerce sites do this". Full per-site captures with quoted evidence strings are in `docs/_research/agent1-competitors.md` and `agent2-competitors.md`.

### Stated limitations
1. **`kidszone.com` is not a competitor.** Verified: a parked domain serving an ad-monetisation pixel. No `<title>`, no products, no platform, `robots.txt` connection-resets, `sitemap.xml` returns an HTML error page. It should be removed from the competitor set.
2. **Two sites could not be fully audited** because their money pages render entirely client-side (`kidszonebd.shop`, and SK Toy's PLP/PDP). Their feature rows are `NV`, not `No`. That opacity is itself the finding.
3. **`sktoy.com.bd` and `krystal.com.bd` serve a Cloudflare-managed `robots.txt` containing `User-agent: ClaudeBot` / `Disallow: /`.** Fetch volume was kept low and user-directed, and the restriction is recorded as a finding rather than worked around.
4. Catalog sizes are counted from sitemaps and are therefore a floor, not an exact figure.

---

## 1. The Competitive Set

| # | Site | Platform (evidence-based) | Products | Verdict |
|---|---|---|---|---|
| 1 | **sktoy.com.bd** | Next.js App Router, headless against a separate Node/Mongo API (`api.sktoy.com.bd`, Mongo ObjectIds, S3, Firebase). Built by "Rootdevs" | 81 | Best marketing stack, worst crawlability on money pages |
| 2 | **krystal.com.bd** | **Astro** frontend over **headless WooCommerce** (`backendapi.krystal.com.bd`, `x-powered-by: PHP/8.5.12`, `wp-json/wc/store/v1`) | **820** | Best checkout in the set; catastrophic SEO |
| 3 | **toyshop.com.bd** | WordPress + WooCommerce + `Elementor 4.3.6` + Woostify + Site Kit | 126 | Worst purchase experience in the set |
| 4 | **kidsrepublicbd.com** | `WordPress 7.1.4` + `WooCommerce 11.2.2` + Theme Freesia + Yoast + 10 plugins | 71 | Best structured data; slowest site |
| 5 | **kidszonebd.shop** | Angular SPA on the `saleecom` SaaS platform | unknown (no sitemap) | Effectively invisible to search |
| 6 | **toyhousebd.com** | WooCommerce + Elementor/ShopEngine | **12** | Barely trading; broken commercially |
| 7 | **babybazarbd.com** | WooCommerce + Woodmart | 666 | Strong taxonomy, weak merchandising |
| 8 | **edutoyzone.com** | **nopCommerce** + custom front-end | 510 | **The site to beat** |
| 9 | ~~kidszone.com~~ | Parked ad domain | — | Not a competitor |

**Platform conclusion.** Six of eight real competitors run WooCommerce, directly or headless. One runs nopCommerce. One runs an unnamed Angular SaaS. **Nobody in this market has a purpose-built operational back end** — which is where half of Toy Mart BD's value sits, and why no competitor can match it by installing a plugin.

---

## 2. Comparison Matrix

As requested: feature → who does it → how → what works → what doesn't → our opening. Only the findings that change a decision are listed; the exhaustive 60-row checklist is in `FEATURE-MATRIX.md`.

| Feature | Competitor | Implementation observed | Strengths | Weaknesses | Toy Mart BD opportunity |
|---|---|---|---|---|---|
| **Age-based browsing** | SK Toy, Kids Republic, EduToyZone, Baby Bazar | SK Toy: homepage age tiles. Kids Republic: age taxonomy. **EduToyZone: 7 age bands as a real PLP filter with result counts.** Baby Bazar: age categories | EduToyZone proves parents use it — it is the only one that made it a *filter* | **Zero of nine let you combine age with price, brand or gender.** SK Toy and Kids Republic only navigate by age; they cannot filter a listing | Age stored **in months**, combinable with every other facet, URL-addressable and shareable. Nobody has this |
| **Brand filter** | **None** | — | — | **0 of 9 — verified absent on all 7 auditable PLPs** | Trivial to build, immediately differentiating for parents buying a known brand |
| **Gender filter** | **None** | — | — | **0 of 9 — verified absent** | Same |
| **Skill / developmental filter** | Baby Bazar, EduToyZone | Baby Bazar: 10 therapy goals as categories. EduToyZone: 16 skills as a filter | Validated demand, and the strongest reason to buy from a specialist | Baby Bazar's is categories only, not filterable. Neither is combinable with age | Make "what does my 2-year-old need for fine-motor work" a single filtered, indexable URL |
| **Search quality** | All | Krystal and Toyshop have autocomplete; Kids Republic has none | Autocomplete present on 3 | **0 of 5 testable sites survived a one-character typo** — `puzle` and `lgo` both return "No products were found". No Bangla/Banglish equivalence anywhere | Meilisearch with typo tolerance and Bangla↔Banglish↔English synonyms. A visible win on the first visit |
| **Stock accuracy** | All | WooCommerce/nopCommerce stock flags | EduToyZone shows per-variant stock; Krystal shows low-stock counts | **Toyshop: 19 of 28 homepage products out of stock**, most PLP buttons read "Read more". **SK Toy: 2 products priced ৳0**, 4 "Coming Soon", 1 "SOLD OUT" on the homepage. **Baby Bazar shows no stock state at all, even on variable products** | Accurate, visible, per-variant stock. Unglamorous and the cheapest trust win available |
| **Delivery charge transparency** | 5 of 9 publish one | SK Toy ৳80/৳130 free >৳2,500 · Krystal ৳70/৳115 free >৳1,500 · EduToyZone **৳70/৳100/৳120 with a Dhaka-subcity tier** · Baby Bazar flat ৳130 national | EduToyZone's 3-tier zone model is the best; Krystal states charges in Bangla | **Toyshop hides it behind "Enter your address to view shipping options". Toy House BD computes none at all — the order total equals the subtotal.** Kids Republic publishes nothing | Charge shown **on the product page before the cart**, from the same code path as checkout so the two can never disagree |
| **Policy consistency** | — | — | — | **SK Toy states three different free-delivery thresholds** (৳3,000 banner / ৳2,500 config / ৳60 in schema). **Krystal's shipping page says free over ৳10,000 while its checkout says ৳1,500**, and its hotline prints as `xxxx-xxxxx` on a live page. **EduToyZone states its threshold three ways** (1,999 / 2,000 / 3,000) | One source of truth, rendered everywhere. This is a data-model decision, not a copywriting one |
| **Checkout** | All | **Krystal: 1 page, 7 fields, 64 districts + dependent thana, optional email, COD + SSLCommerz, coupon, Bangla charges.** EduToyZone: phone-first, email optional, 36+ districts with Dhaka subcities | Krystal and EduToyZone are both genuinely good and are the bar | **Toyshop: 3 steps, required email, a `2 + 2 = ?` math captcha, untranslated "State / County" and "Postcode / ZIP", no COD.** **Toy House BD is COD-only and collects no phone number at all.** Krystal has no cart page — `/cart/` 404s | One page, phone-first, email optional, district→upazila→area, landmark field, COD default, charge already known. Beats 7 of 9 outright |
| **Payment** | — | SK Toy COD with bKash `enabled:false` while bKash badges sit in the footer. **Krystal is the only site with a real automated gateway (SSLCommerz).** Toyshop: **manual** bKash/Rocket/Nagad — the buyer sends money first, then types their own account number and transaction ID | Krystal's SSLCommerz is the only working digital rail in the set | **8 of 9 are COD-only in practice.** Toyshop's manual-transfer flow asks a stranger to pay before ordering | COD first-class **plus** one working aggregator gateway. Low bar, real differentiation |
| **Reviews** | Kids Republic, EduToyZone | WooCommerce reviews; Yoast emits `AggregateRating` | Kids Republic is the only site with `AggregateRating` in schema | **One single customer review exists across ~1,100 products in agent 1's five sites.** Two of three in agent 2's set have reviews switched off entirely. **8 of 9 emit no `AggregateRating`, so no competitor earns review stars in search results** | Post-delivery SMS/WhatsApp review requests, moderated, `is_verified_purchase` proven from the order item. Being the only BD toy store with real review volume is both conversion and SERP real estate |
| **Structured data** | Mixed | Kids Republic (Yoast) is strongest. EduToyZone has Product + Offer + **FAQPage ×2** | EduToyZone is the only one with FAQ schema | **Krystal: 820 products, zero JSON-LD, zero canonical, zero Open Graph on every page type** — the single biggest SEO hole found. **Kids Zone BD emits nothing at all.** **SK Toy's `Product` schema is broken: `"price": 0` with `"availability": "InStock"`.** Kids Republic emits **duplicate `rel=canonical`**. Toyshop uses a numeric post ID as `sku` and has no `brand`. **`hreflang`/bn-BD: 0 of 9** | Correct Product + Offer + AggregateRating + BreadcrumbList + Organization + FAQPage, Bangla fields, proper locale. Takes the rich results uncontested |
| **Crawlability** | — | — | Krystal, Toyshop, Kids Republic server-render everything | **Kids Zone BD returns one byte-identical 53,313-byte Angular shell titled "E-commerce Platform" for every URL including `/this-path-does-not-exist-12345`** (all MD5 `fa7ce14b…`), no sitemap, ~682 KB of JS before first paint, and surfaced nowhere in a branded search. **SK Toy's PDP `<main>` contains only a spinning loader** — its homepage is server-rendered but its money pages are not | Server-rendered HTML for every indexable page, ISR-cached at the CDN edge. Two competitors have conceded product and category search entirely |
| **Performance** | — | Measured TTFB on a PDP | Toyshop 0.48s TTFB, Krystal 17.9 KB gzipped HTML | **Kids Republic: 2.27s TTFB with 91 `<script>` and 21 `<link rel=stylesheet>` on one PDP.** **Toyshop ships 90 KB gzipped / 421 KB raw HTML.** SK Toy 1.52s TTFB. Kids Republic declares `viewport` with no `initial-scale`; **Kids Zone BD blocks pinch-zoom** (`user-scalable=no`) | CI-gated Core Web Vitals budgets. The one ranking factor fully under our control, and every competitor is leaving it on the table |
| **Analytics** | Mixed | SK Toy: GA4 + Meta Pixel + **TikTok Pixel**. EduToyZone: GTM + GA4 + Meta Pixel. Krystal: GTM only. Baby Bazar: Meta Pixel only | SK Toy and EduToyZone are competently instrumented | **Toy House BD and Kids Zone BD run zero analytics.** Toyshop and Kids Republic have **no Meta Pixel** in a market where Meta is the dominant paid channel. Nobody has server-side conversion tracking | GA4 Measurement Protocol + Meta CAPI with browser/server deduplication and a diagnostics screen. Recovers the 20–40% of conversions ad-blockers and iOS suppress |
| **Content / blog** | 8 of 9 have the route | — | Krystal has 88 posts | **All are dormant, off-market or demo cruft.** Baby Bazar's and EduToyZone's blog pages have **no posts**. Toyshop has 4 dormant. **Kids Republic still serves 2019 theme demo posts** (`/2019/03/27/10-student-discount/`) and lists them in its sitemap. Krystal's 88 posts are off-market filler | All parenting, age-guide and gift-guide search intent is uncontested. Plus **3 of 5 of agent 1's sites block AI crawlers in robots.txt**, conceding AI-answer citations too |
| **Courier transparency** | 2 of 9 | Krystal names four (PaperFly, Sundarban, SA Poribohon, "SteadTas" — sic). Baby Bazar names Pathao | Krystal at least tells the buyer who will deliver | **7 of 9 never name a courier. No site exposes shipment-level tracking.** Kids Republic's tracking page 404s | Named courier, real consignment tracking, a customer timeline. Direct output of the courier integration we need anyway |
| **Urgency / promotions** | **None** | — | — | **0 of 9 run a flash sale or countdown. Baby Bazar has the capability and explicitly disabled it (`sale_countdown:0`)** | Flash sales with countdown, a promotions engine with priority and stacking |
| **Bundles** | 1 of 9 | EduToyZone's "More Savings in a Bundle" / Combo Offer | The single most imitable commercial idea in the audit | Nobody else has bundles or frequently-bought-together | Play-progression kits — a marketplace structurally cannot assemble cross-SKU developmental bundles |
| **Gift mechanics** | **None** | — | — | **0 of 9 offer gift wrapping, a gift message, a gift finder or a price-band gift guide** — in a category driven by birthdays and Eid | Entirely unclaimed |
| **Safety / standards** | ~None | Ad-hoc warning lines inside EduToyZone descriptions | — | **No site presents materials, certification or choking-hazard information systematically** | The strongest available trust signal in a category where a parent cannot verify quality |
| **Bangla** | 6 of 9 | Krystal states delivery charges in Bangla; EduToyZone has extensive Bangla | Krystal and EduToyZone do it properly | Bangla appears "almost always only in blog posts, trust strips or product descriptions, **never in the UI chrome or checkout**". 2 of 9 have no Bangla at all. `hreflang`: 0 of 9 | Bangla content fields from day one, Bangla UI as a fast follow with a proper `bn-BD` locale |
| **Messenger / WhatsApp** | 4 of 9 | EduToyZone places both in 3 locations; Krystal has both plus an **AI chat assistant** | EduToyZone's "Need help before ordering?" is the right instinct | **Messenger: 1 of 5 in agent 1's set**, despite being the default BD customer-service channel | Consultative pre-purchase help is something a marketplace cannot offer for a single vertical |

---

## 3. The Four Universal Gaps

Across all auditable competitors, these are absent. Each is cheap to build and none requires beating anyone on price or catalog size.

1. **Nobody lets a parent filter by age, brand or gender.** Two sites navigate by age; none filters by it. The most natural way a parent shops for toys is unserved by the entire market.
2. **Nobody has reviews.** One real review across ~1,100 products in agent 1's five sites. Eight of nine emit no `AggregateRating`, so **no competitor earns star ratings in Google results**.
3. **Nobody has working content.** Every blog is empty, dormant or demo cruft. All informational search intent — age guides, developmental milestones, gift guides, Bangla parenting content — is uncontested. Three sites additionally block AI crawlers, conceding AI-answer citations as well.
4. **Nobody's data is trustworthy.** Prices of ৳0 marked in stock. Three contradictory delivery thresholds on one site, twice. A live page printing `xxxx-xxxxx` as its hotline. A COD-only store that collects no phone number. 2019 demo posts in a production sitemap. **Being merely accurate is a differentiator here.**

---

## 4. The Five Most Exploitable Weaknesses

**1 — Structured data and Bangla-first SEO are unowned.** Krystal has 820 products and emits zero JSON-LD, zero canonical and zero Open Graph. Kids Zone BD emits nothing. SK Toy's `Product` schema is actively broken. Only one site has `AggregateRating`, built on one review. **No site has `FAQPage` across agent 1's five; none has `hreflang` or a bn-BD variant anywhere.** Shipping correct Product + Offer + AggregateRating + BreadcrumbList + FAQPage with Bangla fields takes the rich results and AI citations uncontested.

**2 — Checkout friction, with Toyshop as the extreme.** No COD, manual money transfer where the buyer pays first and types their own transaction ID, three steps, required email, a math captcha, and untranslated WooCommerce address labels. Toy House BD is COD-only and collects no phone number. Krystal is the bar to beat — and even it has no cart page and contradicts its own shipping policy. A one-page, phone-first, district→thana, COD-default checkout with the charge shown before any field is typed beats seven of nine.

**3 — Two competitors have conceded organic search.** Kids Zone BD serves one identical shell for every URL with no sitemap and no analytics, and does not appear in a branded search for its own name. SK Toy server-renders its homepage but ships a spinning loader on every product and category page. Those queries are free.

**4 — Stock, price and policy data are visibly wrong across the board.** Documented in §2. Accurate stock, real prices and one consistent delivery policy are low-cost and high-trust.

**5 — Toy-specific merchandising does not exist.** No age/brand/gender faceting, no bundles beyond one competitor, no countdown mechanics, no gift flows, no structured age guidance, one review in eleven hundred products, and search that breaks on a one-letter typo.

---

## 5. What EduToyZone Gets Right

EduToyZone is the strongest competitor and the benchmark to beat, so its wins are listed explicitly rather than buried:

Age filter with 7 bands **and result counts** · 16 developmental skills as a filter · product video on the PDP · per-variant pricing **and** per-variant stock · an age badge on the PDP · bundles and combo offers · a free-delivery progress bar · phone-first checkout with optional email · 36+ districts **with Dhaka subcity granularity** · a 3-tier delivery charge (৳70 / ৳100 / ৳120) · phone-based order lookup at `/findorder` · WhatsApp and Messenger in three places · extensive Bangla · Product + Offer + **FAQPage ×2** · full GTM + GA4 + Meta Pixel.

**Where it is still beatable:** no brand or gender filter · no flash sale or countdown · sparse reviews and no `AggregateRating` · an empty blog · no Open Graph on the homepage · a self-contradictory age badge · and a free-delivery threshold stated three different ways. It is also nopCommerce, so its operational back end is a generic .NET admin, not a BD-specific order/courier/risk/P&L system.

---

## 6. AppByte Benchmark

Benchmarked separately as a software product, not a competitor store. Full detail in `docs/_research/agent3-appbyte-and-couriers.md`.

### Finding: there is no product site to audit
`appbyte.net` serves **EcomByte** by "appByte Technologies Ltd". It is a single-page, 100% client-rendered React application. Sixteen paths were probed — `robots.txt`, `sitemap.xml`, `/features`, `/pricing`, `/docs`, `/blog`, `/demo`, `/integrations`, `/changelog`, `/api-docs`, `/login`, `/dashboard` and others — and **every one returns the identical 634,483-byte SPA shell.** The footer lists all those pages; the links have no `href`. There is no documentation, help centre, API reference, changelog, legal page or demo tenant.

Everything below was recovered by extracting string literals from the inlined JS bundle: a 29-item FAQ array, a 3-plan pricing array, a 31-row feature-comparison table and a 29-card feature grid. **That is reliable evidence of what AppByte claims, and nothing more.** No independent verification exists — no review, comparison, testimonial or forum thread in English or Bengali. Every "screenshot" on the site is a hand-coded mock with fabricated data. Maturity signals are weak: a numeric-ID Facebook page, a Gmail business address, a private or deleted GitHub repository.

### Confirmed claimed capabilities
Sourced verbatim from the bundle. **Claimed, not observed working.**

| Module | Claimed capability |
|---|---|
| Orders | Statuses `pending, confirmed, in_transit, delivered, returned, cancelled` · sources including phone · `fbclid`/`ttclid`/`gclid`/UTM retained 90 days · round-robin staff assignment · offline entry |
| Inventory | SKU, variants, **batch tracking**, **multi-warehouse**, low-stock SMS alerts, bulk update, **dead-stock and valuation reports** |
| Couriers | **Exactly 4: Steadfast, Pathao, RedX, Carrybee** |
| Fake Order Guard | Phone-number lookup — see below |
| Accounting | Gross sales / net profit, ad and courier costs, **projected profit from in-transit parcels** |
| COD | Per-order status, per-courier amounts due, Excel export |
| Invoices | **10+ templates, 3-up per page, barcode and sticker printing** |
| CRM | OTP login, one-click WhatsApp, reviews |
| Products | Coupons, bundles, **B2B quotations** |
| Access | RBAC admin/manager/employee, unlimited staff |
| Notifications | Bengali SMS, in-app bell, **browser push (VAPID)** |
| Payments | bKash/Nagad/Rocket advance collection with proof verification |
| Tracking | **Meta CAPI + TikTok Events API** |
| Other | Landing-page builder, analytics |

**Pricing — annual prepay, not monthly SaaS.** Basic ৳7,000/yr · Pro ৳12,000/yr · Enterprise ৳30,000 + ৳1,000 setup. VAT included, hosting bundled, no commission or per-order fee. **Every CTA is "contact us" → WhatsApp; there is no signup, trial or checkout.** Market context: Easy Order Manager is ৳390/month or ৳9,900 lifetime; Bonik AI around ৳590/month. **The market clears at roughly ৳400–1,000/month.**

⚠️ **One internal contradiction worth noting:** the pricing table claims data from "৭ টি কুরিয়ার" (7 couriers) while the FAQ says 4 and the UI renders an array of length 4.

### The fake-order check, as actually described
Lookup is **by phone number**, returning per-courier signals rendered beside each courier's logo: parcels / delivered / returned counts, success ratio, number age and verification, average delivery time, address verification, **"4 names at one address"**, "3 COD payments not taken", "didn't answer verification call". Three tiers with success gauges (94% / 56% / 7%) map to auto-confirm / phone-verify / block. The stated rule is explicit: **"স্কোর ৭০% ছাড়ালে অর্ডার নিজে থেকেই আটকে যায়"** — above a 70% risk score the order is held automatically.

**AppByte never discloses its data source.** The category mechanism was confirmed from working open-source code (`AbiruzzamanMolla/Fraud-Checker-BD-Courier-Laravel`): vendors **store the merchant's courier-portal email and password** and call reverse-engineered internal endpoints — `POST merchant.pathao.com/api/v1/user/success`, `GET steadfast.com.bd/user/frauds/check/{phone}` (CSRF + cookie login), RedX's `customer-success-return-rate`, Carrybee's `/fraud-check/{phone}` — normalising to `success/cancel/total/success_ratio`. **Undocumented, unsupported, and requiring stored passwords.**

**The important exception:** **Steadfast publishes an official fraud endpoint.** `GET /fraud_check/{phone}` appears in its own Postman collection, returning `total_parcels` / `total_delivered` / `total_cancelled` / `total_fraud_reports`. A newer `/fraud_check/score/{phone}` exists but currently returns `score` and `level` as `null` with `scoring_disabled: true`.

**Our decision:** official APIs only. Toy Mart BD will not store a merchant's courier portal password to scrape unsupported endpoints, and accepts thinner signal on a genuinely first-time customer as the price. Reasoning in `PRODUCT-REQUIREMENTS.md` §6.

### Separating confirmed from recommended
**Confirmed on AppByte** — everything in the capability table above, as a claim in its own bundle.
**Inferred / category-standard but not verified on AppByte** — actual working quality of any module, real courier API depth, data accuracy of the fraud check, report correctness, uptime, support responsiveness. None of this could be verified, because there is no demo, no documentation and no independent review.
**Our additions, not AppByte features** — supplier and purchase-order management with landed costing, partial-delivery handling, COD settlement-to-consignment matching, the revenue-versus-cash ledger separation, slug-history 301s, and CI-gated Core Web Vitals.

### What AppByte appears not to do — our openings
No supplier or purchase-order module at all · **no partial-delivery state**, although both couriers support it · no COD settlement matching · no COD gating by success rate · no device blocking · no cross-merchant fraud reports · no Mushak/VAT-compliant invoicing · and it paywalls OTP and SMS out of its cheapest tier, which is the tier its fraud-check marketing targets.

---

## 7. Strategic Conclusion

The market is larger in count than in strength. Eight real competitors, six on WooCommerce, and **not one with a purpose-built operational back end**. The strongest (EduToyZone) wins on merchandising but runs a generic nopCommerce admin. The one with the best checkout (Krystal) emits no structured data at all across 820 products. The one with the best marketing stack (SK Toy) ships a spinner on every product page.

**So the opening is not a feature. It is competence applied in two places at once:**

1. **A storefront that is correct** — accurate stock, one consistent delivery policy, a charge shown before the cart, age/brand/gender faceting, search that survives a typo, real reviews, complete schema, server-rendered and fast.
2. **A back office no competitor has** — one-click courier booking, phone-history risk screening from official APIs, landed-cost inventory, and a profit figure that survives courier charges, returns and refunds.

Neither requires beating anyone on price or catalog size. Both are things this market has simply left undone.
