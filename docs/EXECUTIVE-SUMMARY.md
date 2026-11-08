# Toy Mart BD — Executive Summary

**Date:** 2026-11-08 · **Phase:** Research & planning complete, awaiting approval · **Decision required:** approve the roadmap and MVP boundary, and resolve two blockers

---

## 1. The Short Version

Nine competitors were audited directly — HTML, JS bundles, sitemaps, carts and checkouts fetched, with evidence quoted rather than assumed. One turned out not to be a competitor at all. What the rest showed is a market that is **larger in count than in strength**.

Six of the eight real competitors run WooCommerce. One runs nopCommerce. **Not one has a purpose-built operational back end.** Among them: a store with 820 products that emits no structured data at all, a COD-only store that collects no phone number, a store whose only payment method is asking strangers to transfer money and type their own transaction ID, a store that serves one identical page for every URL including nonexistent ones, and **one single customer review across roughly 1,100 products.**

**So the opportunity is not a feature. It is competence applied in two places at once:** a storefront that is simply correct and fast, and a back office that tells the owner the truth about money. Neither requires beating anyone on price or catalog size.

**Recommendation: build it, MVP-first.** ≈148 dev-days, ≈14–16 weeks with two developers and a half-time designer. Two blockers must be cleared first (§6).

---

## 2. What the Research Found

### The four universal gaps
1. **Nobody lets a parent filter by age, brand or gender.** Two sites navigate by age; none filters by it. Even Daraz, the market leader, stores "Recommended Age" as seller-typed free text — producing ~30 overlapping, unsortable buckets on one page (`"3 years"`, `"From 0 month +"`, `"0 - 12 years"`, `"10 and Up"`…). **A single-merchant store with age in months beats the market leader on its own best facet.**
2. **Nobody has reviews.** One real review across ~1,100 products. Eight of nine emit no `AggregateRating` — and **neither marketplace emits it on its product pages either**. Review stars in Google results are unclaimed by the entire category.
3. **Nobody has working content.** Every blog is empty, dormant, or demo cruft — one still serves 2019 theme placeholder posts in its production sitemap. All parenting, age-guide and gift-guide search intent is uncontested. Three sites additionally block AI crawlers, conceding AI-answer citations too.
4. **Nobody's data is trustworthy.** Products priced ৳0 marked "in stock". Three contradictory free-delivery thresholds on one site — on two different sites. A live page printing its hotline as `xxxx-xxxxx`. 19 of 28 homepage products out of stock. **Being merely accurate is a differentiator here.**

### The benchmark, and the extremes
**EduToyZone is the site to beat** — age filter with result counts, 16 developmental skills, per-variant stock, bundles, 3-tier delivery pricing, phone-first checkout, `FAQPage` schema, full analytics. It is still beatable: no brand or gender filter, no flash sale, sparse reviews with no `AggregateRating`, an empty blog, and a free-delivery threshold stated three different ways.

**At the other end:** Toyshop has no COD at all — only manual bKash/Rocket/Nagad transfers where the buyer sends money first, on top of a three-step checkout, a required email, and a `2 + 2 = ?` math captcha. Toy House BD is COD-only and **collects no phone number**, which makes its orders unfulfillable by any BD courier. Kids Zone BD returns one byte-identical 53 KB shell titled "E-commerce Platform" for every URL, has no sitemap, no analytics of any kind, and does not appear in a search for its own name.

### AppByte
`appbyte.net` is a single-page React marketing site. All 16 probed paths — including `/features`, `/pricing`, `/docs`, `/demo` — return the identical 634,483-byte shell. There is no documentation, demo tenant, or independent review in any language. Its claimed feature set was recovered from string literals in its own JS bundle: real evidence of what it *claims*, and nothing more.

It is priced at ৳7,000–30,000/year with "contact us" as the only call to action, in a market that clears around ৳400–1,000/month. **It has no supplier or purchase-order module, no landed costing, no partial-delivery state, no COD settlement matching, and no VAT-compliant invoicing** — and it does not include a storefront.

Its headline "fake order check" never discloses its data source. The category mechanism was confirmed from working open-source code: vendors **store the merchant's courier-portal email and password** and call reverse-engineered internal endpoints. **We will not do that.** Steadfast publishes an official `fraud_check` endpoint; we use that plus our own order history, and accept thinner signal on a genuinely first-time customer as the price of not holding credentials we have no right to.

---

## 3. What We Will Build

**Storefront** — age-in-months plus skill, brand, gender and price faceting, combinable and URL-addressable · typo-tolerant Bangla ↔ Banglish ↔ English search · accurate per-variant stock · **the delivery charge on the product page, before the cart**, from the same code path as checkout so the two can never disagree · a one-page, phone-first, COD-default checkout with district→upazila→area and a landmark field · moderated reviews with verified-purchase proof and `AggregateRating` · systematic safety and certification information · order tracking by number and phone · a named courier with a real shipment timeline · flash sales with a countdown · complete structured data including `FAQPage` · automatic 301s from slug history · server-side conversion tracking with a diagnostics screen · a blog that actually has posts.

**Admin** — catalog with a variant matrix and bulk import · an append-only stock ledger · suppliers and purchase orders with **landed cost** · an order board with bulk processing and post-placement editing · Pathao and Steadfast one-click and bulk booking behind one abstraction · per-provider location mapping · failed-booking retry with a circuit breaker · partial-delivery handling · phone-keyed risk screening with a **visible score breakdown**, and official courier data shown as a **separate, timestamped panel** rather than blended into one number · COD settlement reconciliation · and a P&L that keeps **revenue, cash collected and receivables separate** and nets off courier charges, COD fees, return charges, gateway fees, packaging, discounts, refunds and write-offs.

**The three decisions worth knowing about**

| Decision | Choice | Reasoning |
|---|---|---|
| Storefront rendering | **Next.js on a Laravel API**, with ISR and cache tags | A product page becomes static HTML served from a CDN edge — no PHP, no query, no origin hop on a cache hit. Every competitor is slow; Core Web Vitals is the one ranking factor fully under our control. **Honest cost: three apps and two languages.** Re-evaluated at the Phase 3 gate, where the fallback is a Laravel Blade storefront with everything else unchanged. |
| Payment gateway | **SSLCommerz**, COD primary | The only one of five with complete public docs, a vendor-maintained Laravel library, published pricing, *and* the strongest verification story (signature **plus** a server-to-server validation API **plus** published source IPs). One integration covers cards and every major wallet. |
| Default courier | **Steadfast** primary, Pathao secondary | Cheaper outside Dhaka (৳155 vs ৳170 at 2 kg), the only openly accessible API docs, the only official fraud endpoint, and `/payments` endpoints that make COD reconciliation automatable. |

---

## 4. Plan & Cost

| Scope | Dev-days | Calendar |
|---|---|---|
| **MVP — launchable and revenue-generating** | **≈164** | **≈16–18 weeks** |
| Full scope as briefed | ≈256 | ≈25–29 weeks |

**Revised 2026-11-08** for three owner decisions: Bangla UI moved into MVP (+16 dev-days, +3 designer-days) · development proceeds without courier merchant accounts · the design is being built into the empty Figma file now rather than reviewed.

Assumes one senior full-stack developer, one mid-level frontend developer, and a half-time designer, with 15% contingency already included. **Every operational module the business cannot run without is inside the MVP** — orders, inventory, courier booking, risk screening, real P&L. What is deferred is breadth, not capability: gift mechanics, bundles, campaigns, multi-warehouse, Bangla *admin* UI, deeper analytics.

Ten phases with five decision gates are in `DEVELOPMENT-ROADMAP.md`; task-level detail for the first two build phases is in `IMPLEMENTATION-PLAN.md`.

**Start in week one, regardless of everything else** — payment-gateway merchant onboarding (needs a trade licence, TIN and bank account; **the most common cause of launch slippage**), Pathao and Steadfast merchant accounts, SMS sender-ID approval, WhatsApp Business verification, and product photography, which is the owner's largest non-development workload and is routinely underestimated.

---

## 5. Principal Risks

| Risk | Mitigation |
|---|---|
| **Figma file is empty — Phase 2 blocked** | Resolve at Gate 1; three options in `UI-UX-DESIGN-SPEC.md` §1 |
| Payment onboarding delays launch | Start week 1; build COD-complete so the store can launch COD-only if forced |
| Three-app complexity exceeds team capacity | Honest reassessment at Gate 3; Laravel Blade fallback costs ~2 weeks there vs ~6 weeks later |
| Courier APIs behave differently from their documentation | Both providers' docs are login-gated or JS-only. **Phase 6 task one is capturing real responses with live credentials as test fixtures.** Until then every driver detail is provisional. Gateway abstraction, raw payload retention, reconciliation polling and manual-entry fallback absorb the rest |
| COD return losses exceed margin | **No reliable national return-rate figure exists** — none of the primary sources publish one. Planning range 15–30%, held as a tunable parameter, replaced by our own data within 60 days. Gating COD on Steadfast's `fraud_check` substitutes real per-customer evidence for an average nobody can supply |
| Profit figures disputed, destroying trust in the system | Reconcile ten real orders by hand against a spreadsheet at the Phase 5 gate before declaring it done |
| Product content and photography not ready | Standard defined in Phase 2; shooting starts in Phase 4; minimum launch catalog agreed up front |

Full register, with likelihood and impact, in `DEVELOPMENT-ROADMAP.md` §6 and `SYSTEM-ARCHITECTURE.md` §14.

---

## 6. What We Need From You

### Decisions taken on 2026-11-08

**1. Bangla UI ships at launch.** This reverses the earlier recommendation to defer it, and it is defensible: no audited competitor has `hreflang` or a true Bangla locale, and Daraz's Bangla is visibly machine-translated — `Interest` renders as `সুদ` (financial interest) and its navigation shows `ছাগল` (goat). Hand-written Bangla against that is a real differentiator. English at the root, Bangla at `/bn/`, with Western numerals in both locales so a price never costs the reader a hesitation. **The development cost is ≈16 dev-days; the larger half of the work is content and it is yours** — every product name, description, category label and policy page needs a human Bangla version before launch. Machine translation is explicitly out, since beating it is the point. This is now the critical-path content dependency, ahead of photography.

**2. No courier merchant accounts — develop on sandbox.** Only half possible, and the half that fails matters. **Pathao has a real sandbox. Steadfast has none** — the `sandbox.api.steadfast.io` host that appears in third-party documentation is fabricated and does not resolve, and its API key is issued only on merchant-account creation. So the Pathao driver is verified against the real sandbox, while the Steadfast driver is built against fixtures from its published Postman collection, ships flagged unverified, and is blocked from booking a real parcel until a two-day live-verification task runs. The gateway abstraction makes launching Pathao-only and enabling Steadfast later a configuration change, not a rewrite — this is precisely the cost it was designed to avoid.
   **The real loss is to risk screening.** Steadfast's `fraud_check` is the only officially published courier-history endpoint in this market and it needs an account. Without it the risk score runs on our own order history alone — fine for a repeat customer, but **no external signal at all on a genuinely first-time caller, which is the case the feature exists to catch.** The compensating control in MVP: any first order from an unknown phone above a value threshold goes to a confirmation call regardless of score. **Recommendation: open both accounts before launch rather than after.** They are ordinarily free, and no amount of code substitutes for the data.

**3. The design is being built, not reviewed.** The Figma file was re-checked under the new account and is genuinely empty — `Document`, one page, zero nodes, zero variables, zero styles. Not a permissions artefact. Construction is under way; see §6a.

### Still blocking

**⚠️ Toys came under mandatory BSTI certification in 2026** — R.O. 232/233-Act/2026, gazetted 23 June 2026, effective 23 August 2026, covering BDS ISO 8124 safety standards. This post-dates every compliance guide currently online, which is why it is easy to miss. Whether an importer needs a full CM licence or consignment-wise port clearance **could not be resolved from public sources** and changes import cost and lead time. **Confirm with BSTI and a C&F agent before committing to an import plan.** This sits outside the software project but is the highest-impact unknown the research surfaced — and it also creates an opportunity, since no competitor presents safety certification systematically.

### Decisions still at Gate 1
Approve or amend the MVP boundary · confirm the team and their availability (every estimate depends on it) · confirm the payment gateway and **start onboarding this week** · **say who will write the Bangla content, and when**.

### 6a. Design build — in progress

The Figma file now has its 8-page structure. Five agents are writing detailed specifications in parallel (`docs/_design/`) covering foundations, 31 primitive components, storefront composites and screens, admin composites and screens, and the full bilingual string set with notification templates and segment counts. All Figma construction runs sequentially from those specs, because the design-system tooling requires it — parallel writes to one file risk corrupting it.

This pass delivers foundations, the component library, and the six screens that establish the direction: Home at mobile and desktop, product detail, checkout, admin dashboard, admin order board. The remaining screens in the §5 inventory follow once you approve the look — building a hundred screens before the direction is agreed would guarantee rework.

One constraint already surfaced from the live file: **Anek Bangla, the Bangla face, has no ExtraBold weight.** Bangla display type therefore caps at Bold (700) while Latin uses ExtraBold (800), so the type scale specifies weight per script rather than one weight for both.

### Questions still open
Expected order volume at launch and at 12 months · does the business hold its own stock with supplier invoices, or dropship · who operates the admin daily and how many need separate restricted logins · is there existing catalog or order history to migrate (**not currently in any estimate**) · do a trade licence, TIN/BIN and bank account exist · who shoots product photography · logo and existing brand colours.

A few items need professional confirmation rather than our reading: the VAT registration threshold (sources conflict between Tk 50 lakh and Tk 3 crore), the Digital Commerce refund deadline and advance-payment trigger (sources conflict; the gazette PDF is in legacy encoding and machine-unreadable), and Steadfast's return-charge policy — **its own Terms and Business pages contradict each other**, and at 15–30% return rates that single line can outweigh the forward tariff.

---

## 7. Documents

| Document | Contents |
|---|---|
| `COMPETITOR-ANALYSIS.md` | Site-by-site findings with quoted evidence, the requested comparison matrix, AppByte benchmark |
| `FEATURE-MATRIX.md` | 10-property feature comparison, gap analysis ranked by impact ÷ effort, AppByte-vs-us table |
| `PRODUCT-REQUIREMENTS.md` | Requirements with IDs and MVP/P2 priority, BD decisions, deliberate trade-offs, open questions |
| `SYSTEM-ARCHITECTURE.md` | Topology, the rendering decision argued in full, verified courier asymmetries, security, testing, risks |
| `DATABASE-DESIGN.md` | Schema, transaction boundaries, costing and profit logic, indexing, correctness invariants |
| `UI-UX-DESIGN-SPEC.md` | The Figma finding, design tokens, components, 100 screens, performance and accessibility budgets |
| `DEVELOPMENT-ROADMAP.md` | Ten phases with objectives, tasks, dependencies, estimates, gates, risk register, priorities |
| `IMPLEMENTATION-PLAN.md` | Task-level breakdown for Phases 3–4, repository layout, first-week plan |
| `_research/` | Raw research with every source URL, including the courier and payment API references |

---

## 8. Recommendation

**Approve the MVP scope and begin Phase 3.** The research supports the business case: this market's weakness is not its size but its execution, and the two things that would win it — a correct, fast storefront and an honest back office — are both buildable in about four months.

Two caveats stated plainly. First, the three-application architecture is the right long-term choice but a real burden for a small team; the Gate 3 checkpoint exists so that decision can be reversed cheaply rather than regretted expensively. Second, clear the Figma and BSTI blockers before Phase 2 — the first will stall design, and the second could change the economics of the business the platform is being built for.
