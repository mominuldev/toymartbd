# CLAUDE.md — Toy Mart BD

Guidance for Claude Code when working in this repository.

## What this is

**Toy Mart BD** — a kids' toy and educational-products e-commerce platform for the Bangladesh market. Two interconnected applications over one Laravel API:

1. **Storefront** — customer-facing shop, SEO- and speed-critical
2. **Admin** — order, inventory, courier, risk and finance management for the business owner and staff

The business value is split roughly evenly between winning organic search traffic and giving the owner accurate operational and financial truth in a COD-dominated market.

## Current state

**Phase 3 — Technical Foundation, started 2026-11-11.** Gate 1 (roadmap) and Gate 2 (design sign-off) are cleared. The Figma design is complete and approved; the admin uses **Theme B** (slate & indigo, dark sidebar). Design tokens are exported to `packages/tokens` and wired into the admin's Tailwind. The foundation is built and tested end to end (auth, roles, i18n, API contract, observability, backups), with brands as the first full CRUD through admin, API and storefront. **Phase 4 — Core E-commerce, started 2026-11-11:** catalog schema, product/variant API, categories and attributes admin, and the storefront layout are in. Phase 3 items still open need owner infrastructure (staging, backup bucket). Follow `docs/IMPLEMENTATION-PLAN.md` §2 progress and Phase 4.

## Read before working

| Need | Document |
|---|---|
| Why features were chosen | `docs/COMPETITOR-ANALYSIS.md`, `docs/FEATURE-MATRIX.md` |
| What to build and for whom | `docs/PRODUCT-REQUIREMENTS.md` |
| How the system fits together | `docs/SYSTEM-ARCHITECTURE.md` |
| Schema, transactions, invariants | `docs/DATABASE-DESIGN.md` |
| Tokens, components, screens | `docs/UI-UX-DESIGN-SPEC.md` (§9 maps Figma components to React files) · `packages/tokens/README.md` |
| Admin screen checklist + Figma node ids | `docs/_design/06-admin-feature-checklist.md` |
| Storefront rebrand — **Smart Junior** (Figma `2b6PVOOapPbcaKYIdHWZup`, source of truth for the storefront since 2026-11-23) | `docs/_design/07-smart-junior-storefront.md` |
| Phases, estimates, gates | `docs/DEVELOPMENT-ROADMAP.md` |
| Task-level breakdown | `docs/IMPLEMENTATION-PLAN.md` |
| Market, payments, couriers, legal | `docs/_research/` |

## Stack

**API** Laravel 13 · PHP 8.4 · MySQL 8.4 · Redis · Horizon · Sanctum · Meilisearch
**Admin** React 19 · TypeScript strict · Vite · Tailwind v4 · TanStack Query + Table · React Hook Form + Zod · Recharts
**Storefront** Next.js 16 App Router · TypeScript · Tailwind v4 · ISR with cache tags
**Decision pending at the Phase 3 gate:** if the team lacks TypeScript capacity, the storefront switches to Laravel Blade + Livewire. See `SYSTEM-ARCHITECTURE.md` §3.

## Non-negotiable rules

These encode decisions already argued in the docs. Changing one requires updating the doc that justifies it, in the same PR.

### Architecture
1. **All business logic lives in Laravel.** The storefront and admin are view layers. They never compute a price, discount, delivery charge, stock decision or profit figure. If a client calculates money, that is a bug.
2. **Domain boundaries are enforced** by `deptrac`. `Domain/` must never import from `Http/`. Cross-domain writes go through events, never direct model access.
3. **Transaction boundaries live in Actions**, one use case per Action. `DB::transaction` appears nowhere else.
4. **No external HTTP call inside a database transaction.** Ever. Courier and gateway calls happen in queued jobs, after commit.
5. **Add a courier or gateway by writing a driver**, never by touching order code.

### Data
6. **Money is `DECIMAL(14,2)`** with the `Money` cast. Never float, never a bare int.
7. **`order_items.cost_price_snapshot` is written once at order creation and never recalculated.** The entire finance module depends on this.
8. **`stock_movements` and `financial_transactions` are append-only.** No updates, no deletes. Current state is a rebuildable projection.
9. **Stock changes require `SELECT … FOR UPDATE`, always ordered by ascending `variant_id`** to avoid deadlock.
10. **Revenue and cash are separate.** Revenue is recognised on delivery; cash on courier remittance. Never conflate them. `is_recognised_revenue` and `is_cash_movement` on the ledger carry this.
11. **Daily reports bucket by Dhaka date, not UTC date.**
12. **Orders are never deleted or soft-deleted**, only status-changed.

### API
13. `/api/v1/...` — additive changes only once production ships. Breaking changes go to `v2`.
14. Every write endpoint validates through a `FormRequest`. No inline validation in controllers.
15. Responses go through API Resources. Never return an Eloquent model directly.
16. Filters and sorts use an explicit allowlist. An unlisted filter is a 400.
17. `POST /checkout`, shipment booking and payment capture require an `Idempotency-Key`.
18. Errors follow RFC 9457 with a stable machine-readable `type`.

### Security
19. **Verify a webhook signature before parsing or writing anything.** Then re-query the provider for the authoritative amount and status. Never trust a webhook body about money.
20. Gateway credentials use the `encrypted` cast. Never log a credential, a full phone number, or a full address.
20a. **Two-factor sign-in is the owner's switch, off by default** (owner decision 2026-11-14, made a setting 2026-11-16): Staff & roles › Two-factor sign-in (`security.two_factor_required`, `PUT /staff/two-factor`, `staff.manage`, audited). When on, owners and anyone holding a `Permission::revealsMoney()` permission must enrol before using the admin; when off it is optional, and anyone enrolled still gives a code at sign-in. Authenticator-app TOTP with single-use recovery codes — never SMS. The `TWO_FACTOR_ENFORCE` config is only a kill switch over the setting: forced on in production (`ProductionSafety`), switched off by the test suites.
20b. **Roles are editable in the admin** (owner decision, 2026-11-14). At runtime `role_has_permissions` is authoritative; `Role::permissions()` is each role's default, which the seeder applies only to roles and permissions it creates. The owner role and the owner-only permissions (`staff.manage`, `roles.manage`) are never editable. Every change is audited.
21. Every model has a Policy. Every admin endpoint has a role test asserting 403 for unauthorised roles.
22. Order tracking requires order number **plus** phone digits — never order number alone.
23. **Risk scoring never auto-cancels an order.** It flags for human verification. A false positive loses a real customer.
24. Our computed risk score and verified courier data are stored, and displayed, separately and timestamped. Never merge them into one number.
24a. **Never store a courier portal email/password to scrape undocumented endpoints**, however standard that is in this market. Only officially published provider APIs. See `SYSTEM-ARCHITECTURE.md` §8.
24b. Auto-**hold** a high-risk order for human verification. Never auto-**cancel** one.
24c. Treat a Pathao webhook as a trigger to re-query, not as authoritative — its signature is a plain shared secret, not an HMAC.
24d. Bulk courier booking returns no consignment ids on either provider. Show "submitted" until a webhook or poll confirms.

### Frontend
25. **Two frontends, two homes** (owner decisions 2026-11-08 and 2026-11-17 — the storefront moved to its own repository):
    - **Admin** → `resources/js/admin/`, inside Laravel, built by Laravel's Vite, served from the `/admin/{any?}` catch-all via `resources/views/admin.blade.php`. It consumes `/api/v1/admin/*` over a Sanctum **cookie** session, so every write carries the CSRF token and no long-lived credential sits in JavaScript.
    - **Storefront** → its own repository, github.com/mominuldev/toymartbd-storefront (checked out as the sibling folder `../toymartbd-storefront`), a separate Next.js app with its own CI and Vercel deploy. Never part of the Vite bundle. Its rules are in that repo's CLAUDE.md.
    - **Shared** → `packages/tokens`, `packages/api-types` and `packages/observability` here (npm workspaces root: the root `package.json`). The storefront gets **copies**: after `npm run tokens:build` or `npm run api-types:generate`, run `npm run sync:storefront` (writes `../toymartbd-storefront/shared/`, plus `config/payments.php` and `StaticPagesSeeder.php` for its contract tests) and commit the result in the storefront repo. `npm run sync:storefront -- --check` reports stale copies.
    - **Deploy order:** a storefront change that needs a new API endpoint deploys only after that API release is live — the two pipelines are separate now.
    - `resources/views/` holds the admin shell plus invoice PDF and mail templates. There is no Blade storefront.
25a. Server data lives in TanStack Query only. Zustand holds no server data.
25b. **Recommendations: nothing on checkout.** No upsell, no cross-sell, no "customers also bought". Checkout completes one task. Caps elsewhere are 2 upsell + 4 cross-sell + 1 frequently-bought-together on a product page, and one cross-sell rail in the cart — the cart carries no upsell, because a buyer there has already decided.
25c. Recommendation guardrails live in the **resolver**, server-side: filter out-of-stock, archived and unpublished targets; never recommend the product itself; reject an `upsell` whose target is not more expensive; enforce the caps on read. A block with sold-out items hides itself rather than degrading.
25d. Every recommendation impression, click and add-to-cart is logged to `recommendation_events`, and the winning placement is stamped onto `order_items.recommendation_source`. Attribution must be a measurement, not a belief — the P&L reads it through `order_items`.
26. Only semantic design tokens in components. A hardcoded hex or px spacing value is a defect.
27. Zod schemas for admin forms are generated from OpenAPI, never hand-written twice.
28. The storefront reaches the API from Server Components and Route Handlers only. No API credential ever reaches the browser.
29. Performance and accessibility budgets in `UI-UX-DESIGN-SPEC.md` §7–8 are CI gates, not targets.

### Bangladesh specifics
30. Phone numbers: validate with `/^(?:\+?880|0)1[3-9]\d{8}$/` — **prefixes 013–019 only**. Reject 010/011/012 (011 = defunct Citycell) and the 06x IoT series. Store E.164. Do **not** rely on libphonenumber alone; it is more permissive and accepts 011. **Never infer the operator from the prefix** — number portability has existed since 2018.
31. Addresses are division → district → upazila → area + free-text line + landmark. The landmark field materially reduces failed deliveries; never drop it.
32. Courier zone ids are the provider's own namespace and do not match district/upazila data. Use `courier_locations`. **Never guess a zone** — block the booking and surface it for mapping. Pathao has ~64 cities and ~1,258 zones against 495 upazilas; **64 ≈ 64 invites a fatal naive join** that breaks on spellings like Cumilla/Comilla. Steadfast needs no zone ids at all.
32a. **Steadfast's weight step is at 1 kg, not 2 kg.** Never copy Pathao's "+per kg over 2 kg" rule onto it. Boxed toys sit squarely in the 1–3 kg band where the two tariffs diverge.
32b. Never put OTP on WhatsApp: Bangladesh is on Meta's *authentication-international* tier at roughly 26× the domestic authentication rate. Utility messages are cheap (≈৳0.45) and are the right channel for order updates.
33. COD is a first-class payment driver, not a special case.
33a. **No Pathao or Steadfast merchant account exists yet (as of 2026-11-08).** Pathao has a real sandbox; **Steadfast has none** — develop its driver against hand-authored fixtures from its published Postman collection, keep `courier_providers.verified = false`, and never allow a production booking through an unverified provider. `NullGateway` plus fixtures is how the fulfilment flow is tested in CI.
33b. Steadfast's fraud check (`/fraud_check/score/{phone}` — the old `/fraud_check/{phone}` returns all zeros since 2026-09-27) needs a merchant account and runs once its keys are saved (`verified` gates booking only), so at launch the risk score uses **our order history only**. The courier-data panel renders an explicit "provider not connected" state, never a zero. Any first order from an unknown phone above the value threshold goes to the verification queue regardless of score.
33c. **POS Due = the unpaid part of the order** (owner decision, 2026-11-15). A `due` tender writes no payment row and no cash — never book due as cash or as `cod_received`. The POS order is still delivered (revenue recognised on delivery as usual); its `due_total` stays > 0 and `SyncOrderPaymentStatus` derives the payment status. Selling on due needs `pos.sell_on_due`, a known customer (valid phone and a name — never the walk-in row) and stays within the per-customer due limit (Settings › Store › Point of sale, `PUT /settings/pos`), checked under the customer's row lock. Due is collected later at the POS as real payments, FIFO over the customer's oldest POS orders, booked like sale tenders (`pos_receipt`). See `DATABASE-DESIGN.md` §11.
33d. **POS manual discount** (owner decision, 2026-11-15): any POS user may take a percent or a flat taka amount off a sale, **at most 10%** of the goods after promotions and the coupon (`checkout.pos_discount_max_percent`, `PosDiscountSettings`; editable at Settings › Store › Point of sale with `settings.update`, `PUT /settings/pos`), with a required reason from a fixed list (`DiscountReason` enum, stored as its code; each screen shows its own language's label). The server prices it (`PriceBreakdown::withManualDiscount`), spreads it over the order items, stores it as `orders.manual_discount` (inside `discount_total`, so the ledger books it as `discount_given`) and keeps who/what/why on `pos_sales`. Over the cap is a `discount` blocker, never a silent clamp.
34a. **Every static page is built from sections, like the homepage** (owner decision, 2026-11-16). One block system, one layout per page (`LayoutKey` `page:{id}`), with its own draft, publish, versions and preview. The Home page is listed first in the Pages list. `cms_pages.content*`/`faq_items` are legacy — never add features on them.
35. **Bangla UI ships at launch** (owner decision, 2026-11-08). **Bangla at the root (`/`), English at `/en/`** (owner decision 2026-11-25 — Bangla first; it was English at `/`, Bangla at `/bn/`, and the storefront 308s old `/bn/…` URLs). `hreflang` both ways plus `x-default`, which points at the Bangla (unprefixed) URL. Every public storefront URL the API builds goes through `App\Support\StorefrontUrl` — never hand-write a locale prefix. Every user-facing string goes through the i18n layer — a hardcoded English string in a component is a defect, the same as a hardcoded hex.
36. **Numerals are Western (0–9) in both locales** — prices, phone numbers, quantities, order numbers, dates. Bengali numerals are for editorial copy only.
37. **Slugs are ASCII-transliterated and shared across locales.** One product, one slug, served under both prefixes. Bangla-script slugs percent-encode into unreadable strings when shared on Messenger or WhatsApp.
38. Bangla text needs ~1.15× the line-height of Latin at the same size; apply it via the `.lang-bn` class, never a single global line-height for both scripts.
39. Order updates go over WhatsApp utility messages (≈৳0.45) rather than Bangla SMS, which costs 2–3× more because Bangla consumes 70 characters per segment against 160 for Latin. OTP stays on SMS (see rule 32b).
34. `utf8mb4_0900_ai_ci` throughout. Bangla and English fixtures in every seed and E2E path.

## Conventions

**PHP** PSR-12 via Pint · typed properties and return types everywhere · constructor property promotion · enums for every status · named route groups per domain · `php artisan test` must pass before any commit.
**TypeScript** strict, no `any` (`unknown` plus narrowing instead) · named exports · feature-first folders · no cross-feature internal imports.
**Migrations** one concern per file; expand-and-contract for every change to a populated table; never an `ALTER` that locks `orders` during business hours.
**Tests** Pest for PHP, Vitest for the admin, Playwright for E2E. A bug fix ships with the test that would have caught it.
**Commits** Conventional Commits with the phase: `feat(orders): add bulk courier booking [P6]`.
**Branches** `phase-N/feature-name`. `main` stays deployable at all times.

## Working practice

- Read the relevant `docs/` section before implementing. The reasoning is there; re-deriving it wastes effort and tends to reach a worse answer.
- If a doc is wrong or has been superseded, update the doc in the same PR as the code. Stale docs are worse than none.
- Never mark a task complete without running its tests and saying what passed.
- The two correctness invariants are asserted on every build: stock movements must sum to stock levels, and order financials must equal the ledger. If either fails, stop and fix it — it means a code path bypassed a transaction.
- When research is incomplete, say so explicitly. Do not fill a gap with a plausible guess, especially about competitor features, courier API behaviour, payment-gateway terms, or Bangladesh regulation.

## Commands

**Laravel 13 lives at the repo root, and so does the npm workspaces root.** Run everything from the repo root. The admin SPA is inside Laravel at `resources/js/admin/`; the Next.js storefront is its own repository (`../toymartbd-storefront`, commands in its README).

```bash
# API — from the repo root
php artisan serve
php artisan queue:work --queue=critical,courier,notify,index,reports
php artisan horizon
php artisan test
./vendor/bin/pint && ./vendor/bin/phpstan analyse && ./vendor/bin/deptrac

# Frontend — from the repo root (one workspaces root covers everything)
npm install
npm run dev                     # Laravel Vite dev server for the admin SPA
npm run build                   # build the admin bundle
npm run typecheck
npm run tokens:build            # rebuild the Tailwind preset from the design tokens
npm run api-types:generate      # regenerate types from the API's OpenAPI document

# The admin is served by Laravel, so it needs BOTH running:
#   php artisan serve   → http://localhost:8000/admin
#   npm run dev         → Vite HMR for the admin bundle

# E2E and UI checks — from the repo root, always through the Playwright CLI.
# Locally Playwright drives the Google Chrome installed on this machine (channel 'chrome' in
# every playwright.config.ts) — never a separately downloaded Chromium, never another browser
# tool. CI alone uses `npx playwright install chromium`. Screenshots for a Figma check: run a
# spec with SHOTS_DIR=<dir> (e2e/admin/staff.spec.ts shows the pattern).
npm run build && npm run test:e2e:admin   # real Laravel app on a throwaway SQLite DB
# Storefront e2e runs in the storefront repo (npm run test:e2e there).

# Contract — after changing a FormRequest or Resource
composer openapi:export                   # docs/api/openapi.json (a test fails when stale)
npm run api-types:generate                # packages/api-types: TS types + Zod schemas
npm run sync:storefront                   # then copy them to the storefront repo and commit there

# Ops
php artisan toymart:backup && php artisan toymart:backup:restore-rehearsal
php artisan toymart:storefront-token      # bearer token for the Next.js server
```

## Figma build rules

These are mechanical and non-obvious; each one cost a rework pass.

1. **Clear the default fill on every structural container.** `figma.createFrame()` and `figma.createAutoLayout()` both default to an **opaque white fill**. A layout container that keeps it shows as a white block on the canvas and, worse, silently breaks dark mode — a raw white fill does not follow the Color collection. Set `fills = []` on every header, row, strip, grid and section wrapper. Only these carry a background: the page root (`color/bg/canvas`), cards and panels (`color/bg/surface`), and the specimen swatches themselves.
2. **No raw colour, anywhere, including strokes.** A hairline on a swatch is still a colour. Bind it to `color/border/default`. A hardcoded hex is a defect; it means a token is missing.
3. **Nothing overlaps.** Top-level frames on a page must not intersect. Lay pages out on a single horizontal or vertical run with a clear gap, and audit it — compute bounding boxes and assert no intersection rather than trusting the eye.
4. **Mutations are strictly sequential.** Never run two `use_figma` calls at once; parallel writes can corrupt the file. Parallelise specification work in `docs/_design/`, not Figma writes.
5. **Font loads do not persist across calls.** Reload every face in every script that writes text, or the write fails with "unloaded font".
6. **Figma rejects `.` in a variable name.** The spacing half-step is `space/0-5`; its CSS custom property stays `--space-0-5`.
7. **`lineHeight` cannot be variable-bound.** A bound FLOAT is read as **pixels**, so a 160 meant as a percentage renders as a 160px line-height. Set `lineHeight` explicitly as a PERCENT. `fontSize`, `letterSpacing` and `fontFamily` bind correctly.
8. **Elevation and motion cannot be variables.** Elevation is effect styles (`Elevation/Light`, `Elevation/Dark`, `Elevation/Neutral` (Admin Light), `Elevation/Admin Dark` — each `e1`–`e4` — and `Focus/ring`); motion is documentation plus the code token file.
9. **Screenshot a section, not the whole page.** A 9,000px page renders to an unreadable thumbnail and proves nothing.
10. **`resize()` resets BOTH sizing modes to FIXED.** Resize first, then set `primaryAxisSizingMode`/`counterAxisSizingMode`. Getting this backwards locked every Button to its min-width and clipped the label — which would have truncated every Bangla string in the system.
11. **A component property has one default across the whole set and overrides per-variant content.** Where content differs per variant (Badge labels, Badge icons), keep it per-variant and do NOT bind a property. A nested instance stays swappable without one.
12. **A property-bound `visible` cannot be toggled on an instance** — use `instance.setProperties({...})`. `componentPropertyReferences` cannot be set to null; delete the property to unbind.
13. **`addComponentProperty` with `INSTANCE_SWAP` takes the component node id, not its key.**
14. **An instance child's transform cannot be overridden.** Render a "pressed" state by resizing a child, not moving it.
15. **Icons are Phosphor (filled paths), not Lucide (strokes).** Colour them with FILLS. Fetch path data from `@phosphor-icons/core`; never hand-write it. Component children need `constraints: SCALE` or instances crop instead of scaling.
16. **Tokens leave Figma only through the export.** After any variable, effect-style or admin text-style change, re-export into `packages/tokens/src/figma-variables.json`, run `npm run tokens:build`, and commit the diff. Never edit `packages/tokens/dist/` by hand. Add a new colour mode to `THEMES` in `scripts/build.mjs`.
17. **Never clip a structural container.** `clipsContent = true` on a row, section, card wrapper or header cuts off elevation shadows and overhanging badges (the cart count). Clip only image crops, a pattern layer inside its band, and the screen frame itself; draw focus rings as a stroke or ring layer rather than relying on clipping. Screenshot headers, cards and badges zoomed in to prove nothing is cut.

## Known gaps

The owner has not yet answered the open questions in `SYSTEM-ARCHITECTURE.md` §15 and `PRODUCT-REQUIREMENTS.md`, nor Gate 1 decisions 3, 5 and 7 (`DEVELOPMENT-ROADMAP.md` §8: team, payment gateway, stock model). Designs still carry placeholder business values (delivery charges, hotline, licence/BIN, merchant numbers, loyalty rates) — `UI-UX-DESIGN-SPEC.md` §10. None of these block Phase 3.

**Local toolchain:** on the lead's machine Homebrew `node` fails to start (missing `libsimdjson.33.dylib`; fix with `brew reinstall simdjson node`). Until fixed, npm, Vite and tsc cannot run, and the token build was run under macOS JavaScriptCore. Say so rather than claiming a frontend build passed.
