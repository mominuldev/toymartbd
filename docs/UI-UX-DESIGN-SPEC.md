# Toy Mart BD — UI/UX & Design System Specification

**Status:** Design complete and approved — in development · **Created:** 2026-11-07 · **Synced with Figma:** 2026-11-11

---

## 0. Status — Figma Is the Source of Truth

**2026-11-11 — design complete, signed off, development started.** The owner reviewed the finished file and approved it ("looks good, now start develop"), which closes Gate 2 for the MVP design. The admin adopts **Theme B — slate & indigo with a dark sidebar**; the storefront keeps the coral/sunshine/teal palette.

**Where truth lives now.** Figma file `PWZp5VzEgEEf5N2Y38lGIh` is the source of truth for every token, component and screen. Its variables are exported to `packages/tokens/src/figma-variables.json` and built into CSS, Tailwind and TypeScript by `npm run tokens:build` (see `packages/tokens/README.md`). Where this document's prose and the Figma file disagree, **Figma wins** and this document is the defect. Sections 3 and 9 below were rewritten on 2026-11-11 to match the file; sections 2, 4–8 are the reasoning and still hold.

| Figma page | Contents |
|---|---|
| 00 Cover | — |
| 01 Foundations | Variables (Primitives · Color with 4 modes · Spacing · Radius · Typography), effect styles, text styles, specimens |
| 02 Primitives | 31 Tier-1 components, all states, bound to variables |
| 03 Storefront Components | Tier-2 composites, incl. section 08 Account (AccountNavItem, AccountOrderCard, AddressCard, RewardsCard) |
| 04 Admin Components | Tier-3 components (mapped to React in §9) + 115 Phosphor admin icons |
| 05 Storefront — Mobile | 390px screens, incl. 9 account screens |
| 06 Storefront — Desktop | 1440px screens, incl. 5 account screens |
| 07 Admin — Desktop | 00 access & states · 01–09 41 screens at 1440 · 10 responsive 1024 · 11 responsive 768 · 12 dark mode · 13 বাংলা · 14 Theme B (approved) |
| 08 Archive | superseded work |

Screen-level node ids and the feature checklist are in `docs/_design/06-admin-feature-checklist.md`; storefront screens in `docs/_design/03-storefront-spec.md`.

**Colour modes.** `Light` and `Dark` (storefront) · `Admin Light` and `Admin Dark` (admin, Theme B). The admin sidebar renders in `Admin Dark` in both admin themes. Contrast audits in Figma: 0 failures across dark mode (1,704 text nodes), Theme B (6,085) and the sidebar (≥6.77:1); the token build re-checks 120 text/background pairs on every run.

### History

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

**Scope of the first build pass** was foundations, the component library and six direction-setting screens. After the owner approved the direction, the rest followed: all admin screens, admin responsive, dark mode and Bangla variants, the customer account area, and the alternate admin theme the owner then adopted (2026-11-11).

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

Primitive ramps (the raw palette) are separated from semantic tokens (what the UI actually references). Components may only use semantic tokens — that is what makes dark mode, the admin theme and any future rebrand a token change rather than a rewrite. The full set (59 semantic tokens × 4 modes, 101 primitives) is in `packages/tokens/src/figma-variables.json`; resolved hex per theme in `packages/tokens/dist/tokens.json`. The table below is the core subset.

**Storefront primitives (500 step)** — `brand` coral-red #DC3442 · `accent` sunshine #F7CE54 · `teal` #42A2BF · `grape` #8B51DE · `pink` #CA3F8D · warm `neutral` ramp from #FEFBF8 (50) to #151B30 (950) · `success` #49C08D · `warning` #DF7D39 · `danger` #DA3285 · `info` #3387EB. The admin modes use their own slate/indigo values, set directly per mode rather than through a primitive ramp.

**Semantic tokens (core)**

| Token | Light | Dark | Admin Light | Admin Dark | Tailwind | Used for |
|---|---|---|---|---|---|---|
| `color/bg/canvas` | `neutral/100` #FEF8F2 | `neutral/950` #151B30 | #F4F6F9 | #0B1020 | `bg-canvas` | Page background |
| `color/bg/surface` | #FFFFFF | `neutral/900` #232E4A | #FFFFFF | #131A2B | `bg-surface` | Cards, panels, modals |
| `color/bg/surface-raised` | #FFFFFF | `neutral/800` | #FFFFFF | #1B2438 | `bg-surface-raised` | Popovers, dropdowns |
| `color/bg/subtle` | `neutral/200` | `neutral/800` | #EEF1F5 | #1B2438 | `bg-subtle` | Table stripes, inert fills |
| `color/bg/brand` | `brand/500` #DC3442 | `brand/500` | #4F46E5 | #5B5BD6 | `bg-brand` | Primary buttons, active nav |
| `color/bg/brand-subtle` | `brand/100` | `brand/dark-tint` #4C2F48 | #EEF0FF | #252A52 | `bg-brand-subtle` | Tinted sections, selected rows |
| `color/bg/accent` | `accent/500` | `accent/500` | #F59E0B | #F59E0B | `bg-accent` | Sale badges — **surface only** |
| `color/bg/overlay` | ink 60% | ink 72% | #0F172A 60% | #000 72% | `bg-overlay` | Modal scrim |
| `color/text/primary` | `neutral/900` | `neutral/50` | #0F172A | #F1F5F9 | `text-fg-primary` | Body, headings |
| `color/text/secondary` | `neutral/700` | `neutral/300` | #475569 | #CBD5E1 | `text-fg-secondary` | Supporting copy |
| `color/text/tertiary` | `neutral/600` | `neutral/400` | #586377 | #94A3B8 | `text-fg-tertiary` | Metadata, placeholders |
| `color/text/on-brand` | #FFFFFF | #FFFFFF | #FFFFFF | #FFFFFF | `text-fg-on-brand` | Text on `bg/brand` |
| `color/text/on-accent` | `neutral/900` | `neutral/900` | #0F172A | #0F172A | `text-fg-on-accent` | Text on `bg/accent` — **never white** |
| `color/text/link` | `teal/700` | `teal/300` | #4338CA | #A5B4FC | `text-fg-link` | Links |
| `color/text/discount` | `brand/600` | `brand/300` | #BE123C | #FDA4AF | `text-fg-discount` | "−25%" |
| `color/border/default` | `neutral/300` | `neutral/700` | #E2E6EC | #2A3448 | `border-line-default` | Dividers, inputs |
| `color/border/strong` | `neutral/500` | `neutral/400` | #8892A6 | #64748B | `border-line-strong` | Input borders (3:1) |
| `color/border/focus` | `teal/600` | `teal/400` | #4F46E5 | #818CF8 | `ring-line-focus` | Focus ring — on the storefront distinct from brand, so error + focus stay distinguishable |
| `color/feedback/{success,warning,danger,info}` | `*/700` | `*/300` | #047857 · #B45309 · #BE123C · #1D4ED8 | #6EE7B7 · #FCD34D · #FDA4AF · #93C5FD | `text-feedback-*` | Alerts, toasts, status chips |
| `color/status/{in,low,out-of}-stock` | `success/700` · `warning/700` · `neutral/600` | `*/300` · `neutral/400` | #047857 · #B45309 · #586377 | #6EE7B7 · #FCD34D · #94A3B8 | `text-status-*` | Stock pills |

> `color/bg/accent` is a **surface colour only** and must never carry text other than `color/text/on-accent`. This single rule prevents the most common accessibility failure in bright, playful palettes.

**Accessibility contract.** Every text/background pair meets WCAG 2.2 AA (4.5:1 body, 3:1 for strong borders and the focus ring) in all four modes. This is verified on every build: `packages/tokens/scripts/build.mjs` checks 120 pairs and fails the build on any miss.

### 3.2 Typography

| | Family | Fallback | Rationale |
|---|---|---|---|
| Latin UI, body | **Hanken Grotesk** | ui-sans-serif, system-ui | Humanist grotesque with a tall x-height; sits on Anek Bangla's baseline without a visible step (replaced Plus Jakarta Sans — `_design/01-foundations-spec.md` §5) |
| Latin display (headings, prices, buttons on the storefront; admin titles) | **Fredoka** | Hanken Grotesk | Rounded, playful — carries the energy where it is safe to. Also supplies the `৳` glyph, which Hanken lacks |
| Bangla UI, body | **Anek Bangla** | Noto Sans Bengali UI | Ek Type's Anek superfamily, drawn to sit beside a Latin grotesque. Eight weights **including ExtraBold (800)**. Replaced Hind Siliguri on 2026-11-08 — see the note below |
| Bangla display | **Baloo Da 2** | Anek Bangla | The Bangla twin of Fredoka's rounded display voice |
| Mono (admin ids, SKUs, consignment numbers) | **JetBrains Mono** | ui-monospace | |
| Numerals / price | the family of the style, with `.tabular` (`font-variant-numeric: tabular-nums`) | — | Prices and admin tables must align in columns |

Bangla needs ~1.15× the line-height of Latin at the same size for ascender/descender clearance — matra above, conjunct descenders below. In code every `.t-*` type class carries its own Bangla twin under `:lang(bn)` / `.lang-bn`; never set one global line-height for both scripts.

> **Bangla face changed 2026-11-08, on the owner's instruction, from Hind Siliguri to Anek Bangla.** Three reasons, and one caveat.
>
> 1. **It removes a real compromise.** Hind Siliguri tops out at Bold (700), verified against the live Figma file — so Bangla `display-1`, `display-2` and `price-lg` had to drop a weight while Latin used ExtraBold (800). Anek Bangla has ExtraBold, so the two scripts now carry identical weights at every step of the scale. A hero headline no longer looks lighter in Bangla than in English.
> 2. **It is drawn for this pairing.** Anek is Ek Type's multi-script superfamily, designed so each script sits beside a Latin grotesque without a skeleton mismatch. Hind Siliguri's forms are higher-contrast and more traditional, which fought Hanken Grotesk at the constant Bangla–Latin boundary this interface has — every price, every mixed label, every `৳2,450` next to a Bangla product name.
> 3. **It differentiates.** Hind Siliguri is the default choice on Bangladeshi sites, so using it reads as the template the brief explicitly refuses.
>
> **Caveat, stated plainly:** Anek Bangla is newer and less battle-tested in Bangladesh than Hind Siliguri, SolaimanLipi or Kalpurush. **A native Bangla reader must confirm conjunct rendering (যুক্তাক্ষর) and matra positioning at 15px on a real mid-range Android before launch.** If that check fails, the fallback is **Noto Sans Bengali UI** — 12 weights, tighter UI vertical metrics, exhaustive coverage — which is a font-family token change, not a redesign. Neither SolaimanLipi nor Kalpurush is available in Figma or on Google Fonts, so neither is a candidate for this system.

**Storefront type scale** (Figma `Typography` collection → `.t-*` classes; desktop size applies from `lg` 1024px)

| Token | Mobile | Desktop | Weight | Line height Latin / Bangla | Tracking | Family | Use |
|---|---|---|---|---|---|---|---|
| `display-1` | 32px | 52px | 800 | 1.06 / 1.22 | −1.14px | display | Hero headline |
| `display-2` | 26px | 40px | 800 | 1.12 / 1.29 | −0.72px | display | Section hero |
| `h1` | 24px | 34px | 700 | 1.18 / 1.36 | −0.48px | display | Page title |
| `h2` | 20px | 27px | 700 | 1.25 / 1.44 | −0.27px | display | Section heading |
| `h3` | 18px | 21px | 600 | 1.30 / 1.50 | −0.13px | display | Card group title |
| `h4` | 16px | 18px | 600 | 1.35 / 1.55 | 0 | display | Sub-heading |
| `body-lg` | 17px | 18px | 400 | 1.60 / 1.84 | 0 | UI | Product description |
| `body` | 15px | 16px | 400 | 1.60 / 1.84 | 0 | UI | Default |
| `body-sm` | 14px | 14px | 400 | 1.50 / 1.73 | 0 | UI | Supporting |
| `caption` | 12px | 12px | 500 | 1.40 / 1.61 | 0.06px | UI | Metadata, badges |
| `overline` | 11px (Bangla 12) | 11px | 700 (Bangla 600) | 1.30 / 1.50 | 0.99px | UI | Eyebrow labels (uppercase in Latin only) |
| `label` | 13px | 13px | 600 | 1.35 / 1.55 | 0.05px | UI | Field labels |
| `button` | 15px | 16px | 600 | 1.20 / 1.38 | 0.03px | display | Button labels |
| `price-lg` | 24px | 30px | 800 | 1.15 / 1.32 | −0.36px | display | PDP price |
| `price` | 17px | 18px | 700 | 1.25 / 1.44 | −0.14px | display | Card price |
| `price-was` | 14px | 14px | 400 | 1.25 / 1.44 | 0 | display | Struck price |

**Admin type** (Figma `Admin/*` and `Admin/bn/*` text styles → `.t-admin-*`): `display` 32 · `page-title` 24 (Fredoka / Baloo Da 2) · `section` 17 · `body-lg` 16 · `body` 14 · `body-strong` 14 · `caption` 12 · `caption-strong` 12 · `overline` 11 uppercase, 8% tracking · `button` 14 · `button-sm` 13 (Hanken Grotesk / Anek Bangla) · `num` 14 · `num-lg` 26 · `num-xl` 34 (Hanken, no Bangla twin — digits stay Western) · `mono` 13 · `mono-sm` 12 (JetBrains Mono). Bangla twins run ~1.15× the Latin line-height (e.g. body 1.50 → 1.72).

Minimum body size on mobile is 15px. Product titles on cards clamp to two lines; never truncate mid-word at one line — parents scan by toy name.

**Loading.** Storefront: `next/font` self-hosting, `display: swap`, subset to Latin + Bengali, only the weights the scale uses. Target: no font file over 40KB, no layout shift. Admin: served from fonts.bunny.net (no Google tracking, no CLS concern for an internal tool) — see `resources/views/admin.blade.php`.

### 3.3 Spacing, Radius, Elevation, Motion

**Spacing** — 4px base: `0, 1=4, 2=8, 3=12, 4=16, 5=20, 6=24, 8=32, 10=40, 12=48, 16=64, 20=80, 24=96`. Section vertical rhythm: 48px mobile, 80px desktop. Page gutter: 16px mobile, 24px tablet, 32px desktop, max content width 1280px.

**Radius** — `none=0, xs=6, sm=10, md=16, lg=20, xl=28, 2xl=40, full=9999` (Figma `Radius`; Tailwind `rounded-*`). Buttons `full` on the storefront (the single strongest playful signal, at zero cost to usability) and squarer in the admin, where pill buttons read as unserious. Exact per-component radii are bound in Figma.

**Elevation** — four levels, as effect styles per mode (Tailwind `shadow-e1`…`e4`, switching with the theme):
- `Elevation/Light` — a neutral ink layer plus a coral-tinted layer, because neutral-grey shadows look cheap against a saturated palette.
- `Elevation/Dark` and `Elevation/Admin Dark` — a 1px inset ring plus a black drop shadow, since shadows alone are invisible on dark surfaces.
- `Elevation/Neutral` — slate-tinted, used by Admin Light (Theme B).
- `Focus/ring` — 2px surface gap + 2px `color/border/focus` (`shadow-focus`, applied on `:focus-visible`).

**Size tokens** — controls `sm 36 · md 44 · lg 52`, touch minimum 44, icons 16/20/24 and 48 for category tiles; border widths hairline 1, control 1.5, strong 2.

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
**Admin at launch** (operations staff work long sessions): `Admin Dark` mode, designed in Figma page 07 §12 (Theme A originals) and §14 (Theme B samples). The admin follows the OS preference until a staff member picks a theme; the choice is stored per browser (`tm-admin-theme`) and applied before first paint. **Storefront dark** exists as the `Dark` mode in Figma and in the token build, but ships in P2 — following the system preference — so launch QA covers one storefront theme. Every colour reference is a semantic token, so either is a `data-theme` switch, not a redesign.

### 6.6 Bilingual UI — both languages at launch
Bangla UI moved into MVP on the owner's instruction (2026-11-08). Design consequences that are not optional:

- **Every screen is designed twice.** Not translated twice — *designed* twice. Bangla strings run roughly 20–40% longer than English in buttons, labels and navigation, so a button that fits "Add to cart" will not fit its Bangla equivalent at the same width. Every component with a text label must hug its content or wrap, never truncate.
- **Line-height differs by script.** Bangla needs ~1.15× the Latin line-height at the same size, applied through a `.lang-bn` class. A single global line-height produces clipped ascenders in Bangla and loose, airy English.
- **Anek Bangla + Baloo Da 2 for Bangla, Hanken Grotesk + Fredoka for Latin**, with the Bangla faces carrying the same weight range so the two scripts read as one system rather than two pasted together.
- **Numerals stay Western (0–9) in both locales** — prices, phone numbers, quantities, order numbers, dates. Bengali numerals (০–৯) appear only in editorial copy where the writing calls for it. A price is the one element where a moment's hesitation costs an order, and mixed-script numerals also break tabular alignment in the cart and in every admin table.
- **Routing:** English at the root, Bangla at `/bn/`, `hreflang` pairs both ways plus `x-default`. The language toggle sits in the header and persists per visitor. Bangla is *suggested* on a first visit from Bangladesh — never force-redirected, which breaks crawling and annoys returning visitors.
- **Admin ships in English and Bangla** (owner decision, 2026-11-11 — supersedes "Bangla admin is P2"). A per-staff EN | বাং switch in the top bar; Bangla uses the `Admin/bn/*` text styles (Anek Bangla UI, Baloo Da 2 titles, ~1.15× line-height). Order numbers, SKUs, consignment IDs, phone numbers, prices and dates keep Western digits; customer-entered data (names, addresses) is shown as entered. Figma: section `13 · বাংলা (Bangla)` on page 07.
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

## 9. Figma ↔ Code

**File structure (as built).** One file, `PWZp5VzEgEEf5N2Y38lGIh`, holding library and screens together — pages listed in §0. A separate published library was not needed for a single team, and keeping one file means a screen and its components cannot drift between versions.

**Token pipeline.**
```
Figma variables + effect/text styles
   └─ export (use_figma, read-only)  →  packages/tokens/src/figma-variables.json   ← committed snapshot
        └─ npm run tokens:build      →  dist/tokens.css     --tm-* per data-theme, .t-* type classes
                                        dist/tailwind.css   @theme: bg-surface, text-fg-primary, border-line-default …
                                        dist/tokens.json    resolved hex per theme (tests, charts)
                                        src/generated.ts    ColorToken union, cssVar()
```
CI runs `npm run check --workspace=@toymart/tokens`, which fails if any output is stale or any contrast pair drops below AA. To change a token: change it in Figma, re-export, rebuild, commit the diff — never edit `dist/`.

**Naming parity.** Figma `color/bg/X` ⟷ `bg-X`, `color/text/X` ⟷ `text-fg-X`, `color/border/X` ⟷ `border-line-X`, `color/feedback/X` ⟷ `*-feedback-X`. The `fg-`/`line-` prefixes exist because Tailwind shares one colour namespace across `bg-`, `text-` and `border-`, and `brand`, `danger` and `accent` occur in more than one group. Tailwind's default palette is removed, so a non-token colour fails to compile. Component variant properties match React props one-for-one; Code Connect mappings are added as each component lands.

**Themes in code.** `data-theme` on `<html>`: `light`/`dark` for the storefront, `admin-light`/`admin-dark` for the admin. The admin sidebar carries `data-theme="admin-dark"` itself, matching Figma.

**Component → React map (admin).** Paths are relative to `resources/js/admin/`. Primitives live in `components/ui/`; feature composites live with their feature (feature-first folders, no cross-feature internal imports).

| Figma component (page 04, node) | React file |
|---|---|
| AdminButton 184:242 · AdminIconButton 184:267 | `components/ui/Button.tsx` · `IconButton.tsx` |
| AdminInput 184:338 · AdminSelect 184:369 · SearchField 185:28 · AdminField 185:151 | `components/ui/Input.tsx` · `Select.tsx` · `SearchField.tsx` · `Field.tsx` |
| CountBadge 185:43 · StatusChip 185:79 · Tag 185:92 | `components/ui/CountBadge.tsx` · `StatusChip.tsx` · `Tag.tsx` |
| SegmentedControl 185:110 · AdminTab 185:125 | `components/ui/SegmentedControl.tsx` · `Tabs.tsx` |
| ConfirmDialog 203:1387 · AdminToast 203:2315 · AdminEmptyState 203:1531 | `components/ui/ConfirmDialog.tsx` · `Toast.tsx` · `EmptyState.tsx` |
| SidebarItem 187:91 · Sidebar 187:473 · Topbar 187:474 · Topbar/Tablet 228:1857 · ContentHeader 187:530 | `components/shell/SidebarItem.tsx` · `Sidebar.tsx` · `Topbar.tsx` · `ContentHeader.tsx` → composed in `app/Shell.tsx` |
| DataTable 192:532 · OrderCard 228:1891 (≤768 row card) · BulkActionBar 193:740 · DateRangePicker 193:741 | `components/data/DataTable.tsx` · `features/orders/components/OrderCard.tsx` · `components/data/BulkActionBar.tsx` · `components/data/DateRangePicker.tsx` |
| StatCard 189:360 · ChartCards 189:361 / 406 / 437 / 483 | `features/dashboard/components/StatCard.tsx` · `ChartCard.tsx` |
| OrderStatusBadge 190:384 · RiskPill 190:405 · PaymentChip 190:441 · OrderStatusStepper 194:683 | `features/orders/components/…` (same names) |
| ShipmentTrackingTimeline 194:835 · CourierProviderCard 194:1014 · CourierBookingPanel 196:743 | `features/courier/components/…` |
| RiskScoreCard 197:858 / 995 · CustomerPhoneLookup 197:1057 | `features/risk/components/…` — our score and courier data stay two panels (rule 24) |
| FormSection 198:901 · FormActions 198:1085 · SlugField 198:1086 · SeoPanel 198:1123 | `components/form/…` |
| ImageUploader 201:1035 · VariantMatrixEditor 201:1201 · RichTextToolbar 201:1570 | `features/catalog/components/…` |
| PageBuilderSection 201:1716 | `features/cms/components/PageBuilderSection.tsx` |
| Chat components 202:1374–1560 | `features/inbox/components/…` |
| ActivityFeed 203:1532 · PermissionMatrix 203:1610 · IntegrationCard 203:2257 | `features/activity/…` · `features/staff/…` · `features/settings/…` |
| Admin icons (115, Phosphor) | `@phosphor-icons/react`, same glyph names — not re-drawn |

**Storefront map.** Primitives (page 02) → `toymartbd-app/storefront/src/components/ui/`; composites (page 03) → `src/components/<area>/` (`product/`, `cart/`, `checkout/`, `account/` …). Filled in when the storefront scaffold lands in Phase 3.

Build rules for future Figma work stay as before: variables not styles, composites from instances only, screens from composites only. A raw rectangle or hardcoded hex on a screen is a defect.

---

## 10. What Still Needs the Owner's Input

1. ~~**The Figma file is empty**~~ — resolved; the design is being built into it. See §0.
2. **Logo and wordmark** — do they exist? In what format? Brand colours below are a proposal, not a match to an existing identity.
3. ~~**Bangla at launch or Phase 2?**~~ **Resolved: both languages at launch.** The open question is now *who writes the Bangla* — every product name, description, category label and policy page needs a human Bangla version. Machine translation is explicitly rejected; beating Daraz's machine-translated Bangla is the point. **This is now the critical-path content dependency, ahead of photography.**
4. **Product photography** — who shoots it, and against what standard? Inconsistent imagery will undo the design work faster than any code decision.
5. ~~**Confirm the palette.**~~ **Resolved 2026-11-11:** the owner approved the design as built — coral/sunshine/teal for the storefront, Theme B (slate & indigo, dark sidebar) for the admin.
6. **Placeholders still in the designs** that need real values before launch: delivery charges (৳70 / ৳100 / ৳130), hotline number, trade licence and BIN, bKash merchant number, Nagad fee, loyalty earn/burn rates and tiers, branch addresses, sample product names.
