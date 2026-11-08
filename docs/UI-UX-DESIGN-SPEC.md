# Toy Mart BD — UI/UX & Design System Specification

**Status:** Draft for review · **Date:** 2026-11-07

---

## 0. Status — Resolved, and Now In Build

**2026-11-08.** The Figma file was re-inspected after an account change (now signed in as *Mominul Islam*, team *Decent Themes*). The result was identical: file name `Document`, one page, **zero** top-level nodes, zero variable collections, zero paint styles, zero text styles. This is not a permissions artefact — the file is genuinely empty.

**The owner's instruction is therefore to build the design, not review it.** Section 1 below is retained as the record of why there was nothing to review. Everything from section 2 onward is the specification being built into that file now.

**Build approach.** The Figma MCP requires that design-system mutations run **strictly sequentially** — parallel writes to one file risk corrupting it. So the five parallel agents produce detailed written specifications in `docs/_design/`, and the lead executes all Figma construction in sequence from those specs:

| Spec file | Covers |
|---|---|
| `docs/_design/01-foundations-spec.md` | Final palette with verified contrast ratios, type scale, spacing, radius, elevation, motion, Figma variable-collection plan, logo concept |
| `docs/_design/02-primitives-spec.md` | 31 Tier-1 components with exact dimensions, variant axes, token bindings and states |
| `docs/_design/03-storefront-spec.md` | Tier-2 composites plus section-by-section layouts for Home, PDP, category, checkout, cart, search, tracking — at 390px and 1440px |
| `docs/_design/04-admin-spec.md` | Tier-3 composites plus layouts for dashboard, order board, order detail, product form, risk screen, courier queue, P&L, stock ledger |
| `docs/_design/05-content-and-assets-spec.md` | The full bilingual UI string set, notification templates with SMS segment counts, iconography, illustration and empty states, photography brief |

**Figma file structure being created:**
```
00 Cover · 01 Foundations · 02 Primitives · 03 Storefront Components
04 Admin Components · 05 Storefront — Mobile · 06 Storefront — Desktop · 07 Admin — Desktop
```

**Scope of this build pass.** Foundations, the component library, and the six screens that establish the visual direction: Home (mobile + desktop), product detail, checkout, admin dashboard, admin order board. The remaining screens from the inventory in §5 are built once the owner approves the direction — building a hundred screens before the look is agreed would guarantee rework.

---

## 1. Original Figma Review — Why There Was Nothing To Review

**The supplied Figma file was empty.**

File: `https://www.figma.com/design/PWZp5VzEgEEf5N2Y38lGIh/Toy-Mart?node-id=0-1`

Inspected on 2026-11-07 via the Figma MCP API. Verified state:

| Property | Value |
|---|---|
| File name | `Document` (never renamed) |
| Pages | 1 — `Page 1` |
| Top-level nodes on that page | **0** |
| Canvas bounds | 0 × 0 |
| Local variable collections | 0 |
| Local paint styles | 0 |
| Local text styles | 0 |
| Published components | none |

There is therefore **no visual direction to review, no screens to audit, and no design tokens to extract.** The requested review deliverables — design strengths, UX problems, missing screens, responsive issues — cannot be produced against this file without inventing findings, which the brief explicitly forbids.

### Resolution
This document therefore defines the design system **from first principles** rather than reviewing an existing one. It is written to be Figma-ready: every token, component and screen below can be entered directly into a new Figma library.

**Resolved 2026-11-08.** The owner re-supplied the same URL and instructed that the design be built. Option 2 was taken by implication: this specification is the visual direction, and it is being constructed in the empty file. See §0.

---

## 2. Brand Direction

The brief asks for playful, colourful, friendly, modern, premium, trustworthy, parent-friendly, and child-focused without being childish. Those pull in opposite directions, so the resolution has to be explicit:

> **Children supply the energy; parents supply the trust.** Colour, illustration and motion carry the playfulness. Layout, typography, spacing and information density carry the credibility. Playfulness never touches the parts of the interface where a parent is deciding whether to hand over money or a phone number.

Three rules follow, and they settle most individual design arguments:

1. **Colour is the toy; structure is the shop.** Saturated brand colour appears in hero areas, category tiles, badges and illustration. Product cards, cart, checkout and account screens stay on a near-white surface with one accent — because that is where hesitation happens.
2. **No baby-talk in the interface.** Microcopy is warm and plain. "Delivery in 2–3 days inside Dhaka", never "Yay! Your toys are zooming to you!" A parent spending BDT 3,000 wants competence.
3. **Every price, stock state and delivery promise is unambiguous.** The most trust-building thing this site can do is answer, before being asked: is it in stock, what will delivery cost, when will it arrive, and what happens if my child doesn't like it.

### Audience reality this must serve
Predominantly mid-range Android, 4G, one-handed, often shopping in the evening, frequently price-comparing against Facebook pages and Daraz in other tabs. Many buyers read Bangla more comfortably than English but search in Banglish. A meaningful share have been burned by a Facebook-page seller before, which is why trust signals are load-bearing rather than decorative.

---

## 3. Design Tokens

### 3.1 Colour

Primitive ramps (the raw palette) are separated from semantic tokens (what the UI actually references). Components may only use semantic tokens — that is what makes dark mode and any future rebrand a token change rather than a rewrite.

**Primitives**

| Ramp | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 |
|---|---|---|---|---|---|---|---|---|---|---|
| `brand` (coral-red) | #FFF1F0 | #FFDEDB | #FFBFB8 | #FF968C | #FF6E5F | **#F4453A** | #D42E27 | #AE2020 | #8A1A1B | #701819 |
| `accent` (sunshine) | #FFFBEB | #FFF3C4 | #FFE88A | #FFDB4D | #FFCE1F | **#F7B500** | #D18F00 | #A66E05 | #85570C | #6E470F |
| `teal` (play) | #EFFCFB | #D0F7F3 | #A3EDE8 | #6CDCD6 | #35C3BF | **#13A5A2** | #0D8483 | #0E696A | #0F5456 | #104548 |
| `grape` (fun) | #F6F2FF | #EBE2FF | #D8C7FF | #BEA2FF | #A07AFA | **#8255EE** | #6A3BD1 | #562EA8 | #452785 | #39226B |
| `neutral` | #FAFAFA | #F4F4F5 | #E8E8EB | #D4D4D8 | #A1A1AA | #71717A | #52525B | #3F3F46 | #27272A | #18181B |
| `success` | #ECFDF4 | — | — | — | — | **#12A150** | #0C7F3E | — | — | — |
| `warning` | #FFF8EB | — | — | — | — | **#E07C02** | #B35F00 | — | — | — |
| `danger` | #FEF1F1 | — | — | — | — | **#DC2626** | #B01919 | — | — | — |
| `info` | #EFF6FF | — | — | — | — | **#2563EB** | #1D4ED8 | — | — | — |

**Semantic tokens**

| Token | Light | Dark | Used for |
|---|---|---|---|
| `color.bg.canvas` | `neutral.50` | `neutral.900` | Page background |
| `color.bg.surface` | `#FFFFFF` | `neutral.800` | Cards, panels, modals |
| `color.bg.surface.raised` | `#FFFFFF` | `neutral.700` | Popovers, dropdowns |
| `color.bg.subtle` | `neutral.100` | `neutral.800` | Table stripes, inert fills |
| `color.bg.brand` | `brand.500` | `brand.500` | Primary buttons, active nav |
| `color.bg.brand.subtle` | `brand.50` | `brand.900` | Tinted sections, selected rows |
| `color.bg.accent` | `accent.500` | `accent.500` | Sale badges, countdown |
| `color.text.primary` | `neutral.900` | `neutral.50` | Body, headings |
| `color.text.secondary` | `neutral.600` | `neutral.400` | Supporting copy |
| `color.text.tertiary` | `neutral.500` | `neutral.500` | Metadata, placeholders |
| `color.text.on-brand` | `#FFFFFF` | `#FFFFFF` | Text on `bg.brand` |
| `color.text.on-accent` | `neutral.900` | `neutral.900` | Text on `bg.accent` — **never white** |
| `color.text.price` | `neutral.900` | `neutral.50` | Current price |
| `color.text.price.was` | `neutral.500` | `neutral.400` | Struck original price |
| `color.text.discount` | `brand.600` | `brand.400` | "−25%" |
| `color.border.default` | `neutral.200` | `neutral.700` | Dividers, inputs |
| `color.border.strong` | `neutral.300` | `neutral.600` | Hovered inputs |
| `color.border.focus` | `teal.500` | `teal.400` | Focus ring — distinct from brand so error+focus stay distinguishable |
| `color.status.in-stock` | `success.500` | `success.500` | |
| `color.status.low-stock` | `warning.500` | `warning.500` | |
| `color.status.out-of-stock` | `neutral.500` | `neutral.400` | |

> `accent.500` on white is 1.9:1 — it is a **surface colour only** and must never carry text. Text on accent is always `neutral.900`. This single rule prevents the most common accessibility failure in bright, playful palettes.

**Accessibility contract.** Every text/background pair in the table meets WCAG 2.2 AA (4.5:1 body, 3:1 for ≥24px). `brand.500` on white is 4.6:1 — it passes for body text and is the only brand shade allowed on white type. Lighter brand shades are surfaces only. Contrast is verified in CI by a token-level test, not by eye.

### 3.2 Typography

| | Family | Fallback | Rationale |
|---|---|---|---|
| Latin UI + headings | **Plus Jakarta Sans** | system-ui, sans-serif | Rounded geometric — friendly without being a comic font; wide weight range |
| Bangla | **Anek Bangla** | Noto Sans Bengali UI, sans-serif | Ek Type's Anek superfamily, drawn specifically to sit beside a Latin grotesque. Eight weights **including ExtraBold (800)**, so Bangla display type matches Latin instead of dropping a step. Replaced Anek Bangla on 2026-11-08 — see the note below |
| Numerals / price | Plus Jakarta Sans, `font-variant-numeric: tabular-nums` | — | Prices and admin tables must align in columns |

Bangla needs ~1.15× the line-height of Latin at the same size for ascender/descender clearance — matra above, conjunct descenders below. The `.lang-bn` class applies the adjustment; never set one global line-height for both scripts.

> **Bangla face changed 2026-11-08, on the owner's instruction, from Anek Bangla to Anek Bangla.** Three reasons, and one caveat.
>
> 1. **It removes a real compromise.** Anek Bangla tops out at Bold (700), verified against the live Figma file — so Bangla `display-1`, `display-2` and `price-lg` had to drop a weight while Latin used ExtraBold (800). Anek Bangla has ExtraBold, so the two scripts now carry identical weights at every step of the scale. A hero headline no longer looks lighter in Bangla than in English.
> 2. **It is drawn for this pairing.** Anek is Ek Type's multi-script superfamily, designed so each script sits beside a Latin grotesque without a skeleton mismatch. Anek Bangla's forms are higher-contrast and more traditional, which fought Hanken Grotesk at the constant Bangla–Latin boundary this interface has — every price, every mixed label, every `৳2,450` next to a Bangla product name.
> 3. **It differentiates.** Anek Bangla is the default choice on Bangladeshi sites, so using it reads as the template the brief explicitly refuses.
>
> **Caveat, stated plainly:** Anek Bangla is newer and less battle-tested in Bangladesh than Anek Bangla, SolaimanLipi or Kalpurush. **A native Bangla reader must confirm conjunct rendering (যুক্তাক্ষর) and matra positioning at 15px on a real mid-range Android before launch.** If that check fails, the fallback is **Noto Sans Bengali UI** — 12 weights, tighter UI vertical metrics, exhaustive coverage — which is a font-family token change, not a redesign. Neither SolaimanLipi nor Kalpurush is available in Figma or on Google Fonts, so neither is a candidate for this system.

**Type scale** (1.250 major third, 16px base, clamped fluid for display sizes)

| Token | Mobile | Desktop | Weight | Line height | Tracking | Use |
|---|---|---|---|---|---|---|
| `display-1` | 32px | 52px | 800 | 1.08 | −0.02em | Hero headline |
| `display-2` | 26px | 40px | 700 | 1.15 | −0.015em | Section hero |
| `h1` | 24px | 32px | 700 | 1.2 | −0.01em | Page title |
| `h2` | 20px | 26px | 700 | 1.25 | −0.01em | Section heading |
| `h3` | 18px | 20px | 600 | 1.3 | 0 | Card group title |
| `h4` | 16px | 17px | 600 | 1.35 | 0 | Sub-heading |
| `body-lg` | 17px | 18px | 400 | 1.6 | 0 | Product description |
| `body` | 15px | 16px | 400 | 1.6 | 0 | Default |
| `body-sm` | 14px | 14px | 400 | 1.5 | 0 | Supporting |
| `caption` | 12px | 12px | 500 | 1.4 | 0.01em | Metadata, badges |
| `overline` | 11px | 11px | 700 | 1.3 | 0.08em | Eyebrow labels (uppercase) |
| `price-lg` | 24px | 28px | 800 | 1.2 | −0.01em | PDP price |
| `price` | 17px | 18px | 700 | 1.3 | −0.01em | Card price |
| `price-was` | 14px | 14px | 400 | 1.3 | 0 | Struck price |

Minimum body size on mobile is 15px. Product titles on cards clamp to two lines; never truncate mid-word at one line — parents scan by toy name.

**Loading.** `next/font` self-hosting for both families, `display: swap`, subset to Latin + Bengali, weights 400/600/700/800 only. Target: no font file over 40KB, no layout shift.

### 3.3 Spacing, Radius, Elevation, Motion

**Spacing** — 4px base: `0, 1=4, 2=8, 3=12, 4=16, 5=20, 6=24, 8=32, 10=40, 12=48, 16=64, 20=80, 24=96`. Section vertical rhythm: 48px mobile, 80px desktop. Page gutter: 16px mobile, 24px tablet, 32px desktop, max content width 1280px.

**Radius** — `sm=6, md=10, lg=14, xl=20, 2xl=28, full=9999`. Product cards and images `lg`; buttons `full` on the storefront (the single strongest playful signal, at zero cost to usability) and `md` in the admin (where pill buttons read as unserious). Modals `xl`.

**Elevation** — four levels only, all warm-tinted rather than pure black, because neutral-grey shadows look cheap against a saturated palette:
`e1` `0 1px 2px rgba(24,24,27,.06)` · `e2` `0 2px 8px rgba(24,24,27,.08)` · `e3` `0 8px 24px rgba(24,24,27,.10)` · `e4` `0 20px 48px rgba(24,24,27,.14)`. Dark mode substitutes a 1px `neutral.700` border for `e1`/`e2`, since shadows are invisible on dark surfaces.

**Motion** — `fast=120ms`, `base=200ms`, `slow=320ms`; easing `standard cubic-bezier(.2,0,0,1)`, `decelerate cubic-bezier(0,0,.2,1)`, `spring cubic-bezier(.34,1.56,.64,1)` reserved for add-to-cart confirmation only. Everything respects `prefers-reduced-motion`. No carousel auto-advances — auto-advancing heroes measurably hurt conversion and accessibility.

### 3.4 Iconography & Illustration
**Phosphor Icons** (regular weight), sizes 16/20/24, filled paths on a 256 grid — replaced Lucide on 2026-11-08. Lucide is the starter-kit default and reads as templated; Phosphor is drawn to sit beside a Latin grotesque, and its filled paths scale cleanly where strokes do not. Path data comes from `@phosphor-icons/core`, never hand-written. Colour is applied as a fill. Category icons are custom, flat, two-tone (brand + accent), 48px — line icons read as too corporate for this category. Illustration is reserved for empty states, 404, order-success and the "no results" state; nowhere else. Product photography is never illustrated over.

**Photography standard** — square 1:1, pure white or `neutral.50` background, toy filling 85% of the frame, consistent lighting, minimum 1200×1200 source. A set of shots that vary in crop and background is the fastest way to look like a Facebook reseller; consistency here outweighs individual photo quality.

---

## 4. Component Library

Every component is specified with states (`default, hover, active, focus-visible, disabled, loading, error`), responsive behaviour, and a 44×44px minimum touch target. Grouped by build order.

### Tier 1 — Primitives (build first; everything depends on them)
`Button` (variants `primary, secondary, outline, ghost, danger`; sizes `sm 36, md 44, lg 52`; `iconLeft/iconRight`, `loading`, `fullWidth`) · `IconButton` · `Input` · `Textarea` · `Select` · `NativeSelect` (mobile-preferred — a custom select on Android is almost always worse) · `Checkbox` · `Radio` · `Switch` · `QuantityStepper` (44px targets; disables at stock ceiling) · `Badge` (`sale, new, bestseller, low-stock, out-of-stock, free-delivery`) · `Chip` (removable filter token) · `Avatar` · `Spinner` · `Skeleton` · `Divider` · `Tooltip` · `Rating` (display + interactive) · `Price` (handles current/was/discount/tabular alignment in one place) · `Tabs` · `Accordion` · `Breadcrumb` · `Pagination` · `Alert` · `Toast` · `Modal` · `Drawer` (right on desktop, bottom-sheet on mobile) · `Popover` · `EmptyState` · `Stepper` · `ProgressBar`

### Tier 2 — Storefront composites
`ProductCard` — image 1:1 with lazy loading and a fixed aspect box (no CLS), discount badge top-left, wishlist top-right, two-line clamped title, `Price`, rating with count, stock pill, and `Add to cart` that becomes a `QuantityStepper` in place once added. Variants: `grid` (2-up mobile / 4-up desktop), `list`, `compact` (carousels). **Two per row on mobile, not one** — parents compare toys, and a single-column grid halves the number of options visible per scroll.

`ProductCardSkeleton` · `CategoryTile` · `MegaMenu` (3-level, keyboard navigable, promo slot) · `MobileNav` (bottom tab bar: Home, Categories, Search, Cart, Account) · `SearchBar` (debounced, Banglish-tolerant, grouped suggestions for products/categories/brands, recent searches) · `FilterPanel` (desktop sidebar, mobile bottom-sheet; **Age** first, then Price, Category, Brand, Gender, Rating, Availability; every facet shows a result count and selections render as removable `Chip`s above the grid) · `SortDropdown` · `ProductGallery` (thumbnail rail, pinch-zoom on mobile, hover-zoom on desktop, video slot, swipeable) · `VariantSelector` (colour swatches, size pills; out-of-stock combinations disabled with the reason, never hidden) · `AgeRangeIndicator` · `DeliveryEstimator` (district selector returning real charge and ETA **on the product page, before the cart** — the highest-value single component on the storefront; a hidden delivery charge discovered at checkout is a top abandonment cause) · `StockIndicator` · `TrustStrip` (COD available · 7-day return · authentic products · phone support — shown on PDP and checkout) · `AddToCartBar` (sticky on mobile PDP, holding price + variant summary + CTA) · `CartLineItem` · `CartSummary` · `CouponInput` · `OrderTimeline` (customer-facing shipment status) · `ReviewCard` · `ReviewForm` · `WishlistButton` · `RecentlyViewedRail` · `FrequentlyBoughtTogether` · `CountdownTimer` · `WhatsAppFab` (bottom-right, above the mobile tab bar, never covering the add-to-cart button)

### Tier 3 — Admin composites
`AppShell` (collapsible sidebar, topbar with global search / notifications / shift context / theme toggle) · `DataTable` (server-side sort, filter, cursor pagination, column visibility, row selection, bulk action bar, sticky header, CSV export, density toggle, saved views) · `StatCard` (value, delta vs. previous period, sparkline, drill-through link) · `ChartCard` (Recharts; line, bar, stacked bar, donut) · `DateRangePicker` (presets: today, yesterday, last 7/30 days, this month, last month, custom — all Dhaka-dated) · `FormSection` · `FormActions` (sticky save bar) · `ImageUploader` (drag-drop, reorder, alt-text field per image, crop) · `VariantMatrixEditor` (attribute combinations in one grid with price/cost/SKU/stock per row) · `RichTextEditor` · `SlugField` (with a live warning when a slug change will create a 301) · `SeoPanel` (title/description with pixel-width preview, SERP preview, OG preview, robots) · `OrderStatusBadge` · `OrderStatusStepper` · `RiskScoreCard` (**our score and the courier's verified data in two visually distinct panels, each labelled and timestamped — never merged into one number**) · `CustomerPhoneLookup` · `CourierBookingPanel` (provider choice, weight, COD amount, live charge preview, book / bulk-book) · `ShipmentTrackingTimeline` · `StockAdjustmentForm` · `StockMovementLedger` · `PurchaseOrderForm` · `ProfitLossStatement` · `ExpenseForm` · `InvoicePreview` · `CouponBuilder` · `PromotionRuleBuilder` · `CmsBlockEditor` (ordered, drag-reorder, per-type form, schedulable, with live preview) · `PermissionMatrix` · `ActivityFeed` · `BulkActionBar` · `ConfirmDialog` (destructive actions require typing the order number or product name)

---

## 5. Screen Inventory

Marked **MVP** or **P2**. Total: 42 storefront screens, 58 admin screens.

### Storefront (42)
**Browse & discover** — Home **MVP** · Category listing **MVP** · Brand listing **MVP** · Search results **MVP** · Zero-results search with suggestions **MVP** · All categories (mobile) **MVP** · Flash sale / campaign landing **MVP** · Shop by age **MVP** · Shop by price **P2** · Gift finder quiz **P2** · New arrivals **MVP** · Best sellers **MVP**
**Product** — Product detail **MVP** · Bundle detail **P2** · Size/age guide modal **MVP** · Review list + write review **MVP** · Image lightbox **MVP**
**Purchase** — Cart **MVP** · Empty cart **MVP** · Checkout (single page, accordion sections) **MVP** · Order confirmation **MVP** · Payment failed/retry **MVP** · Order tracking by number + phone **MVP** · Delivery charge checker **MVP**
**Account** — Login/register (phone + OTP primary, email secondary) **MVP** · OTP verification **MVP** · Dashboard **MVP** · Order history **MVP** · Order detail **MVP** · Addresses **MVP** · Wishlist **MVP** · Profile **MVP** · Children's profiles **P2** · Reviews written **P2** · Password reset **MVP**
**Content & utility** — Blog index **MVP** · Blog post **MVP** · Static page template (About, Contact, FAQ, Privacy, Terms, Returns) **MVP** · Contact with form **MVP** · 404 **MVP** · 500 **MVP** · Maintenance **MVP**

### Admin (58)
**Dashboard** — Overview **MVP** · Sales analytics **MVP** · Courier performance **P2** · Product performance **P2**
**Catalog (11)** — Product list **MVP** · Create/edit (tabs: General, Pricing, Variants, Inventory, Images, SEO, Related) **MVP** · Variant matrix **MVP** · Bulk import/export **MVP** · Category list + tree **MVP** · Category edit **MVP** · Brands **MVP** · Attributes **MVP** · Attribute values **MVP** · Review moderation **MVP** · Product duplication **MVP**
**Inventory (9)** — Stock overview **MVP** · Low-stock **MVP** · Out-of-stock **MVP** · Stock movement ledger **MVP** · Stock adjustment **MVP** · Suppliers **MVP** · Purchase orders **MVP** · Receive purchase **MVP** · Inventory valuation **MVP** · Damaged goods **P2** · Stock transfer **P2**
**Orders (10)** — Order board (status tabs with counts) **MVP** · Order detail **MVP** · Manual order creation **MVP** · Order edit **MVP** · Bulk processing **MVP** · Pending verification queue **MVP** · Cancellations **MVP** · Returns **MVP** · Refunds **MVP** · Order activity log **MVP**
**Fulfilment (7)** — Ready-to-ship queue **MVP** · Courier booking **MVP** · Bulk courier booking **MVP** · Shipment list **MVP** · Shipment detail + tracking **MVP** · Failed bookings retry **MVP** · COD settlement reconciliation **MVP** · Courier providers config **MVP** · Courier location mapping **MVP**
**Risk (4)** — Phone lookup **MVP** · High-risk queue **MVP** · Blacklist/whitelist **MVP** · Risk rule configuration **P2**
**Finance (8)** — P&L statement **MVP** · Daily/weekly/monthly reports **MVP** · Expenses **MVP** · Expense categories **MVP** · Product-wise profitability **MVP** · Order-wise profitability **MVP** · Supplier payments **MVP** · COD receivables **MVP** · Cash position **P2**
**Customers (4)** — List **MVP** · Detail (orders, addresses, LTV, risk, notes) **MVP** · Segments **P2** · Import/export **P2**
**Marketing (7)** — Coupons **MVP** · Coupon builder **MVP** · Promotions **MVP** · Flash sales **MVP** · Abandoned carts **MVP** · Campaigns (SMS/WhatsApp) **P2** · Notification templates **MVP**
**CMS (7)** — Homepage block builder **MVP** · Static pages **MVP** · Menus **MVP** · Banners **MVP** · Blog posts **MVP** · Blog categories **MVP** · Media library **MVP** · Testimonials **P2**
**SEO (5)** — Per-entity SEO panel **MVP** · Redirect manager **MVP** · Sitemap status **MVP** · Robots.txt editor **MVP** · Schema preview/validator **P2**
**System (7)** — Users **MVP** · Roles & permissions **MVP** · Store settings **MVP** · Courier settings **MVP** · Payment settings **MVP** · Notification settings **MVP** · Tracking & pixels **MVP** · Tracking diagnostics **MVP** · Activity log **MVP** · Invoice template **MVP**

### Screens the brief did not list but which the system needs
Named explicitly because omitting them is how a build slips: courier location mapping (unmapped areas block bookings) · failed courier booking retry queue · COD settlement reconciliation · tracking diagnostics · order edit after placement (BD customers phone in changes constantly) · pending verification queue · zero-results search page · payment retry · delivery charge checker · slug-change 301 warning · bulk import error report.

---

## 6. Key Interaction Specifications

### 6.1 Checkout — single page, three accordion sections
Steps 1 Contact & delivery → 2 Delivery method & charge → 3 Payment & review, all on one route, with a sticky order summary (desktop right rail, mobile collapsible top bar) and a sticky `Place order` button.

Rules that exist because of observed BD abandonment causes:
- **Guest checkout is the default.** Registration is offered after the order is placed, pre-filled, as a single "set a password" step. Forcing an account before purchase is the single largest avoidable drop-off.
- **Phone is the primary identifier**, 11-digit local format, operator-prefix validated inline, auto-formatted. Email is optional.
- **District → upazila → area cascading selects**, searchable, with the last-used address remembered. Free-text address is a single field with a `landmark` hint ("beside which shop / which floor") because that is how BD addresses actually work and it directly reduces failed deliveries.
- **The delivery charge is visible before this page** via the product-page estimator, and is shown again here. It never changes between cart and checkout.
- **COD is listed first** and pre-selected. Online payment sits below with its methods shown as logos. Any COD advance-payment requirement is stated on the product page, not revealed here.
- The coupon field is collapsed behind "Have a coupon?" — an open empty coupon field makes buyers leave to hunt for a code.
- One field per row on mobile. Numeric keypad for phone. No field asks for information the business will not use.
- The `Place order` button shows the exact amount: `Place order · ৳2,450`.
- On failure, the form state survives; the error is specific and names the fixable field.

### 6.2 Product detail — above-the-fold priority on mobile
Image gallery → title → rating + review count → price block with discount → stock state → variant selector → quantity → **delivery estimator** → Add to cart (sticky) → trust strip → short description → full description / specifications / age guidance / reviews / related.

Non-negotiables: the price never moves or reflows after load; the image box has a reserved aspect ratio (zero CLS); out-of-stock variants are disabled with a reason, never hidden; an out-of-stock product offers "notify me" rather than a dead end.

### 6.3 Search
Debounced 250ms, minimum 2 characters. Suggestions grouped as Products (with thumbnail and price), Categories, Brands. Handles Banglish ("গাড়ি", "gari", "car" return the same results) via a Meilisearch synonym set that must be seeded from real search logs within the first month. Zero results never ends the journey: it shows corrected spelling, the closest category, and best sellers.

### 6.4 Admin order board
Status tabs with live counts; default view is `Pending` because that is the queue that costs money to leave alone. Each row: order number, time, customer name + masked phone, items count, total, payment method, risk pill, courier status. Bulk select → confirm / print invoices / book courier / mark packed. Risk badge is colour + text, never colour alone. Keyboard shortcuts for the top five actions, because operations staff work this screen all day.

### 6.5 Dark mode
Admin only at launch (operations staff work long sessions); storefront follows the system preference in P2. Every colour reference is a semantic token, so storefront dark mode is a token-map addition, not a redesign.

### 6.6 Bilingual UI — both languages at launch
Bangla UI moved into MVP on the owner's instruction (2026-11-08). Design consequences that are not optional:

- **Every screen is designed twice.** Not translated twice — *designed* twice. Bangla strings run roughly 20–40% longer than English in buttons, labels and navigation, so a button that fits "Add to cart" will not fit its Bangla equivalent at the same width. Every component with a text label must hug its content or wrap, never truncate.
- **Line-height differs by script.** Bangla needs ~1.15× the Latin line-height at the same size, applied through a `.lang-bn` class. A single global line-height produces clipped ascenders in Bangla and loose, airy English.
- **Anek Bangla for Bangla, Plus Jakarta Sans for Latin**, with the Bangla face carrying the same weight range so the two scripts read as one system rather than two pasted together.
- **Numerals stay Western (0–9) in both locales** — prices, phone numbers, quantities, order numbers, dates. Bengali numerals (০–৯) appear only in editorial copy where the writing calls for it. A price is the one element where a moment's hesitation costs an order, and mixed-script numerals also break tabular alignment in the cart and in every admin table.
- **Routing:** English at the root, Bangla at `/bn/`, `hreflang` pairs both ways plus `x-default`. The language toggle sits in the header and persists per visitor. Bangla is *suggested* on a first visit from Bangladesh — never force-redirected, which breaks crawling and annoys returning visitors.
- **Admin stays English at launch.** Staff learn the tool once and work it daily; customers do not. Bangla admin is P2.
- **QA treats the two locales as separate surfaces.** Every MVP screen is checked at 390px and 1440px in both languages for string overflow, label wrapping, line-height and `hreflang` correctness, and axe runs against both.

---

## 7. Responsive & Accessibility

**Breakpoints** `xs 0 · sm 480 · md 768 · lg 1024 · xl 1280 · 2xl 1536`. Mobile-first: base styles are mobile; every media query adds upward.

| Region | Mobile | Tablet | Desktop |
|---|---|---|---|
| Product grid | 2 columns | 3 | 4 (5 on 2xl) |
| Navigation | Bottom tab bar + hamburger drawer | Hamburger + search | Full mega menu |
| Filters | Bottom sheet, triggered by a sticky button showing the active count | Bottom sheet | Persistent left sidebar |
| PDP | Single column, sticky add-to-cart bar | Single column | Gallery left / details right, sticky details |
| Cart | Stacked, sticky total bar | Stacked | Items left / summary right |
| Admin tables | Card list per row with the three most important fields | Horizontal scroll with frozen first column | Full table |
| Admin sidebar | Off-canvas drawer | Icon rail | Expanded, collapsible |

**Accessibility — WCAG 2.2 AA, enforced not aspirational.**
Semantic landmarks on every page · one `h1`, no skipped heading levels · visible 2px `border.focus` focus ring on every interactive element (never `outline: none` without a replacement) · full keyboard operability including the mega menu, filter sheet and modals · focus trapped in modals and returned on close · `aria-live` for cart updates, filter result counts and form errors · all images have meaningful `alt` (product alt is auto-composed as "{product} — {variant} — image {n}" and admin-overridable) · icon-only buttons have `aria-label` · form errors are programmatically associated and never conveyed by colour alone · 44×44px minimum targets · `prefers-reduced-motion` honoured · 200% zoom and 320px width both work without horizontal scroll · both Bangla and English pass the same checks.
CI gate: `axe-core` via Playwright on home, category, PDP, cart, checkout, and the admin order board. A new violation fails the build.

---

## 8. Performance Budgets (enforced by Lighthouse CI)

| Metric | Home | Category | PDP | Checkout |
|---|---|---|---|---|
| LCP (mobile, throttled) | ≤2.5s | ≤2.5s | ≤2.5s | ≤2.0s |
| INP | ≤200ms | ≤200ms | ≤200ms | ≤200ms |
| CLS | ≤0.05 | ≤0.05 | ≤0.05 | ≤0.02 |
| JS transferred | ≤140KB | ≤140KB | ≤160KB | ≤120KB |
| Image bytes above fold | ≤250KB | ≤300KB | ≤250KB | — |

Means: AVIF with WebP fallback via `next/image`, explicit dimensions everywhere, `priority` only on the LCP image, `font-display: swap` with self-hosted subsets, no third-party script in the critical path (GTM loads after interactive), route-level code splitting, `prefers-reduced-data` honoured. A PR that breaks a budget fails CI.

---

## 9. Figma File Structure (to build in Phase 2)

```
Toy Mart BD — Design System        (published library)
├── 00 Cover
├── 01 Foundations     colour primitives + semantic tokens (variables, Light/Dark modes),
│                      type scale, spacing, radius, elevation, motion, icon grid
├── 02 Primitives      Tier 1 components as variant sets, all states, bound to variables
├── 03 Storefront      Tier 2 composites
├── 04 Admin           Tier 3 composites
└── 05 Patterns        form layouts, empty states, loading states, error states

Toy Mart BD — Storefront           (consumes the library)
├── 01 Mobile 390      every MVP screen
├── 02 Desktop 1440    every MVP screen
├── 03 Flows           checkout, search, returns (prototyped)
└── 04 P2

Toy Mart BD — Admin
├── 01 Desktop 1440 Light
├── 02 Desktop 1440 Dark
├── 03 Tablet 768
└── 04 Flows           order → courier → delivered → P&L
```

Build order: Foundations as Figma **variables** (not styles — variables give real light/dark modes) → primitives as component sets with variant properties matching the React prop names exactly → composites from instances only → screens from composites only. A screen containing a raw rectangle or a hardcoded hex is a defect; it means a token is missing.

**Code parity.** Figma variable names and Tailwind token names are identical (`color.bg.brand` ⟷ `bg-brand`). Component variant properties match React props one-for-one. Code Connect maps each Figma component to its `src/components/ui` file so the design and the implementation cannot silently diverge.

---

## 10. What Still Needs the Owner's Input

1. ~~**The Figma file is empty**~~ — resolved; the design is being built into it. See §0.
2. **Logo and wordmark** — do they exist? In what format? Brand colours below are a proposal, not a match to an existing identity.
3. ~~**Bangla at launch or Phase 2?**~~ **Resolved: both languages at launch.** The open question is now *who writes the Bangla* — every product name, description, category label and policy page needs a human Bangla version. Machine translation is explicitly rejected; beating Daraz's machine-translated Bangla is the point. **This is now the critical-path content dependency, ahead of photography.**
4. **Product photography** — who shoots it, and against what standard? Inconsistent imagery will undo the design work faster than any code decision.
5. **Confirm the palette.** Coral-red + sunshine-yellow + teal is a deliberate choice: it reads as toys without the primary-colour cliché, and it is distinguishable from the blue-and-orange that most BD toy sites use. If there is an existing brand colour, say so now rather than after components are built.
