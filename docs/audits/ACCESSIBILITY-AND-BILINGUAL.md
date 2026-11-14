# Accessibility and bilingual regression audit (Phase 9, batch 1)

**Date:** 2026-11-14 · **Scope:** storefront (every MVP template, EN + BN, 390 + 1440), admin (main screens, EN + BN, Admin Light + Admin Dark), keyboard paths, the Bangla layout and copy sweep (roadmap Phase 9, UI-UX spec §6.6 and §7, CLAUDE.md rules 26 and 35–39), and notification templates.

**Method**
- **axe-core 4.14** through `@axe-core/playwright` 4.14.0. This is a new root **dev dependency**. Every rule tagged WCAG 2.0, 2.1 or 2.2 at level A or AA runs, and a violation of any impact fails. Best-practice rules also run, but their findings are only attached to the report.
- **Storefront:** `toymartbd-app/storefront/e2e/accessibility.spec.ts` uses the existing mock-API E2E setup with JavaScript on, so it audits the hydrated page.
  - It covers 31 screens per locale and width: 16 catalogue, content and status pages, 8 account pages (signed in through the real OTP flow), and the cart → checkout blank / with errors / filled → confirmation → tracking flow.
  - That makes 124 page audits.
- **Keyboard:** `e2e/keyboard.spec.ts` (storefront) and `e2e/admin-a11y/keyboard.spec.ts` (admin).
- **Admin:** `e2e/admin-a11y/axe.spec.ts` runs against the real Laravel app with the full demo data set, through its own `serve.sh` on port 8344 (not the admin E2E suite on 8124).
  - It covers 25 screens × EN/BN × light/dark, plus the 390 shell, for 102 page audits.
  - Run it with `npm run build && npm run test:a11y:admin`.
- **Bilingual layout:** the existing `e2e/bangla-layout.spec.ts` (P4-I18N-02: overflow, clipping, page scroll and rule-38 line-height for every screen at 390 and 1440) was re-run. On top of it, a one-off DOM scan of every BN page looked for Bengali digits and Latin-only text.
- **Templates:** every `TemplateCatalog` body was rendered with its sample values and run through `SmsSegments`.

Severity follows the brief. **High** means it blocks a user or a whole template. **Medium** means a WCAG A/AA failure or a broken keyboard path on one component. **Low** means hygiene.

## Findings

| # | Sev | Where | Finding | Fix | Test that proves it | Status |
|---|---|---|---|---|---|---|
| A-1 | **High** | Storefront `/delivery-charge` (EN + BN, with or without a quote query) | **The page returned HTTP 500 in every production build.** It reads `searchParams` but was an ISR route (the layout's empty `generateStaticParams`), so Next aborted with `DYNAMIC_SERVER_USAGE`. `next dev` renders it, which hid the bug. The Bangla layout spec "passed" it because it audited the error page. axe caught it through a missing `<title>`. | `export const dynamic = 'force-dynamic'`, the same fix `/search` already carries for the same reason. | `accessibility.spec.ts` (`delivery-charge` in both locales and widths: a real `<title>` and landmarks). Manual check: 200 on `/delivery-charge`, `/bn/delivery-charge` and `?district_id=13`. | **Fixed** |
| A-2 | **High** | Storefront `/blog`, `/guides` | **Same 500 (`DYNAMIC_SERVER_USAGE`) from reading `?page=`.** | Same fix. The API reads stay cached under `content:blog`. | Production build: the digest went from `DYNAMIC_SERVER_USAGE` to the mock's `404 /blog/posts`. The mock API serves no blog endpoints, so these two pages are not in the axe list (see O-1). | **Fixed** (verified against the build, not by axe) |
| A-3 | Medium | Storefront `ProductCard` (every listing, rail and search) | The photo link (`tabindex=-1`, a duplicate of the title link) had no accessible name: axe `link-name`, WCAG 2.4.4 / 4.1.2. There were 36 hits per sweep. | `aria-hidden="true"` on the duplicate link, the pattern `BlogCard` and `CartLineItem` already use. | `accessibility.spec.ts` | **Fixed** |
| A-4 | Medium | Storefront header logo (also checkout and maintenance) | The link was named "Toy Mart BD — home" while its visible text reads "ToyMartBD". That fails WCAG 2.5.3 label-in-name: a speech-control user saying what they see does not match. Bangla named it in Bangla script under a Latin logotype. | The wordmark gets a real space ("ToyMart BD", invisible in the flex layout), and `nav.logoAria` becomes "ToyMart BD — home" / "ToyMart BD — হোম". | `accessibility.spec.ts`; `layout.spec.ts` updated | **Fixed** |
| A-5 | Medium | Storefront header language menu (`<summary>`) | Named "Change language" over visible "EN" / "বাং" (2.5.3). | `aria-label` becomes "EN — Change language" / "বাং — ভাষা বদলান". | `accessibility.spec.ts` | **Fixed** |
| A-6 | Medium | Storefront cart link: header icon and mobile tab bar | The aria-label ("Cart, 1 items" / "কার্ট, 1টি পণ্য") did not contain the tab's visible "Cart" + badge word for word (2.5.3). In Bangla, "1টি" also hid the badge's "1". | `CartLink` names itself with a visually hidden label; the visible tab label and badge are `aria-hidden` (they repeat it). `nav.cartCount` (bn) becomes "কার্টে পণ্য: {count}". | `accessibility.spec.ts` (`*-cart`); `layout.spec.ts` ("Cart, empty" still the name) | **Fixed** |
| A-6b | Medium | Storefront cart, mobile total bar (Bangla) | The "show summary" link's aria-label replaced its visible total (2.5.3). | Visible total plus a visually hidden "Show order summary". | `accessibility.spec.ts` (`bn-390-cart`) | **Fixed** |
| A-7 | Medium (critical in axe) | Storefront PDP desktop tabs | The `role="tablist"` also owned the "Reviews" jump link, which is not a tab: `aria-required-children`, 4.1.2. | The Reviews link moves beside the tablist inside the same ruled row. No visual change. | `accessibility.spec.ts` (`product` at 1440) | **Fixed** |
| A-8 | Medium | Storefront mobile filter sheet (`role=dialog aria-modal=true`) | It claimed to be modal, but focus stayed on the trigger, Tab walked out behind the overlay, and Escape did nothing. That breaks UI-UX spec §7 ("focus trapped in modals and returned on close") and 2.4.3. | New `components/layout/DisclosureKeyboard.tsx`, mounted once by `PageShell`. It applies to every `details[data-dismissible]` (filter sheet, sort menu, header language menu): Escape closes and returns focus to the summary. On an `aria-modal` panel it also moves focus in on open and traps Tab. Without JavaScript, nothing changes. | `keyboard.spec.ts`: "the filter sheet takes focus, traps Tab, closes on Escape and returns focus"; "the sort menu closes on Escape and returns focus" (EN + BN, 390) | **Fixed** |
| A-9 | Medium | Storefront PDP reviews, Bangla | Review dates rendered "12 Oct 2026" on `/bn`, which is untranslated copy (rule 35). | Bangla month names from the catalogue ("12 অক্টোবর 2026"). Digits stay Western (rule 36). English is unchanged. | DOM scan (below); `test:storefront` | **Fixed** |
| A-10 | Medium | Admin topbar account menu (every screen) | Named "Account menu for {name}" over visible "{first name} {role}" (2.5.3), on all 102 admin audits. Escape closed the menu but dropped focus on `<body>`. | Named by content: visible name and role from lg up, and a visually hidden full label below lg. Escape returns focus to the button. | `shell.test.tsx` (new: no aria-label, Escape returns focus); `admin-a11y/axe.spec.ts` | **Fixed** |
| A-11 | Medium | Admin dashboard status strip (chips + cells) | `aria-label` "{status}: {count} — open the order board" over visible "5 Pending" / "Pending 5" (2.5.3). | No aria-label. Visible text plus a visually hidden "— open the order board" (`dash.status.link` re-worded, EN + BN). | `dashboard.test.tsx` (updated); axe | **Fixed** |
| A-12 | Medium | Admin dashboard, sales and customer analytics, P&L, daily reports charts | Each chart sits in an `aria-hidden` wrapper (the data is in a visually hidden table), but Recharts 3's default `accessibilityLayer` made the SVG a tab stop: `aria-hidden-focus`, 4.1.2. Keyboard users landed on an invisible, unnamed stop. | `accessibilityLayer={false}` on all 7 charts. | axe (`dashboard`, `analytics-*`, `finance-pnl`) | **Fixed** |
| A-13 | Medium | Admin customers segment tiles | `aria-label` "VIP: 3 · LTV over ৳15,000" did not match the visible "VIP 3 …" (2.5.3). | Named by content. | `customers.test.tsx` (updated); axe | **Fixed** |
| A-14 | Medium | Admin product form, description language switch | "Editing English. Switch the description language" over visible "EN \| বাং" (2.5.3). | A visually hidden span replaces the aria-label. The admin E2E `/Switch the description language/` still matches. | axe | **Fixed** |
| A-15 | Medium | Admin homepage builder, "live version N from …" button | Named "Version history" over a different visible line (2.5.3). | Visible line plus a visually hidden "— Version history". | `home.test.tsx`; axe | **Fixed** |
| A-16 | Medium (critical in axe) | Admin order board at 390 (card list) | `aria-selected` on a plain `<li>`: `aria-allowed-attr`. | Removed. The row checkbox already carries the selection. | axe (`390-orders`) | **Fixed** |
| A-17 | Medium | Admin categories tree | Nested `<ul role="group">` made every child `<li>` an orphan list item (`listitem`, 1.3.1). | `role="group"` dropped. Nesting is already conveyed by the list structure. | axe (`categories`) | **Fixed** |
| A-18 | Low | Notification templates, Bangla SMS (`TemplateCatalog`) | Most BN SMS bodies are 2 UCS-2 segments where EN is 1. That is the known cost of rule 39, and order updates go over WhatsApp. **`review.request` (bn) is 138 units, 3 segments, 4 units over the 2-segment line.** Trimming about 4 characters (for example "এর খেলনা" → "খেলনা") saves a third of that SMS. | Not changed: template copy comes from content spec §A.17 and is the owner's call. | Table below | **Open — owner copy decision** |
| A-19 | Low | Storefront PDP specifications (API data) | Specification keys ("Material") have no Bangla field, so `/bn` shows English labels. | Needs a `key_bn` on product specifications (catalog API and admin form). | — | **Open — for the catalog area** |
| A-20 | Low | Catalogue data | Some Bangla product names carry Bengali digits ("৪৮৪ পিস"). That is allowed as editorial copy (rule 36), but quantities in names read better Western. | Content guideline for the owner. No code change. | DOM scan | Noted |

Nothing high is open.

## Coverage and results

**Storefront axe** (`accessibility.spec.ts`, 12 tests, 124 page audits):

**12/12 pass** (EN + BN × 390 + 1440). Before the fixes, every one of the 124 audits failed at least one WCAG rule.

- No colour-contrast, form-label or landmark violation in either locale. The token contrast gate (`npm run check -w @toymart/tokens`) already holds the palette, and both locales share it.
- Checkout field errors (`checkout-errors`) stay programmatically associated.

**Storefront keyboard** (`keyboard.spec.ts`, 16 tests, EN + BN):
- The first Tab reaches the skip link, which is visible on focus and moves focus to `<main>`.
- 40 consecutive tab stops on each of home, category, PDP and checkout all show the `Focus/ring` token (`--tm-focus-ring` box-shadow).
- Checkout runs end to end with the keyboard only: add to cart → View cart → Checkout → every field (EN district and upazila by type-ahead) → Place order → confirmation.
- Filter sheet and sort menu: covered by A-8.
- The mega menu's arrow, Escape and wrap contract was already in `layout.spec.ts`.

**16/16 pass.**

**Admin axe** (`admin-a11y/axe.spec.ts`, 5 tests, 102 page audits):

**5/5 pass**, together with the keyboard test: 6 passed in 6.3 min on the local machine. Before the fixes, every audit failed (A-10 alone hit every screen).

- Screens covered: dashboard, orders, order detail, new order, products, product form (edit and new), categories, brands, inventory, movements, customers, customer profile, sales and customer analytics, P&L, invoices, verification queue, invoice settings, couriers, CMS pages, banners, blog, homepage builder and message templates. Each runs in EN and BN, light and dark, plus orders and dashboard at 390.
- No colour-contrast violation in Admin Dark.

**Admin keyboard** (`admin-a11y/keyboard.spec.ts`, 1 test): sign in, then the skip link to `#content`. On a confirmed order:
- Tab to Hold, then Enter. The native `<dialog>` takes focus, and 12 Tabs never reach a page element outside it (Chromium may pass through browser UI, which is the native modal behaviour).
- Escape closes the dialog and focus returns to Hold.
- Tab to "Start processing", then Enter, moves the order on.

The mobile drawer (focus in, trap, Escape, focus return) and the account menu are in Vitest.

## Bilingual sweep

| Check | Result |
|---|---|
| Overflow, clipping, page scroll, header rows: every MVP screen, EN + BN, 390 + 1440 (`bangla-layout.spec.ts`) | **16/16 pass** after the fixes (re-run in the full suite) |
| `.lang-bn` line-height ≈ 1.15× (rule 38) | Asserted in `bangla-layout.spec.ts` (h1/h2 ratio 1.10–1.25). Email layout sets `lang` and the Bangla leading too (`mail/layouts/base.blade.php`). |
| Western numerals (rule 36) | Prices, phone numbers, quantities, order numbers and dates are Western on every BN page scanned. Bengali digits appear only in catalogue names and review text, which is customer and editorial copy (A-20). |
| Hardcoded English (rule 35) | `npm run lint:i18n` reports 0 violations, admin and storefront, with every key in EN and BN. A rendered-DOM scan of every BN page found only the review date (A-9, fixed), spec keys (A-19, data) and customer-entered names and reviews, which are shown as entered. |
| Form labels and button widths in BN | Covered by the layout spec (no wrapped or clipped label in a fixed box) and by axe `label` (no violation). |
| `hreflang` | Spot-checked with the crawler UA on 8 templates in BN and EN: `en`, `bn` and `x-default` both ways, with absolute URLs. `/delivery-charge` had none before A-1 because it was a 500. The full crawl is agent 5's (`SEO-AND-COVERAGE.md`). |

**Notification templates (BN, rendered with sample values):**

| Template | SMS EN | SMS BN | WhatsApp BN (chars) | Email BN (chars) |
|---|---|---|---|---|
| otp.login | 1 seg (79) | 1 seg (61) | — (rule 32b: never WhatsApp) | 81 |
| order.placed | 1 (119) | 2 (116) | 229 | 141 |
| order.confirmed | 1 (105) | 2 (108) | 166 | 145 |
| order.shipped | 1 (130) | 2 (120) | 135 | 133 |
| order.out_for_delivery | 1 (105) | 2 (93) | 144 | — |
| order.out_for_delivery.no_rider | 1 (86) | 1 (66) | 116 | — |
| order.delivered | 1 (101) | 2 (113) | 136 | 157 |
| order.cancelled | 1 (98) | 2 (103) | 108 | 113 |
| cart.recovery | 1 (129) | 2 (127) | — | — |
| review.request | 1 (141) | **3 (138)** | 221 | — |
| customer.birthday | 1 (132) | 2 (124) | 154 | — |

No BN template uses Bengali digits in a variable, and no BN template contains stray English beyond the `BDT` currency code and the variables.

## For other areas

- **O-1 (agent 5, storefront mock or SEO):** `e2e/mock-api.mjs` serves no `/storefront/blog/*`, so `/blog`, `/guides` and posts are 500 under the mock and cannot be axe-audited or crawled. Add fixtures, then add `['blog', '/blog'], ['guides', '/guides']` to `STATIC_SCREENS` in `accessibility.spec.ts`. Separately, `listPosts` throws on an API error, so an API outage is a 500 on `/blog` where the other pages degrade.
- **O-2 (storefront test infrastructure):** `mock-api.mjs` `variantInfo()` references `res`, which is undefined, for an unknown variant id. One such request kills the mock (`ReferenceError`) and fails every later spec with `ECONNREFUSED`. It was seen once in this run. Return a not-found marker to the caller instead.
- **O-3 (catalog):** A-19, Bangla specification keys.

## Needs the owner

1. **Screen-reader passes** (not automatable here). Checklist, in both locales:
   - **NVDA + Chrome** (Windows) and **TalkBack + Chrome** on a mid-range Android phone, storefront: home → category (filters sheet, sort) → PDP (variant, delivery estimator, tabs, reviews) → cart (quantity, coupon) → checkout (every field, the error summary after an empty submit, COD) → confirmation → track order.
     - Listen for: the page title on each step; headings in order; the cart count announced after add to cart (`role=status`); field errors read with their field; prices read as numbers in Bangla speech; Bangla text pronounced by a Bangla voice (`lang` attributes are set).
   - **VoiceOver + Safari** (iPhone): the same storefront path.
   - **NVDA**, admin: sign in → orders board (tabs, row checkbox, bulk bar) → order detail (Hold and Cancel dialogs, Start processing, toasts announced) → product form (variant matrix, image alt text, description language switch).
2. **Real devices:** a mid-range Android (for example a Samsung A-series) on throttled 4G, at 200% text size and 320 px (`UI-UX-DESIGN-SPEC.md` §7). The reflow check is automated at 390 only.
3. **A-18:** approve trimming `review.request` BN SMS copy to two segments.
4. Windows high-contrast or forced-colours mode: the focus ring keeps a transparent outline for it (`globals.css`). It needs a manual look on Windows.

## CI

- **Storefront axe and keyboard are CI gates.** `accessibility.spec.ts` and `keyboard.spec.ts` live in the storefront E2E directory, so the existing **Storefront** job step (`npm run test:e2e:storefront`) runs them. The step name in `.github/workflows/ci.yml` now says so.
  - They add about **2.2 min**: 133 s summed locally under heavy load.
  - They are green: the full storefront suite ran locally against a production build, with 166 passed. The 2 failures are other agents' in-progress specs: `performance-budgets` (agent 3) and `seo-crawl`, which assumes port 3100 (agent 5).
- **The admin axe is a script, not a CI gate.** It takes 6.3 min locally, which is over the 5-minute budget for a new gate, and it needs the full demo seed. Run `npm run build && npm run test:a11y:admin` (`e2e/admin-a11y/`, port 8344; `A11Y_ADMIN_REUSE=1` to reuse a running server). Run it before any admin UI PR merges. Wiring it into the Admin E2E job is a one-line step when the budget allows.
- New dev dependency: `@axe-core/playwright@4.14.0` in the root `package.json`, with `package-lock.json` updated.
