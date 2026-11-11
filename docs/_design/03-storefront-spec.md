# Toy Mart BD — Storefront Layout Specification

**Author:** Design Agent 3 · **Date:** 2026-11-08 · **Status:** Build-ready, for mechanical execution in Figma by the design lead
**Scope:** Tier 2 storefront composites + 7 screen families at 390px and 1440px + the real content they render
**Upstream:** `docs/UI-UX-DESIGN-SPEC.md` §3 (tokens), §4 Tier 2, §6 (interactions), §7 (responsive) · `docs/COMPETITOR-ANALYSIS.md` §2 (matrix), §5 (EduToyZone)

---

## 0. How to read this document

1. **Mobile is the source of truth.** Every component and screen below is specified at **390 × (variable)** first. The 1440 spec is a stated delta, never a redesign.
2. **Colour is only ever a semantic token.** No hex appears in this document. If a surface needs a colour that has no token, that is a missing token — report it, do not invent a fill.
3. **Spacing is only ever a spacing token.** `spacing/1`=4 · `2`=8 · `3`=12 · `4`=16 · `5`=20 · `6`=24 · `8`=32 · `10`=40 · `12`=48 · `16`=64 · `20`=80 · `24`=96. Where a number appears in a size column it is a measured dimension (image boxes, card heights, fixed bars), not a gap.
4. **Radius:** `radius/sm`=6 · `md`=10 · `lg`=14 · `xl`=20 · `2xl`=28 · `full`=9999. **Elevation:** `e1`–`e4`.
5. **"Hug" / "Fill" / "Fixed"** are Figma auto-layout sizing values. Every frame below declares direction + sizing on both axes.
6. **Typography tokens** are those in UI-UX-DESIGN-SPEC §3.2 (`display-1`, `h1`…`h4`, `body-lg`, `body`, `body-sm`, `caption`, `overline`, `price-lg`, `price`, `price-was`). Mobile value applies in the 390 frame, desktop value in the 1440 frame.
7. **Bangla overflow rule (global).** Every string box is specified for a Bangla string **40% longer in glyph count and 1.15× in line-height** than the English equivalent. Three strategies only, named per component: **(a) Fixed-height clamp** — box height set from the Bangla line count, English leaves whitespace, no reflow. **(b) Hug-and-grow** — the parent is hug-height and the Bangla string is allowed to add exactly one line; all siblings below are in the same auto-layout stack so nothing overlaps. **(c) Two-line pre-wrap** — the English label is authored on two lines too, so both languages occupy identical space. A component that would need horizontal truncation gets a shorter Bangla string instead; truncating Bangla mid-word is a defect.
8. **Numerals.** `en` renders Western digits (`৳4,250`), `bn` renders Bengali digits (`৳৪,২৫০`). The `৳` glyph is on the left in both locales, no space. All price text uses `tabular-nums`, so the digit swap cannot change box width for the same digit count.
9. **Nothing playful below the fold of a decision.** Illustration, sticker shapes, the hero blob and the confetti-on-add-to-cart live on Home, category tiles, badges and empty states. Cart, checkout, order tracking and all error states are `color/bg/surface` + one accent, `radius/md`, no illustration except the single empty-cart drawing.

### 0.1 Frame constants

| | 390 frame | 1440 frame |
|---|---|---|
| Page gutter | `spacing/4` (16) each side | `spacing/8` (32) each side, content capped at **1280** and centred → 80px dead margin each side at 1440 |
| Content width | **358** | **1280** |
| Section vertical rhythm | `spacing/12` (48) between sections | `spacing/20` (80) between sections |
| Product grid | **2 columns**, gap `spacing/3` (12) → card **173** wide | **4 columns**, gap `spacing/6` (24) → card **302** wide |
| Product grid *with* filter sidebar | n/a (sidebar is a sheet) | sidebar **272** + gap `spacing/8` (32) + grid **976**; **3 columns**, gap `spacing/6` (24) → card **309** wide. 4 columns only at ≥1536. |
| Rail (horizontal scroll) card | **Compact**, 148 wide, gap `spacing/3`, 16px leading inset, 32px trailing inset so the last card never sits flush to the edge | **Compact**, 216 wide, gap `spacing/6`; rail is a 1280 viewport with arrow controls, no auto-advance |
| Sticky top chrome | Header 56 (always) | Header 72 + MegaMenu bar 48 = 120 |
| Sticky bottom chrome | MobileNav 56 + safe-area inset | none |

---

# PART A — Composite components

## A1. `ProductCard` — the primary differentiator

> **Owner decision, 2026-11-11 — Version B adopted.** Two card designs were built in Figma and the owner chose **Version B**, which departs from A1.1–A1.4 below in four ways: (1) **two actions — a FILL "Buy now" gradient pill first** (straight to checkout with this one item) **and an icon-only cart IconButton second** (Outline, coral filled `icon/shopping-cart-fill` (the rounder "modern" cart with ring wheels), `aria-label` "Add to cart", adds in place) — on Grid and rail (Compact) cards alike; out of stock shows a single outline "Notify me" (owner, 2026-11-11, final); (2) **no rating row on the card** — ratings and review counts live on the PDP; (3) **free delivery and stock are pills on the photo** under the discount badge (truck icon + `color/status/in-stock` on a white 92% pill) — revised from an in-body text line; (4) **left-aligned** body under a photo framed inside the card (card `radius/sm`, photo `radius/xs`), price in `color/text/discount` with the struck was-price beneath it. Card stays **173 × 312** in every state (desktop **302 × 455**); title → price 6px, price → CTA 14px, image → title 12px, no flexible spacer. Titles: `Latin/Mobile/card-title` 15/21 (2 lines, 42 box) and new `Latin/Desktop/card-title` 17/24 (48 box) — spacing + title pass, owner 2026-11-11. Grid row gaps: mobile 16 (columns 12), desktop 32 (columns 24). **Stock pill and free-delivery pill sit on the photo, stacked under the discount badge** (white 92% pills; owner, 2026-11-11). The stock pill shows **only for exceptions** — "Only N left" and "Out of stock"; in-stock shows nothing, leaving title, price row and CTA in the body; the sale price and struck was-price sit **side by side** on one row (owner, 2026-11-11), which is what freed the 20px. The geometry tables below remain the reference for the image box, overlays (discount badge, wishlist 28px with 44 hit, derived age pill), stock pill, OOS behaviour ("Notify me", never a dead end) and the zero-CLS rule. Compact (rail) cards also carry an "Add to cart" pill (owner, 2026-11-11 — reverses A1.6's "no CTA, one tap target"): mobile 148 × 287 with a Small button, desktop 237 × 390 with a Medium button; desktop rails of 5 cards fill the full 1280 with space-between.


**Why it is a differentiator.** Of the eight audited competitors, none shows age, per-variant stock, a rating count and an in-place add-to-cart on one card; Toyshop's listing buttons read "Read more", Baby Bazar shows no stock state at all, and SK Toy ships cards priced ৳0 marked in stock. This card answers *what age · what price · in stock? · how many people bought it · can I add it without leaving the grid* in one 173px column, two per row.

### A1.1 Variant axes and component properties

| Property | Type | Values | Default |
|---|---|---|---|
| `Layout` | Variant | `Grid` · `List` · `Compact` | `Grid` |
| `Stock` | Variant | `InStock` · `LowStock` · `OutOfStock` · `PreOrder` | `InStock` |
| `Discounted` | Variant (boolean) | `true` · `false` | `false` |
| `CartState` | Variant | `Idle` · `Adding` · `InCart` | `Idle` |
| `Wishlisted` | Variant (boolean) | `true` · `false` | `false` |
| `Interaction` | Variant | `Default` · `Hover` · `Focus` · `Pressed` | `Default` |
| `Title` | Text | 2-line clamp | — |
| `Brand` | Text | — | — |
| `AgeLabel` | Text | from `AgeRangeIndicator` | — |
| `Price` / `PriceWas` | Text | — | — |
| `DiscountPct` | Text | `−25%` | — |
| `Rating` / `ReviewCount` | Text | `4.6` / `(38)` | — |
| `Qty` | Text | shown only when `CartState=InCart` | `1` |
| `Badge` | Variant | `None` · `New` · `Bestseller` · `Bundle` | `None` |
| `Image` | Instance swap | 1:1 image | placeholder |

### A1.2 Geometry — `Layout=Grid`

| Element | 390 frame | 1440 frame | Notes |
|---|---|---|---|
| Card frame | **173 × 384** fixed both axes | **302 × 552** fixed both axes | Height is **fixed, not hug** — identical for every stock/discount/cart state. This is the zero-CLS guarantee; a 2-line and a 1-line title produce the same card. |
| Auto-layout | Vertical, gap 0, padding 0 | same | Image then content, two children only |
| Image box | **173 × 173** (1:1), `radius/lg` top corners only, `color/bg/subtle` fill behind the image | **302 × 302** | Aspect ratio locked in Figma AND as `aspect-ratio: 1/1` in code. `next/image` with `fill` + `sizes="(max-width:768px) 50vw, 25vw"`. |
| Content frame | Vertical, Fill width, **211** fixed height, padding `spacing/3` all sides, gap `spacing/2` | Vertical, Fill width, **250** fixed height, padding `spacing/4`, gap `spacing/2` | |
| ↳ Meta stack | Vertical, Fill width, **83** fixed, gap `spacing/1` | **95** fixed, gap `spacing/1` | |
| · Brand line | 18 fixed, `caption`, uppercase off | 18 fixed, `caption` | 1 line, ellipsis allowed (brand names are short in both scripts) |
| · Title | **42** fixed (2 × 21), `body-sm`, `-webkit-line-clamp:2` | **52** fixed (2 × 26), `body` | **Strategy (a) fixed-height clamp.** `.lang-bn` uses `body-sm` at line-height 1.72 → 2 × 24 = 48, so the Bangla card's title box is **48** / mobile and **60** / desktop and the card height becomes **390** / **560**. Build both as a `Lang` variant (`en`/`bn`) rather than letting it reflow. |
| · Rating row | 16 fixed, horizontal, gap `spacing/1`: 5 star glyphs @12 + `4.6` + `(38)` in `caption` | 18 fixed, stars @14 | When `ReviewCount=0` the row renders `নতুন পণ্য · রিভিউ নেই` / `New · no reviews yet` in `caption`/`color/text/tertiary` — the row never collapses |
| ↳ Price row | 24 fixed, horizontal, gap `spacing/2`, baseline-aligned: `price` + `price-was` + `−25%` | 28 fixed | |
| ↳ Stock pill | 20 fixed, hug width, padding `spacing/2` horizontal, `radius/full` | 22 fixed | `StockIndicator` instance, `Size=sm` |
| ↳ CTA slot | **36** fixed, Fill width | **44** fixed, Fill width | Holds `Button` (`Idle`) or `QuantityStepper` (`InCart`) — **same 36/44 height, so the swap causes no reflow** |
| Discount badge | Absolute, top `spacing/2` left `spacing/2`, hug × 22, padding `spacing/2` h, `radius/full` | top/left `spacing/3`, 24 tall | |
| Wishlist button | Absolute, top `spacing/2` right `spacing/2`, **36 × 36** visual, **44 × 44** hit area via transparent padding | **40 × 40** visual / 44 hit | |
| Age pill | Absolute, **bottom** `spacing/2` **left** `spacing/2` of the *image box*, hug × 22, `radius/full` | bottom/left `spacing/3`, 24 tall | Overlays the photo's lower-left; photo standard (85% fill, white ground) keeps this area empty |

### A1.3 Token bindings per state

| Target | Default | Hover (desktop only) | Focus-visible | Pressed | `OutOfStock` |
|---|---|---|---|---|---|
| Card fill | `color/bg/surface` | `color/bg/surface` | `color/bg/surface` | `color/bg/surface` | `color/bg/surface` |
| Card border | 1px `color/border/default` | 1px `color/border/strong` | — | 1px `color/border/strong` | 1px `color/border/default` |
| Card elevation | `e1` | `e3`, translateY −2px, 200ms `standard` | `e1` | `e1` | `e1` |
| Card focus ring | — | — | 2px `color/border/focus`, 2px offset | — | — |
| Image opacity | 100% | 100% (image scales 1.03 inside the clipped box — the box never moves) | 100% | 100% | **60%**, plus a `color/bg/surface` 72%-opacity scrim behind the stock pill |
| Brand text | `color/text/tertiary` | `color/text/tertiary` | — | — | `color/text/tertiary` |
| Title text | `color/text/primary` | `color/text/link` | — | — | `color/text/secondary` |
| Price | `color/text/price` | same | — | — | `color/text/secondary` |
| Was-price | `color/text/price-was`, strikethrough | same | — | — | same |
| Discount text | `color/text/discount` | same | — | — | `color/text/tertiary` |
| Discount badge fill | `color/bg/accent` | same | — | — | `color/bg/subtle` |
| Discount badge text | `color/text/on-accent` | same | — | — | `color/text/tertiary` |
| Age pill fill / text | `color/bg/brand-subtle` / `color/text/primary` | same | — | — | same |
| Wishlist icon (off) | `color/text/secondary` on `color/bg/surface` 90% circle, `e1` | `color/text/primary` | 2px `color/border/focus` ring | — | enabled (you may wishlist an OOS toy) |
| Wishlist icon (on) | `color/bg/brand` filled heart | same | same | scale 0.92 then `spring` to 1 | same |
| CTA | `Button/primary` — `color/bg/brand` / `color/text/on-brand` | `brand` darkens via the Button component's own hover token | Button's own ring | — | `Button/outline`, label becomes **`স্টকে এলে জানান` / `Notify me`** — never a disabled dead end |
| Stock pill | see A13 | | | | |

### A1.4 Add-to-cart in place

| Step | Behaviour | Timing | a11y |
|---|---|---|---|
| 1 `Idle` | `Button/primary`, label `কার্টে যোগ করুন` / `Add to cart`, cart icon left | — | `aria-label` includes the product name |
| 2 `Adding` | Same button, `loading` state, `Spinner` replaces the icon, label unchanged, width unchanged | 0–600ms max, optimistic | `aria-busy="true"` |
| 3 `InCart` | Button is **replaced in place** by `QuantityStepper` (`− 1 +`), 36/44 tall, Fill width, `radius/full`, border 1px `color/border/default`, fill `color/bg/brand-subtle`, value `price`-styled. A 160ms `fast` cross-fade; the slot height never changes. | 160ms | `aria-live="polite"`: `কার্টে যোগ হয়েছে` / `Added to cart` |
| 4 Stepper at 1 → `−` | Returns to step 1, removes the line from the cart | 160ms | `aria-live`: `কার্ট থেকে সরানো হয়েছে` / `Removed from cart` |
| 5 Stepper at stock ceiling | `+` disabled, helper caption below the card is **not** added (no reflow). Instead a `Toast`: `স্টকে আর নেই` / `That's all we have in stock` | — | `aria-disabled` on `+` |
| Confetti | A 320ms `spring` burst of 6 small shapes behind the stepper on first add only, `prefers-reduced-motion: reduce` → opacity fade only | 320ms | decorative, `aria-hidden` |

### A1.5 `Layout=List`

Used on wishlist, order detail, search "list view" toggle, and the mobile cart's related rail.

| Element | 390 | 1440 |
|---|---|---|
| Card frame | Fill width × **144** fixed | Fill width × **168** fixed |
| Auto-layout | Horizontal, gap `spacing/3`, padding `spacing/3`, align top | Horizontal, gap `spacing/4`, padding `spacing/4` |
| Image | **120 × 120**, `radius/md` | **136 × 136** |
| Content | Vertical, Fill, gap `spacing/1`: brand · title (**2-line clamp, 42 fixed**) · age pill + stock pill in one horizontal row gap `spacing/2` · price row | same, `body` sizes |
| CTA | Bottom-right, hug × 36, `Button/primary` `Size=sm`, icon-only cart on 390 (44 hit area) / icon + label on 1440 | |
| Bangla | **Strategy (a)** — title box stays 42/48; the age+stock row is hug-width and wraps to a second row inside the same stack if Bangla pushes it past the content width, which is why the card is 144 not 128 |

### A1.6 `Layout=Compact`

Used in every horizontal rail (flash sale, best sellers, new arrivals, shop-by-age, recently viewed, related).

| Element | 390 | 1440 |
|---|---|---|
| Card frame | **148 × 240** fixed | **216 × 322** fixed |
| Image box | 148 × 148 | 216 × 216 |
| Content | padding `spacing/3`, gap `spacing/1`: title (2-line clamp **42**) · price row (22) | padding `spacing/4`, gap `spacing/1`: title (**52**) · price row (26) |
| Omitted vs Grid | brand line, rating row, stock pill, CTA button. The whole card is one tap target. | same |
| Countdown slot | When the rail is `Flash sale`, a 20-tall `CountdownTimer` `Size=xs` is pinned to the image box's bottom edge, Fill width, `color/bg/accent` / `color/text/on-accent` | 22 tall |
| Bangla | **Strategy (a)**, box 48 → card height 246 / 330 |

### A1.7 Responsive behaviour summary

| Width | Columns | Variant | Card width |
|---|---|---|---|
| 320–479 | 2 | Grid | (W − 32 − 12) / 2 → 138 at 320 |
| 480–767 | 2 | Grid | 206 at 480 |
| 768–1023 | 3 | Grid | 232 at 768 (gutter 24, gap 16) |
| 1024–1279 | 4 (3 with sidebar) | Grid | 232 at 1024 |
| 1280–1535 | 4 (3 with sidebar) | Grid | 302 |
| ≥1536 | 5 (4 with sidebar) | Grid | 302, grid centred at 1280 + one extra column of gutter — **cap at 1280, do not widen** |

---

## A2. `ProductCardSkeleton`

| Property | Value |
|---|---|
| Variants | `Layout=Grid|List|Compact` — **exactly the frames of A1, same fixed heights**, so the real card replaces the skeleton with zero shift |
| Fills | All blocks `color/bg/subtle`, `radius/md` (image block `radius/lg` top) |
| Blocks (Grid) | image 173×173 · brand bar 64×12 · title bar 1 = Fill×14 · title bar 2 = 70%×14 · rating bar 96×12 · price bar 88×18 · pill 72×16 · CTA Fill×36 |
| Shimmer | Horizontal linear sweep, `color/bg/surface` at 45% over `color/bg/subtle`, 1200ms linear infinite. `prefers-reduced-motion` → static `color/bg/subtle`, no animation |
| Count | 390: render **4** (2 rows). 1440 grid: **8**. 1440 PLP with sidebar: **9**. Rails: **4** / **6**. |
| a11y | Container `aria-busy="true"` `aria-live="polite"`, children `aria-hidden`. On resolve, announce `২৪টি পণ্য পাওয়া গেছে` / `24 products found` |
| Never | Never used for the LCP hero image or the PDP price — those are server-rendered, not skeletoned |

---

## A3. `CategoryTile`

| Property | Type | Values |
|---|---|---|
| `Size` | Variant | `sm` (home grid mobile) · `md` (home grid desktop) · `lg` (all-categories page hero row) |
| `Theme` | Variant | `Brand` · `Accent` · `Teal` · `Grape` — cycled in a fixed order down the grid so adjacent tiles never repeat. Implemented as `color/bg/brand-subtle` / `color/bg/accent-subtle` and the two further subtle tints; **the saturated `color/bg/brand` is never used as a tile ground** (it would make the home page shout and lose contrast against the icon). |
| `Count` | Text | `৮৪টি পণ্য` / `84 toys` — optional, `caption`, `color/text/secondary` |

| Element | 390 (`sm`) | 1440 (`md`) |
|---|---|---|
| Frame | **173 × 128** fixed; grid 2 columns gap `spacing/3` | **302 × 168** fixed; grid 4 columns gap `spacing/6` |
| Auto-layout | Vertical, padding `spacing/3`, gap `spacing/2`, align centre-centre | padding `spacing/4`, gap `spacing/2` |
| Icon | 48 custom two-tone (brand + accent), centred | 56 |
| Label | `h4`, `color/text/primary`, centre, **2-line box fixed at 42** | `h4`, 2-line box fixed at 46 |
| Count | `caption`, `color/text/tertiary`, 1 line, 16 fixed | 18 |
| Fill | `Theme` subtle token | same |
| Border | 1px transparent → **hover** 1px `color/border/strong` | |
| Radius | `radius/xl` | `radius/xl` |
| Elevation | none → **hover** `e2` + translateY −2px, 200ms `standard` | |
| Focus | 2px `color/border/focus`, offset 2 | |
| Bangla | **Strategy (c)** — English labels are authored across two lines too (`Building /Blocks`), so `বিল্ডিং ব্লক` and `Building Blocks` occupy the same 42px box. Tile height is therefore identical in both locales. |

---

## A4. `MegaMenu` — 3 levels, keyboard navigable, promo slot

**Desktop only** (≥1024). Below that, `MobileNav` + the categories drawer replace it.

### A4.1 Structure

| Level | Where | Count | Token / type |
|---|---|---|---|
| L1 | Horizontal bar, 48 tall, inside the 1280 content column, sits directly under the 72 header | 9 items | `body` 600 weight, `color/text/primary`; padding `spacing/4` h; full bar fill `color/bg/surface`, bottom border 1px `color/border/default` |
| L2 | Panel, 3 columns of group headings | 3–5 per L1 | `overline` uppercase, `color/text/tertiary` |
| L3 | Links beneath each L2 heading | 4–7 per L2 | `body-sm`, `color/text/secondary` → hover `color/text/link` |
| Promo | Right-hand slot inside the panel | 1 | see A4.3 |

### A4.2 Panel geometry (1440)

| Element | Value |
|---|---|
| Panel frame | Width **1280** (aligned to the content column, not full-bleed), height **hug**, min 320, max 480 then internal scroll |
| Panel auto-layout | Horizontal, padding `spacing/8`, gap `spacing/8`, align top |
| Fill / radius / elevation | `color/bg/surface-raised` · bottom corners `radius/xl` · `e4` |
| Link columns | 3 × Fill (each ≈ 285 after gaps), vertical, gap `spacing/6` |
| ↳ L2 group | Vertical, Fill width, gap `spacing/2`; heading 18 tall; links 28 tall each (hit area 28 × full column width — acceptable on pointer, and the mobile drawer supplies 44) |
| Promo slot | **304** fixed width, Fill height |
| Open / close | Open on `mouseenter` after **120ms** intent delay, on `click`, or on `Enter`/`Space`/`ArrowDown` when the L1 item has focus. Close on `mouseleave` after **180ms** grace, on `Escape`, or on focus leaving the panel. Opening animates opacity 0→1 and translateY −4→0 over 200ms `decelerate`; closing is 120ms `fast` opacity only. |
| Backdrop | `color/text/primary` at 8% over the page below the panel, no blur, click-to-close |

### A4.3 Promo slot content (one per L1, authored in the CMS)

Vertical, padding `spacing/5`, gap `spacing/3`, fill `color/bg/brand-subtle`, `radius/lg`.
`overline` eyebrow `color/text/discount` → `h3` headline `color/text/primary` (2-line box, 52 fixed) → 1:1 image 264 × 264 `radius/md` → `Button/primary Size=sm` hug.
Example, L1 = *Building & Construction*: eyebrow `ফ্ল্যাশ সেল` / `FLASH SALE` · headline `লেগো ক্লাসিক ১৫% ছাড়` / `15% off LEGO Classic` · CTA `অফার দেখুন` / `See the offer`.

### A4.4 Keyboard contract (this is the part competitors do not have)

| Key | Behaviour |
|---|---|
| `Tab` | Moves across L1 items only while closed. When a panel is open, `Tab` enters the panel and moves through L3 links in DOM order (column 1 top→bottom, then 2, then 3, then the promo CTA), then exits to the next L1 item. |
| `ArrowRight` / `ArrowLeft` | Move between L1 items; if a panel is open it stays open and swaps content (no close/reopen flash). |
| `ArrowDown` / `Enter` / `Space` on L1 | Opens the panel and focuses the first L3 link. |
| `ArrowDown` / `ArrowUp` inside a column | Moves within that column; wraps at the ends to the same column's other end. |
| `ArrowRight` / `ArrowLeft` inside the panel | Jumps to the same vertical index of the adjacent column; from column 3 `ArrowRight` goes to the promo CTA. |
| `Home` / `End` | First / last L3 link of the panel. |
| `Escape` | Closes the panel, returns focus to its L1 item. |
| ARIA | L1 bar `role="menubar"`, items `role="menuitem" aria-expanded aria-haspopup="true" aria-controls`; panel `role="menu" aria-labelledby`. Focus ring 2px `color/border/focus` on every level. |

### A4.5 Bangla

L1 bar is the pressure point: 9 Bangla labels at 40% more glyphs will not fit 1280. **Resolution: the L1 label set is authored per locale, not translated.** `bn` uses deliberately short labels (`বিল্ডিং`, `গাড়ি`, `পাজল`, `পুতুল`, `শিশু`, `আউটডোর`, `আর্ট`, `সায়েন্স`, `বয়স`) measured to fit 1280 with `spacing/4` padding. If a locale's bar still overflows, the 9th item collapses into a `আরও ▾` / `More ▾` overflow menu — **it never wraps to two rows and never shrinks the font**. L2/L3 are hug-height stacks (**strategy (b)**); panel min-height absorbs the growth.

---

## A5. `MobileNav` — bottom tab bar

| Property | Type | Values |
|---|---|---|
| `Active` | Variant | `Home` · `Categories` · `Search` · `Cart` · `Account` |
| `CartCount` | Text | `0`–`99+` |

| Element | Value (390) |
|---|---|
| Frame | **390 × 56** + `env(safe-area-inset-bottom)` padding below, fixed to viewport bottom, `position: sticky` z-index above content and below modals |
| Auto-layout | Horizontal, 5 × Fill (78 each), padding 0, gap 0 |
| Fill / border / elevation | `color/bg/surface` · top border 1px `color/border/default` · `e3` upward |
| Each item | Vertical, Fill × 56, align centre, gap `spacing/1`, padding `spacing/2` top — **hit area 78 × 56, exceeds 44 × 44** |
| Icon | 24 Lucide, 1.75px stroke |
| Label | `caption` (12), 1 line, 16 fixed, centre |
| Inactive | icon + label `color/text/secondary` |
| Active | icon + label `color/text/primary`; a **3 × 20** `color/bg/brand` pill, `radius/full`, pinned to the item's top edge centre |
| Pressed | item fill `color/bg/subtle`, 120ms `fast` |
| Focus | 2px `color/border/focus` inset ring on the item frame |
| Cart badge | 18 × 18 min (hug for `99+`, max 28 wide), `radius/full`, `color/bg/brand` / `color/text/on-brand`, `caption` 700; absolute, top 4, left 50% + 8. Hidden at 0. Updates with `aria-live="polite"`: `কার্টে ৩টি পণ্য` / `3 items in cart` |
| Labels (bn / en) | `হোম`/`Home` · `ক্যাটাগরি`/`Categories` · `সার্চ`/`Search` · `কার্ট`/`Cart` · `অ্যাকাউন্ট`/`Account` |
| Bangla | **Strategy (a)** — 16px fixed 1-line box, 78px wide per item. `ক্যাটাগরি` measures within 78 at 12px/Anek Bangla. Longer words are not permitted in this component; the label list is fixed and locale-authored. |
| Hide rule | Visible on every storefront route **except** `/checkout` and the image lightbox. On the PDP it stays, and `AddToCartBar` sits **above** it (A16). |

---

## A6. `SearchBar` + suggestion dropdown

### A6.1 Bar

| Property | Type | Values |
|---|---|---|
| `Context` | Variant | `HeaderDesktop` · `HeaderMobile` (icon-only trigger) · `SearchPageMobile` (full-width, auto-focus) |
| `State` | Variant | `Empty` · `Typing` · `Loading` · `HasQuery` |

| Element | 390 (`SearchPageMobile`) | 1440 (`HeaderDesktop`) |
|---|---|---|
| Frame | Fill × **44** | **560 × 44**, centred in the header between logo and actions |
| Fill / border / radius | `color/bg/subtle`, 1px `color/border/default`, `radius/full` | `color/bg/surface`, 1px `color/border/default`, `radius/full` |
| Padding / gap | `spacing/4` h, gap `spacing/3` | same |
| Leading icon | `search` 20, `color/text/tertiary` | same |
| Input | `body`, `color/text/primary`; placeholder `color/text/tertiary` | same |
| Placeholder | `খেলনা, ব্র্যান্ড বা বয়স খুঁজুন` / `Search toys, brands or age` | same |
| Trailing | `State=HasQuery` → `x` clear IconButton 44×44 hit; `State=Loading` → `Spinner` 20 (clear button is **not** removed, it is swapped in the same 44 slot) | same |
| Focus | Border 2px `color/border/focus` (inset so the bar does not grow), fill → `color/bg/surface` | same |
| Behaviour | Debounce **250ms**, minimum **2 characters**, Banglish-tolerant (`গাড়ি` / `gari` / `car` → one result set) | same |

### A6.2 Dropdown

| Element | 390 | 1440 |
|---|---|---|
| Panel | Fill width, anchored under the bar with `spacing/2` offset; max height `calc(100vh - 160px)`, internal scroll | **560** wide, max height **520**, internal scroll |
| Fill / radius / elevation | `color/bg/surface-raised` · `radius/lg` · `e4` | same |
| Auto-layout | Vertical, padding `spacing/2` v, gap `spacing/2` | same |
| Group header | Fill × 28, padding `spacing/4` h, `overline` uppercase `color/text/tertiary`, with the group count on the right in `caption` | same |
| Group order | **Products → Categories → Brands**, then `Recent searches` when the query is empty | same |
| Product row | Fill × **56**, horizontal, padding `spacing/4` h / `spacing/2` v, gap `spacing/3`: image 40 × 40 `radius/sm` · stack {title `body-sm` 1-line ellipsis, age `caption` `color/text/tertiary`} · price `price` `Size=sm` right-aligned | Fill × 60, image 44 |
| Category row | Fill × **44**: icon 20 `color/text/secondary` · label `body-sm` · count `caption` right | same |
| Brand row | Fill × **44**: `tag` icon 20 · label `body-sm` · count `caption` right | same |
| Row states | hover/`aria-selected` fill `color/bg/brand-subtle`; focus is managed by `aria-activedescendant`, not DOM focus, so the input keeps the caret |
| Max rows | 5 products · 3 categories · 3 brands. Footer row, Fill × 48, `color/text/link` `body-sm` 600, centred: `"পাজল" এর সব ফলাফল দেখুন (৩৭)` / `See all 37 results for "puzzle"` |
| Keyboard | `ArrowDown`/`ArrowUp` traverse **all** rows across groups (headers are skipped); `Enter` opens the active row; `Escape` closes the panel and keeps the query; second `Escape` clears the query; `Tab` closes and moves on |
| Empty-query state | `সাম্প্রতিক খোঁজ` / `Recent searches` — up to 4 `Chip`s, each removable, plus a `সব মুছুন` / `Clear all` text button. If no history: `জনপ্রিয় খোঁজ` / `Popular searches` with 6 seeded chips: `লেগো`, `পাজল`, `হট হুইলস`, `২ বছরের খেলনা`, `সাইকেল`, `পুতুল` |
| No-match state | 72-tall row, centred: `caption` `কিছু পাওয়া যায়নি — Enter চাপুন পুরো সার্চের জন্য` / `No quick matches — press Enter for full search`. **Never an empty panel.** |
| Bangla | **Strategy (b)** — rows are fixed height with 1-line ellipsis for titles (acceptable here because the full title is one tap away), but the **footer row and no-match row are hug-height** and allowed to take two lines |

---

## A7. `FilterPanel` — desktop sidebar **and** mobile bottom sheet

**Facet order is fixed: Age → Price → Category → Brand → Gender → Skill → Rating → Availability.** Age first because EduToyZone proved parents use it and nobody else made it a filter; Brand and Gender exist because **0 of 9 competitors have either**. Every facet row shows a live result count. Every selection also renders as a removable `Chip` above the grid (A7.4).

### A7.1 Shared facet anatomy

| Element | Value |
|---|---|
| Section | Vertical, Fill width, hug height, gap `spacing/3`, padding `spacing/4` 0; divider 1px `color/border/default` below (not after the last) |
| Section header | Fill × 24, horizontal, space-between: `h4` `color/text/primary` + `chevron-up/down` 20 `color/text/secondary`. Whole header is the 44-tall accordion button on mobile |
| Expanded by default | Age, Price. Collapsed by default: Category, Brand, Gender, Skill, Rating, Availability |
| Option row | Fill × **40** (mobile **48**), horizontal, gap `spacing/3`, align centre: `Checkbox` 20 · label `body-sm` Fill · count `caption` `color/text/tertiary` right |
| Option states | hover fill `color/bg/subtle`; selected label → `color/text/primary` 600; **zero-count option is disabled**: label + count `color/text/tertiary`, checkbox disabled, `title`/tooltip `এই ফিল্টারের সাথে কোনো পণ্য নেই` / `No products match this with your other filters` — **shown, not hidden**, so the parent can see why |
| Long lists | Brand and Category show 6 rows then `আরও ৯টি দেখুন` / `Show 9 more` text button; above 12 options a 36-tall mini search input appears at the top of the facet |

### A7.2 Facet definitions and controls

| Facet | Control | Options / range |
|---|---|---|
| **Age (months)** | 7 radio-style **multi-select pills**, 2 per row at 390 / 2 per row in the 272 sidebar, hug × 36, `radius/full`, plus an "exact age" `NativeSelect` | `০–১২ মাস`/`0–12 months` · `১–২ বছর`/`1–2 years` (12–24) · `২–৪ বছর` (24–48) · `৪–৬ বছর` (48–72) · `৬–৮ বছর` (72–96) · `৮–১২ বছর` (96–144) · `১২+ বছর` (144+). The `NativeSelect` reads `আমার সন্তানের বয়স` / `My child's age` and sets `age_months=N`, which selects the band **and** sorts by age-fit. |
| **Price (৳)** | 5 checkboxes + a two-input custom range (`৳ থেকে` / `৳ পর্যন্ত`, 40 tall, numeric keypad) | `৳৫০০ এর নিচে` · `৳৫০০–১,০০০` · `৳১,০০০–২,৫০০` · `৳২,৫০০–৫,০০০` · `৳৫,০০০+` |
| **Category** | Checkbox tree, 2 levels, child rows indented `spacing/6` | from A3 / Part C |
| **Brand** | Checkbox list, alphabetical, mini-search above | LEGO · Fisher-Price · Hot Wheels · Hape · Mideer · Bruder · Barbie · Play-Doh · Crayola · Clementoni · Little Tikes · Melissa & Doug · Winfun · Huanger · Intex · Rubik's |
| **Gender** | 3 checkboxes | `সবার জন্য`/`For everyone` · `ছেলে`/`Boys` · `মেয়ে`/`Girls` — "For everyone" is first and pre-labelled, because the point is browsing without gendering |
| **Skill** | Checkbox list, 8 shown of 16 | `ফাইন মোটর`/`Fine motor` · `গ্রস মোটর`/`Gross motor` · `ভাষা`/`Language` · `গণিত`/`Early maths` · `কল্পনা`/`Imagination` · `সমস্যা সমাধান`/`Problem solving` · `সামাজিক দক্ষতা`/`Social skills` · `সংবেদী`/`Sensory` |
| **Rating** | 4 radio rows with star glyphs | `৪★ ও তার বেশি` … `১★ ও তার বেশি` |
| **Availability** | 2 checkboxes | `স্টকে আছে`/`In stock` · `ফ্রি ডেলিভারি`/`Free delivery` |

### A7.3 Desktop sidebar (1440)

| Element | Value |
|---|---|
| Frame | **272** fixed width, hug height, `position: sticky; top: 136` (header 72 + menu bar 48 + `spacing/4`), max-height `calc(100vh - 152px)`, internal scroll with a 1px `color/border/default` top/bottom fade |
| Fill / radius / border | `color/bg/surface` · `radius/lg` · 1px `color/border/default` |
| Padding | `spacing/5` |
| Header row | Fill × 32: `h3` `ফিল্টার` / `Filters` + `সব মুছুন` / `Clear all` text button `color/text/link` `body-sm`, right. The Clear-all button is **disabled, not hidden**, when nothing is selected |
| Apply | **None.** Desktop filters apply immediately and push a URL (A7.5). |
| Count feedback | Under the header, a 24-tall `aria-live="polite"` line: `২৪টি পণ্য` / `24 products` |

### A7.4 Mobile bottom sheet (390)

| Element | Value |
|---|---|
| Trigger | Sticky bar under the PLP header, **390 × 56**, `color/bg/surface`, bottom border 1px `color/border/default`, padding `spacing/4` h, horizontal gap `spacing/3`: `Button/outline Size=md` `ফিল্টার (২)` / `Filters (2)` with a `sliders` icon (the count badge is `color/bg/brand`/`color/text/on-brand`, 18 round) + `SortDropdown` trigger, both Fill |
| Sheet frame | **390 × 88vh** max, pinned to bottom, `radius/2xl` top corners only, `color/bg/surface`, `e4`, backdrop `color/text/primary` @ 40% |
| Entry / exit | translateY 100%→0 over 320ms `decelerate`; exit 200ms `standard`. Drag handle 36 × 4 `color/border/strong` `radius/full`, centred, `spacing/3` from top. Swipe-down past 96px or on the handle closes. `prefers-reduced-motion` → opacity only |
| Sheet header | Fill × 56, padding `spacing/4`, space-between: `h3` `ফিল্টার` / `Filters` · `সব মুছুন` / `Clear all` · `x` IconButton 44 |
| Sheet body | Vertical scroll, Fill, padding `spacing/4` h, the A7.1/A7.2 sections with **48**-tall rows |
| Sheet footer | **Sticky inside the sheet**, Fill × 80, padding `spacing/4`, top border 1px `color/border/default`, `color/bg/surface`: a single `Button/primary Size=lg` Fill, label **`২৪টি পণ্য দেখুন` / `Show 24 products`** — the count is live and updates as facets change, which is the entire reason the mobile sheet uses deferred apply while the desktop sidebar does not |
| Deferred apply | Mobile selections are staged; the grid updates only on `Show N products`. `x` / backdrop / swipe **discards** staged changes and shows a `Toast`: `পরিবর্তন বাতিল হয়েছে` / `Changes discarded` |
| Zero staged results | Footer button becomes `Button/outline`, disabled, label `এই ফিল্টারে কিছু নেই` / `No toys match`, and a 40-tall inline `Alert/info` appears above the footer: `ফিল্টার কিছু কমিয়ে দেখুন` / `Try removing a filter` with a `বয়সের ফিল্টার রাখুন, বাকি মুছুন` / `Keep age, clear the rest` action |

### A7.5 Active-filter chips + URL addressability

**This is the gap we take from EduToyZone.** Its filters are client-side with no `pushState`, so a filtered view cannot be shared, bookmarked, sent on WhatsApp or indexed. Ours:

| Rule | Implementation |
|---|---|
| Every facet change writes the URL | `/category/building-blocks?age=24-48&brand=lego,hape&price=1000-2500&gender=all&skill=fine-motor&rating=4&sort=popular&page=2` |
| Canonical + indexable | Single-facet and age-facet URLs are canonical and in the sitemap (`?age=24-48` is a real landing page with its own `h1` and intro copy). Multi-facet combinations carry `rel=canonical` to the single-facet parent and `robots: noindex, follow`. |
| Back/forward | `history.pushState` per committed change (debounced 400ms on the desktop sidebar), so browser back steps through filter states |
| Server-rendered | The filtered grid is rendered on the server from the URL on first load — no flash of unfiltered content |
| Chip row | Above the grid: horizontal wrap, gap `spacing/2`, row hug height, `spacing/4` bottom margin. Each `Chip`: hug × 32, `radius/full`, `color/bg/brand-subtle` fill, `body-sm` `color/text/primary`, `x` 16 trailing, **44 × 32 minimum hit area via horizontal padding `spacing/3`**. Label format `বয়স: ২–৪ বছর` / `Age: 2–4 years`. |
| Chip row overflow | Wraps to a maximum of 2 rows, then a `+৩টি` / `+3 more` chip expands it. Last chip is always `সব মুছুন` / `Clear all`, `Chip/ghost` with `color/text/link`. |
| Bangla | **Strategy (b)** — the chip row is hug-height and wraps; nothing below it is absolutely positioned |

---

## A8. `SortDropdown`

| Property | Type | Values |
|---|---|---|
| `Context` | Variant | `Mobile` (half of the sticky filter bar, opens a bottom sheet) · `Desktop` (inline `Popover`) |
| `Value` | Variant | `Relevance` · `Popular` · `PriceAsc` · `PriceDesc` · `Newest` · `Discount` · `Rating` · `AgeFit` |

| Element | 390 | 1440 |
|---|---|---|
| Trigger | `Button/outline Size=md`, Fill, 44 tall, label `সাজান: জনপ্রিয়` / `Sort: Popular`, `chevron-down` 20 trailing | Hug × 40, `radius/full`, 1px `color/border/default`, `color/bg/surface`, label same, padding `spacing/4` |
| Panel | Bottom sheet, Fill × hug (max 70vh), `radius/2xl` top, `color/bg/surface-raised`, `e4`; header 56 `সাজান` / `Sort by` + `x` 44 | `Popover` 248 wide, hug height, right-aligned to the trigger, `radius/lg`, `color/bg/surface-raised`, `e3`, `spacing/2` offset |
| Option row | Fill × **52**, padding `spacing/4`, horizontal: `Radio` 20 + label `body` Fill + `check` 20 when selected | Fill × **40**, padding `spacing/3` h, same minus the radio (check only) |
| Selected | label 600 `color/text/primary`, row fill `color/bg/brand-subtle` | same |
| Options (bn / en) | `প্রাসঙ্গিকতা`/`Relevance` (search only) · `জনপ্রিয়`/`Popular` (default on PLP) · `দাম: কম থেকে বেশি`/`Price: low to high` · `দাম: বেশি থেকে কম`/`Price: high to low` · `নতুন আগে`/`Newest first` · `সবচেয়ে বেশি ছাড়`/`Biggest discount` · `রেটিং`/`Top rated` · `বয়সের সাথে মিল`/`Best age match` (only present when an age facet or `age_months` is set) |
| URL | Writes `?sort=popular`; `sort` is part of the canonical rule in A7.5 (sorted views are `noindex, follow`) |
| Behaviour | Selecting applies immediately on **both** breakpoints and closes the panel. 8 options × `body` fits 70vh without scroll at 390. |
| Bangla | **Strategy (b)** — rows are 52/40 tall with a 1-line label; `দাম: কম থেকে বেশি` fits 248px at `body`. The *trigger* label truncates the value, never the word `সাজান:` — on 390 the trigger reads `সাজান` alone if the value would wrap. |

---

## A9. `ProductGallery`

| Property | Type | Values |
|---|---|---|
| `Count` | Text | number of media items |
| `HasVideo` | Variant boolean | video occupies slot 2 when true |
| `Layout` | Variant | `MobileSwipe` · `DesktopRail` |

### A9.1 390 — `MobileSwipe`

| Element | Value |
|---|---|
| Stage | **358 × 358** fixed (1:1), `radius/lg`, `color/bg/subtle` ground, overflow hidden. **Aspect ratio reserved in markup — this is one of the two zero-CLS guarantees on the PDP.** |
| Paging | Horizontal scroll-snap, one slide per viewport, `scroll-snap-type: x mandatory`. Pinch-zoom enabled (never `user-scalable=no`; Kids Zone BD blocks it, which is both a bug and an a11y failure). Double-tap zooms 2×. |
| Dots | Absolute, bottom `spacing/3`, centred, horizontal gap `spacing/2`; 6 × 6 `color/border/strong` → active **16 × 6** `radius/full` `color/bg/brand`. Max 6 dots, then `3/9` counter pill instead |
| Counter pill | Absolute top-right `spacing/3`, hug × 24, `radius/full`, `color/bg/surface` @ 88%, `caption` `color/text/secondary`: `৩/৯` |
| Video slide | Poster frame + 56 centred play button, `color/bg/surface` @ 90% circle, `e2`. Never autoplays. Duration pill bottom-left `০:৪২` |
| Badges | Discount badge top-left (A1 tokens), `Bestseller`/`New` badge below it, gap `spacing/2`; wishlist top-right **under** the counter pill (gap `spacing/2`) |
| Thumbnail rail | **Omitted at 390** — dots + swipe only. Scrolling a thumb rail with one thumb while swiping a stage is the worst mobile gallery pattern. |
| Lightbox | Tap the stage → full-screen `Drawer/fullscreen`: `color/bg/canvas`, close `x` 44 top-right, pinch-zoom, swipe between slides, swipe-down to dismiss, counter centred in a 56 top bar |
| a11y | `role="group" aria-roledescription="carousel"`, each slide `aria-label="৩ এর ৯"`; prev/next buttons exist but are visually hidden at 390 and reachable by keyboard; alt text auto-composed `{product} — {variant} — image {n}` |

### A9.2 1440 — `DesktopRail`

| Element | Value |
|---|---|
| Gallery column | **608** fixed width inside the PDP's two-column layout |
| Stage | **608 × 608** (1:1), `radius/lg`, 1px `color/border/default` |
| Thumb rail | Below the stage, `spacing/4` gap: horizontal, gap `spacing/3`, 5 visible thumbs of **96 × 96** `radius/md`; inactive 1px `color/border/default`, active 2px `color/bg/brand`; hover 1px `color/border/strong`. Overflow scrolls with 32 chevron buttons at each end |
| Hover zoom | Hovering the stage shows a 2× magnifier **inside the stage bounds** (not a side panel — a side panel overlaps the price column and shifts nothing but confuses). Cursor `zoom-in`. Click opens the lightbox at 1:1 native resolution |
| Keyboard | `ArrowLeft`/`ArrowRight` change slide when the stage has focus; thumbs are a `tablist` with `role="tab"` |
| LCP | The slot-1 image is the **PDP LCP element**: `priority`, AVIF/WebP, explicit 608 × 608, preloaded. No other image on the PDP is `priority`. |

---

## A10. `VariantSelector`

**Rule from UI-UX-DESIGN-SPEC §6.2, non-negotiable: unavailable combinations are disabled with a visible reason, never hidden.** Hiding them makes a parent think the shop is smaller than it is; EduToyZone shows per-variant stock and that is already the market's best — a visible *reason* beats it.

| Property | Type | Values |
|---|---|---|
| `AxisType` | Variant | `Colour` (swatches) · `Size` (pills) · `Set` (pills, e.g. "90 pcs / 484 pcs") |
| `OptionState` | Variant | `Available` · `Selected` · `Unavailable` · `LowStock` · `Disabled-by-other-axis` |

### A10.1 Layout

| Element | 390 | 1440 |
|---|---|---|
| Axis block | Vertical, Fill width, hug, gap `spacing/3`; `spacing/5` between axes | same, gap `spacing/3` |
| Axis label row | Fill × 24, horizontal, gap `spacing/2`: `h4` `রঙ` / `Colour` + `color/text/secondary` `body-sm` selected value + (right) `সাইজ গাইড` / `Size guide` text button where applicable | same |
| Colour swatch | **40 × 40** visual, `radius/full`, inner 32 colour disc, 4px ring gap; **44 × 44** hit area | 40 / 44 |
| Size / Set pill | Hug × **44**, min-width 56, padding `spacing/4` h, `radius/full`, `body` 600 | Hug × 40, min-width 64 |
| Row | Horizontal wrap, gap `spacing/3`, hug height — **strategy (b)**, wraps freely |

### A10.2 Token bindings

| State | Swatch | Pill |
|---|---|---|
| `Available` | ring 1px `color/border/default` | 1px `color/border/default`, `color/bg/surface`, `color/text/primary` |
| Hover | ring 1px `color/border/strong` | 1px `color/border/strong` |
| `Selected` | ring **2px** `color/bg/brand` + 2px white gap inside, `check` 16 in `color/text/on-brand` on the disc if the disc is dark | 2px `color/bg/brand`, fill `color/bg/brand-subtle`, text `color/text/primary` 700 |
| Focus-visible | additional 2px `color/border/focus` at 2px offset (outside the selection ring, so both are visible at once) | same |
| `LowStock` | normal + a 14-tall `caption` `color/status/low-stock` line under the row: `লাল: মাত্র ২টি বাকি` / `Red: only 2 left` | same, caption under the row |
| `Unavailable` (that option is sold out in every combination) | disc at 40% opacity, a 1px `color/status/out-of-stock` diagonal strike across the disc, ring 1px `color/border/default` dashed | text `color/text/tertiary`, fill `color/bg/subtle`, 1px dashed `color/border/default`, **strikethrough label** |
| `Disabled-by-other-axis` (valid option, impossible with the current other-axis choice) | same visual as `Unavailable` | same |

### A10.3 The visible reason (the differentiator)

Every non-available option is **still focusable and still announced** (`aria-disabled="true"`, not `disabled`, so keyboard users reach it) and carries a reason in three places:

1. **Inline reason line** — a **20-tall, always-present** row beneath each axis. Reserved height even when empty, so selecting an unavailable option cannot reflow the price above it. `caption`, `color/text/secondary`.
2. **Tooltip on hover/focus** — same string, `Tooltip` component, `color/bg/surface-raised`, `e2`.
3. **`aria-describedby`** — the reason node is referenced by the option.

| Case | Reason string (bn / en) |
|---|---|
| Option sold out everywhere | `লাল রঙ এখন স্টকে নেই` / `Red is out of stock right now` |
| Combination impossible | `লাল রঙে ৪৮৪ পিসের সেট নেই — ৯০ পিস বেছে নিন` / `Red doesn't come in the 484-piece set — pick 90 pieces` |
| Pre-order | `নীল ১৫ নভেম্বর স্টকে আসবে` / `Blue arrives 15 November` |
| Nothing wrong | *(empty, height reserved)* |

Tapping an unavailable option does **not** change the selection. It shows a 48-tall inline `Alert/info` below the axis (inside the reserved stack, pushing only the content *below* it) with the reason and a `স্টকে এলে জানান` / `Notify me` text button that opens the phone-capture sheet. **Never a dead end, never a silent no-op.**

### A10.4 Bangla

**Strategy (b).** Pills are hug-width and the row wraps; the inline reason line is one reserved 20px line in English and becomes **two reserved lines (40)** in `bn` — authored as a `Lang` variant so the PDP's reserved height is correct per locale before any interaction.

---

## A11. `AgeRangeIndicator`

| Property | Type | Values |
|---|---|---|
| `Size` | Variant | `xs` (card overlay, 22 tall) · `sm` (PDP inline, 28) · `md` (PDP age block, 44) |
| `Tone` | Variant | `Neutral` (`color/bg/surface` @ 90% over a photo) · `Brand` (`color/bg/brand-subtle`) |
| `Months` | Text | source of truth; the label is **derived**, never typed |

| Element | Value |
|---|---|
| Frame | Hug × 22/28/44, horizontal, gap `spacing/2`, padding `spacing/2`/`spacing/3`/`spacing/4` h, `radius/full` |
| Icon | `baby` (0–12m) · `blocks` (12–48m) · `smile` (48–96m) · `graduation-cap` (96m+), 14/16/20 |
| Label | `caption`/`caption`/`body-sm` 600, `color/text/primary` |
| Derivation rule (single source of truth — the thing EduToyZone gets wrong with a self-contradictory age badge) | `<12` → `০–{n} মাস` / `0–{n} months`; `12–23` with span <12 → `{n} মাস+` / `{n} months+`; else `{a/12}–{b/12} বছর` / `{a/12}–{b/12} years` with `.5` rendered as `½`; open-ended top → `{a/12}+ বছর` / `{a/12}+ years`. One function, used by card, PDP, filter chip and schema. |
| `md` variant (PDP age block) | Fill width × 44, `color/bg/brand-subtle`, `radius/md`, horizontal gap `spacing/3`: icon 20 · `body` 600 `২–৪ বছরের জন্য` / `For ages 2–4` · right-aligned text button `বয়স গাইড` / `Age guide` opening the age-guide modal |
| Safety line | When `Months` lower bound < 36, a second 20-tall row inside the `md` variant: `caption` `color/status/low-stock` with a `triangle-alert` 14: `৩ বছরের নিচে ছোট অংশ — বড়দের নজরদারি দরকার` / `Small parts — not for under 3 without supervision`. **No competitor presents choking-hazard information systematically; this row is in the component, not the description.** |
| Bangla | **Strategy (b)** — `md` is hug-height; the safety line is authored to wrap to 2 lines in `bn` (40 tall) and the block hugs to 84 |

---

## A12. `DeliveryEstimator` — the second differentiator

**Why.** Audit finding: Toyshop hides the charge behind "Enter your address to view shipping options"; Toy House BD computes none at all (order total = subtotal); Kids Republic publishes nothing; SK Toy states three different free-delivery thresholds; Krystal's policy page and checkout contradict each other; EduToyZone states its threshold three ways. **Six of eight audited competitors hide or omit the delivery charge and one computes none at all.** This component puts the real charge and ETA on the **product page, before the cart**, from the same code path checkout uses, so the two cannot disagree.

### A12.1 Properties

| Property | Type | Values |
|---|---|---|
| `State` | Variant | `Empty` · `Loading` · `Selected` · `NotServiced` · `Error` |
| `Zone` | Variant | `DhakaMetro` · `DhakaOuter` · `Outside` |
| `FreeDeliveryMet` | Variant boolean | derived from cart subtotal + this item |
| `District` / `Upazila` | Text | — |

### A12.2 Geometry

| Element | 390 | 1440 |
|---|---|---|
| Frame | Fill (358) × **hug**, min-height **132** reserved in all states so the sticky `AddToCartBar` and the trust strip below never jump | **568** (the PDP detail column) × hug, min-height 140 |
| Auto-layout | Vertical, padding `spacing/4`, gap `spacing/3` | padding `spacing/5`, gap `spacing/3` |
| Fill / radius / border | `color/bg/surface` · `radius/lg` · **1px `color/border/default`** (not elevated — this is a utility, not a promotion) | same |
| Header row | Fill × 24, horizontal, gap `spacing/2`: `truck` 20 `color/text/secondary` + `h4` `ডেলিভারি চার্জ ও সময়` / `Delivery charge & time` | same |
| Selector | `NativeSelect` Fill × **44**, `radius/md`, 1px `color/border/default`, `body` — **native `<select>` on mobile deliberately**; a custom combobox on mid-range Android is worse. 64 districts, grouped `ঢাকা বিভাগ` / `Dhaka Division` first. | A searchable `Select` 280 wide + `Button/secondary Size=md` hug `দেখুন` / `Check`, horizontal gap `spacing/3` |
| Second selector | Appears **only** after a district with sub-zones is chosen (Dhaka, Gazipur, Narayanganj, Chattogram): `NativeSelect` Fill × 44 for upazila/subcity. It is appended inside the same vertical stack → **strategy (b)**, the block grows downward, nothing above it moves |
| Result block | Fill × hug, vertical gap `spacing/2`, padding `spacing/3`, `radius/md`, fill `color/bg/subtle` | same |

### A12.3 `State=Empty` (first render, nothing chosen)

| Row | Content |
|---|---|
| Header | `truck` + `ডেলিভারি চার্জ ও সময়` / `Delivery charge & time` |
| Helper | `body-sm` `color/text/secondary`, 2-line reserved box (40): `জেলা বেছে নিন — কার্টে যাওয়ার আগেই সঠিক চার্জ আর সময় দেখুন।` / `Pick your district — see the exact charge and time before you reach the cart.` |
| Selector | `NativeSelect`, placeholder `আপনার জেলা নির্বাচন করুন` / `Select your district` |
| Prefill | If a district is in `localStorage` from a previous visit or a saved address, the component **renders in `Selected` server-side** and never shows `Empty`. A `পরিবর্তন করুন` / `Change` text button sits in the header row, right-aligned. |
| Published fallback | Below the selector, a 20-tall `caption` `color/text/tertiary` always visible even in `Empty`: `ঢাকায় ৳৭০ · ঢাকার বাইরে ৳১৩০ · ৳৩,০০০+ অর্ডারে ফ্রি` / `৳70 in Dhaka · ৳130 outside · free over ৳3,000`. **A parent who will not use the selector still learns the charge.** |
| Min height | 132 — identical to `Selected`, so selecting causes no jump above the fold |

### A12.4 `State=Selected`

| Row | Content | Tokens |
|---|---|---|
| 1 Charge | `price` `৳৭০` + `body-sm` `color/text/secondary` `ডেলিভারি চার্জ` / `delivery charge`. If `FreeDeliveryMet`: `price` `ফ্রি` / `Free` in `color/status/in-stock`, with `৳৭০` struck in `price-was` beside it | `color/text/price` |
| 2 ETA | `body` `color/text/primary`: `১০ নভেম্বর – ১১ নভেম্বর এর মধ্যে পৌঁছাবে` / `Arrives 10 Nov – 11 Nov` + `caption` `color/text/secondary` `(১–২ কর্মদিবস)` / `(1–2 working days)` | — |
| 3 Courier | `caption` `color/text/tertiary`: `কুরিয়ার: স্টেডফাস্ট` / `Courier: Steadfast`. **7 of 9 competitors never name a courier.** | — |
| 4 Free-delivery nudge | Shown only when not met: `ProgressBar` Fill × 6 `radius/full`, `color/bg/brand` on `color/bg/subtle`, + `caption`: `আরও ৳৫৫০ কিনলে ডেলিভারি ফ্রি` / `Add ৳550 more for free delivery` | `color/text/discount` |
| 5 COD advance | Shown only when zone = `Outside` and item price > ৳5,000: `caption` `color/status/low-stock` with `info` 14: `ঢাকার বাইরে ৳৫,০০০+ অর্ডারে ৳২০০ অগ্রিম লাগবে` / `Orders over ৳5,000 outside Dhaka need a ৳200 advance`. **Stated here, never revealed at checkout.** | — |
| Zone matrix | `DhakaMetro` ৳70, 1–2 working days · `DhakaOuter` (Savar, Keraniganj, Dohar, Nawabganj, Gazipur, Narayanganj, Narsingdi, Munshiganj, Manikganj) ৳100, 2–3 days · `Outside` ৳130, 3–4 days · free delivery over **৳3,000** in every zone. **One threshold, one table, one code path, rendered on PDP, cart, checkout, the policy page and the delivery-checker page.** | — |
| ETA computation | Cut-off **5:00 pm Asia/Dhaka**; after cut-off add 1 day. Fridays and published holidays are skipped. Dates are rendered as absolute dates, not "2–3 days", because a date is checkable. |

### A12.5 `State=NotServiced` — "we don't deliver there"

| Row | Content | Tokens |
|---|---|---|
| 1 | `alert-circle` 20 + `body` 600: `এই উপজেলায় আমরা এখনো ডেলিভারি দিই না` / `We don't deliver to this upazila yet` | `color/status/out-of-stock` icon, `color/text/primary` text, block fill `color/bg/subtle` (**not** a danger red — it is not the parent's error) |
| 2 | `body-sm` `color/text/secondary`: `{উপজেলা} এখনো আমাদের কুরিয়ার নেটওয়ার্কে নেই।` / `{Upazila} isn't on our courier network yet.` | |
| 3 Option A | `Button/secondary Size=md` Fill: `নিকটস্থ পিকআপ পয়েন্ট দেখুন` / `See the nearest pickup point` → names the serviced upazila and its charge: `{নিকটস্থ উপজেলা} — ৳১৩০, ৩–৪ দিন` | |
| 4 Option B | `Button/outline Size=md` Fill: `চালু হলে জানান` / `Tell me when you deliver here` → 40-tall phone input + `জানান` / `Notify me` in a 96-tall inline block (strategy (b), grows downward) | |
| 5 Option C | Text button `color/text/link`: `হোয়াটসঅ্যাপে কথা বলুন` / `Talk to us on WhatsApp` → opens `WhatsAppFab`'s thread pre-filled with the product name and district | |
| Rule | The add-to-cart button is **not disabled**. A parent may still order to a serviced address. The estimator never blocks the purchase. | |
| Current non-serviced set (seed data) | Rangamati: Bilaichhari, Juraichhari, Barkal · Bandarban: Thanchi, Remakri · Khagrachhari: Lakshmichhari · Cox's Bazar: St. Martin's union of Teknaf · Bhola: Char Fasson's offshore chars · Patuakhali: Rangabali | |

### A12.6 `Loading` and `Error`

| State | Behaviour |
|---|---|
| `Loading` | The **result block keeps its exact height** and shows three `Skeleton` bars (120×18, 200×16, 140×12) in `color/bg/subtle`. Max 400ms; the zone table is bundled client-side so the common path is synchronous and `Loading` is only reached when the live courier-rate call is used. |
| `Error` | `Alert/warning` inline, 64 tall: `চার্জ আনতে সমস্যা হয়েছে — প্রকাশিত হার দেখানো হচ্ছে` / `Couldn't fetch the live rate — showing our published rate`, then renders `Selected` from the static zone table. **It never shows nothing and never shows a spinner forever.** A `আবার চেষ্টা করুন` / `Try again` text button sits in the alert. |

### A12.7 Where else it appears

| Surface | Variant |
|---|---|
| PDP, directly above `Add to cart` (§6.2 order) | Full, `State` as above |
| Cart summary | Read-only row, no selector, `পরিবর্তন করুন` / `Change` text button opening a 280-tall bottom sheet with the same selectors |
| Checkout section 2 | Read-only, driven by the address cascade; `aria-live` announces any change. **The number is identical to the PDP's for the same address — same function, same table.** |
| `/delivery-charge` standalone page | Full component centred at 480 wide + the whole zone table below it |

---

## A13. `StockIndicator`

| Property | Type | Values |
|---|---|---|
| `State` | Variant | `InStock` · `LowStock` · `OutOfStock` · `PreOrder` · `BackInStockSoon` |
| `Size` | Variant | `sm` (card, 20/22 tall) · `md` (PDP, 28) |
| `Count` | Text | shown only in `LowStock` |

| State | Dot | Label (bn / en) | Fill | Text |
|---|---|---|---|---|
| `InStock` | 8 round `color/status/in-stock` | `স্টকে আছে` / `In stock` | `color/bg/subtle` | `color/text/secondary` |
| `LowStock` | 8 round `color/status/low-stock` | `মাত্র ৩টি বাকি` / `Only 3 left` | `color/bg/accent-subtle` | `color/text/primary` |
| `OutOfStock` | 8 round `color/status/out-of-stock` | `স্টকে নেই` / `Out of stock` | `color/bg/subtle` | `color/text/tertiary` |
| `PreOrder` | 8 round `color/status/low-stock` | `প্রি-অর্ডার · ১৫ নভেম্বর` / `Pre-order · 15 Nov` | `color/bg/accent-subtle` | `color/text/primary` |
| `BackInStockSoon` | 8 round `color/status/out-of-stock` | `শীঘ্রই আসছে` / `Back soon` | `color/bg/subtle` | `color/text/tertiary` |

| Element | Value |
|---|---|
| Frame | Hug × 20/22/28, horizontal, gap `spacing/2`, padding `spacing/2` (sm) / `spacing/3` (md) h, `radius/full` |
| Never colour-alone | Dot **and** text always. Colour is never the only carrier (WCAG 1.4.1) |
| `LowStock` threshold | ≤ 5 units. The exact count is shown — it is checkable, and a wrong count is worse than none. `aria-live="polite"` on change |
| `md` extras | PDP adds a `caption` `color/text/secondary` line below: `আজ বিকেল ৫টার আগে অর্ডার করলে আজই পাঠানো হবে` / `Order before 5 pm today and it ships today` (only when `InStock`/`LowStock` and before cut-off) |
| Bangla | **Strategy (a)** 1-line fixed; all five labels are authored short enough to fit 173px minus the card padding |

---

## A14. `TrustStrip`

> **Home USP update (owner: "USP section should be more premium", 2026-11-11).** Two new layouts for the Home page only: `Feature2x2` (mobile) and `FeatureRow4` (desktop). Each promise is a white card (radius/sm, soft shadow) with a tilted pastel icon tile (filled Phosphor icons: `icon/money-fill`, `icon/arrow-counter-clockwise-fill`, `icon/shield-check-fill`, `icon/phone-fill`), a Fredoka title and a one-line subtitle. The Home band uses `Gradient/Bloom-sky`, an overline "OUR PROMISE" (text/discount) and the heading "Why parents shop with us". PDP and checkout keep the existing quiet layouts.

| Property | Type | Values |
|---|---|---|
| `Layout` | Variant | `Grid2x2` (390 PDP) · `Row4` (1440 PDP, 390 checkout as a 2×2) · `Compact` (checkout, icons + short labels, 1 row) |
| `Theme` | Variant | `Subtle` (PDP) · `Plain` (checkout — no fill, just a 1px top border; **nothing playful in checkout**) |

| Element | 390 `Grid2x2` | 1440 `Row4` |
|---|---|---|
| Frame | Fill × **136** fixed, `radius/lg`, `color/bg/subtle`, padding `spacing/4` | **568** × 96, `radius/lg`, `color/bg/subtle`, padding `spacing/5` |
| Auto-layout | Wrap, 2 columns gap `spacing/4` h / `spacing/4` v, each cell Fill × 52 | Horizontal, 4 × Fill, gap `spacing/5` |
| Cell | Horizontal, gap `spacing/3`, align centre: icon 24 `color/text/secondary` + stack {`body-sm` 600 `color/text/primary`, `caption` `color/text/tertiary`} | Vertical, gap `spacing/2`, align start |
| Items | `banknote` **`ক্যাশ অন ডেলিভারি`/`Cash on delivery`** · `rotate-ccw` **`৭ দিনে রিটার্ন`/`7-day return`** · `shield-check` **`১০০% অরিজিনাল`/`100% authentic`** · `phone` **`০৯৬১০-১২৩৪৫৬`/`Call 09610-123456`** | same |
| Sub-captions | `সারা দেশে` / `Nationwide` · `অক্ষত প্যাকেট হলে` / `If unopened` · `ব্র্যান্ড ওয়ারেন্টি` / `Brand warranty` · `সকাল ৯টা–রাত ৯টা` / `9 am – 9 pm` | same |
| Phone rule | A real, rendered number. **Krystal prints `xxxx-xxxxx` as its hotline on a live page** — the number is a data field with a non-null constraint and is tested in CI. |
| `Plain` theme | No fill, no radius, 1px `color/border/default` top border, padding `spacing/4` 0, icons `color/text/tertiary`, labels `body-sm` `color/text/secondary` |
| Bangla | **Strategy (c)** — labels authored short in both locales; cell height 52 fixed holds a 2-line Bangla label at `body-sm` 1.72 |

---

## A15. `CountdownTimer`

| Property | Type | Values |
|---|---|---|
| `Size` | Variant | `xs` (rail card overlay, 20/22) · `sm` (section header, 32) · `md` (flash-sale hero / PDP, 48) |
| `Urgency` | Variant | `Normal` (>1h) · `Urgent` (<1h) |
| `Ended` | Variant boolean | |

| Element | Value |
|---|---|
| Frame | Hug × 20/32/48, horizontal, gap `spacing/1`/`spacing/2`, padding `spacing/2`/`spacing/3`/`spacing/4`, `radius/full` (`xs`/`sm`) or `radius/md` (`md`) |
| Fill / text | `color/bg/accent` / `color/text/on-accent` — **never white text on accent** (1.9:1). `Urgent` swaps to `color/bg/brand` / `color/text/on-brand` |
| Segments | `md`: four 40 × 40 `radius/sm` `color/bg/surface` @ 20% tiles with `price` digits and a `caption` unit under each (`দিন`/`d` `ঘণ্টা`/`h` `মিনিট`/`m` `সেকেন্ড`/`s`), separated by a `:` in `color/text/on-accent` @ 60%. `sm`/`xs`: single `caption`/`body-sm` 700 run `০২:১৪:০৯` with a `zap` 14 leading |
| Tabular | `font-variant-numeric: tabular-nums` mandatory — the width must not twitch each second |
| Reserved width | Fixed per variant (`xs` 84, `sm` 132, `md` 288) so a 2→1 digit change cannot reflow the card |
| Ticking | 1s interval, `aria-live="off"` on the digits (announcing every second is hostile). A single polite announcement at 60m, 10m and 1m remaining: `ফ্ল্যাশ সেল শেষ হতে ১০ মিনিট` / `10 minutes left in the flash sale` |
| `Ended` | Digits replaced by `সেল শেষ` / `Sale ended`, fill `color/bg/subtle`, text `color/text/tertiary`. The rail then re-renders without the countdown rather than showing a dead timer; a `পরের সেল দেখুন` / `See the next sale` text button appears in the section header |
| Reduced motion | No pulse animation in `Urgent` (default is a 1.0→1.04 scale pulse every 2s on the leading `zap` icon only) |
| Server truth | End time is a server-rendered ISO timestamp with timezone; the client only counts down from it. **0 of 9 competitors run a countdown at all — Baby Bazar has the capability and disabled it.** |

---

## A16. `AddToCartBar` — sticky mobile

| Property | Type | Values |
|---|---|---|
| `State` | Variant | `Idle` · `Adding` · `InCart` · `OutOfStock` · `VariantUnchosen` |
| `Visible` | Variant boolean | appears once the inline add-to-cart scrolls out of view |

| Element | Value (390 only; absent at ≥1024, where the detail column is sticky instead) |
|---|---|
| Frame | **390 × 72** + `env(safe-area-inset-bottom)`, fixed to viewport bottom, **stacked directly above `MobileNav`** → bottom offset = 56 + safe-area. Combined bottom chrome = 128 + inset; the PDP's scroll container has 136 bottom padding so the last content is never hidden |
| Fill / border / elevation | `color/bg/surface` · top border 1px `color/border/default` · `e3` upward |
| Auto-layout | Horizontal, padding `spacing/4`, gap `spacing/3`, align centre |
| Left block | Vertical, Hug (max 128), gap 0: `price` `৳৪,২৫০` · `caption` `color/text/secondary` 1-line ellipsis variant summary `লাল · ৪৮৪ পিস` / `Red · 484 pcs` |
| Wishlist | `IconButton Size=md` 44 × 44, `radius/full`, 1px `color/border/default` |
| CTA | `Button/primary Size=lg` **Fill** × 52, `radius/full`, label `কার্টে যোগ করুন` / `Add to cart` |
| `InCart` | CTA splits: `QuantityStepper` hug × 52 (`− 2 +`) + `Button/primary Size=lg` Fill `কার্ট দেখুন` / `View cart`. The bar height does not change |
| `OutOfStock` | CTA → `Button/secondary Size=lg` Fill `স্টকে এলে জানান` / `Notify me`; left block price stays, variant line becomes `color/text/tertiary` |
| `VariantUnchosen` | CTA enabled, label `রঙ বেছে নিন` / `Choose a colour`; tapping scrolls to the variant axis and focuses its first option with a 2px `color/border/focus` ring + a 600ms `color/bg/brand-subtle` flash. **Never a disabled button with no explanation.** |
| Appearance | Slides up translateY 100%→0 over 200ms `decelerate` when the inline CTA passes above the fold; slides down when it returns |
| WhatsApp FAB | `WhatsAppFab` is pinned **above** this bar (bottom = 128 + inset + `spacing/4`) and never overlaps the CTA — explicit requirement from §4 |
| a11y | `role="region" aria-label="অর্ডার বার" / "Order bar"`; the bar's CTA and the inline CTA are the same control semantically — only one is in the tab order at a time (`inert` on the hidden one) |
| Bangla | **Strategy (a)** — bar height fixed at 72; `কার্টে যোগ করুন` at `body` 600 fits the Fill CTA at 390 with the 44 wishlist and a 128 price block. If a locale's label exceeds the CTA, the **price block shrinks to 96 and the variant line truncates** — the CTA label is never truncated or shrunk. |

---

## A17. `CartLineItem`

| Property | Type | Values |
|---|---|---|
| `State` | Variant | `Default` · `Updating` · `StockReduced` · `Unavailable` · `Removed` |
| `Context` | Variant | `CartPage` · `CheckoutReview` (read-only, no stepper) |

| Element | 390 | 1440 |
|---|---|---|
| Frame | Fill × **hug**, min **120** | Fill (808 in the cart's 808/440 split) × hug, min 136 |
| Auto-layout | Horizontal, padding `spacing/4`, gap `spacing/3`, align top | padding `spacing/5`, gap `spacing/4` |
| Fill / divider | `color/bg/surface`; 1px `color/border/default` bottom divider between items (not a card each — a stack of cards at 390 wastes 24px per item) | same |
| Image | **88 × 88** `radius/md`, 1px `color/border/default` | **104 × 104** |
| Content | Vertical, Fill, gap `spacing/2`: title (`body-sm` **2-line clamp, 42 fixed**) · variant + age `caption` `color/text/tertiary` 1 line · `StockIndicator Size=sm` (only when `LowStock`/`StockReduced`) · bottom row | same, `body` and 52 |
| Bottom row | Horizontal, Fill, space-between, align centre: `QuantityStepper Size=sm` hug × 36 · `price` unit total right-aligned | `QuantityStepper Size=md` 44 · price |
| Remove | `IconButton` `trash-2` 20, 44 × 44, top-right of the item, `color/text/tertiary` → hover `color/status/out-of-stock` | same |
| `Updating` | Price and stepper at 40% opacity + a 16 `Spinner` replacing the price; **row height unchanged** |
| `StockReduced` | A 40-tall `Alert/warning` inline row appended inside the content stack: `স্টকে ২টি আছে — পরিমাণ কমানো হয়েছে` / `Only 2 in stock — quantity reduced`. `aria-live="assertive"` |
| `Unavailable` | Image 50% opacity, title `color/text/secondary`, stepper replaced by `স্টকে নেই` / `Out of stock` pill, price `color/text/tertiary` struck, and a 2-button row: `পছন্দে রাখুন` / `Move to wishlist` · `সরান` / `Remove`. The item is excluded from the total and the total's `aria-live` says so |
| `Removed` | The row collapses to a **64-tall undo strip** in place (`color/bg/subtle`): `{নাম} সরানো হয়েছে` / `{name} removed` + `ফিরিয়ে আনুন` / `Undo` text button, auto-dismissing after 8s. **Removing never makes the page jump; the strip holds the space until it resolves.** |
| Bangla | **Strategy (a)** for the title clamp; **(b)** for the alert rows, which are appended to a hug-height stack |

---

## A18. `CartSummary`

| Property | Type | Values |
|---|---|---|
| `Context` | Variant | `CartPageMobile` (inline card + sticky total bar) · `CartPageDesktop` (sticky right rail) · `CheckoutMobile` (collapsible top bar) · `CheckoutDesktop` (sticky right rail) |
| `DeliveryKnown` | Variant boolean | |

| Element | 390 | 1440 |
|---|---|---|
| Frame | Fill × hug | **440** × hug, `position: sticky; top: 136` |
| Fill / radius / border / padding | `color/bg/surface` · `radius/lg` · 1px `color/border/default` · padding `spacing/5` | same, padding `spacing/6` |
| Heading | `h3` `অর্ডার সারসংক্ষেপ` / `Order summary`, 28 tall | `h3`, 32 |
| Rows | Fill × 28 each, horizontal space-between, `body` label `color/text/secondary` / `body` value `color/text/primary` tabular | Fill × 32 |
| Row list | `মোট পণ্যমূল্য`/`Subtotal` · `ছাড়`/`Discount` (value in `color/text/discount`, prefixed `−`, row hidden at 0) · `কুপন (SAVE10)`/`Coupon (SAVE10)` (with a `x` remove IconButton, row hidden when none) · `ডেলিভারি চার্জ`/`Delivery charge` · `অগ্রিম`/`Advance payable` (COD advance, row hidden when 0) |
| Delivery row — `DeliveryKnown=false` | Value reads `জেলা বেছে নিন` / `Select district` as a `color/text/link` text button opening the estimator sheet. **It never reads "Calculated at checkout".** |
| Delivery row — free | `ফ্রি` / `Free` in `color/status/in-stock` 600 with the struck amount in `price-was` beside it |
| Free-delivery progress | Below the rows, hug × 48: `ProgressBar` Fill × 6 + `caption`: `আরও ৳৫৫০ কিনলে ডেলিভারি ফ্রি` / `Add ৳550 more for free delivery` + a `আরও দেখুন` / `Browse more` text button. Removed once met and replaced by `caption` `color/status/in-stock` `ফ্রি ডেলিভারি পেয়েছেন 🎉` / `You've got free delivery` |
| Divider | 1px `color/border/default`, `spacing/4` above and below |
| Total row | Fill × 40: `h3` `সর্বমোট` / `Total` + `price-lg` `color/text/price`. A `caption` `color/text/tertiary` under it: `ক্যাশ অন ডেলিভারিতে পরিশোধ` / `Pay cash on delivery` |
| CTA | `Button/primary Size=lg` Fill × 52: cart → `চেকআউটে যান` / `Proceed to checkout`; checkout → `অর্ডার করুন · ৳৪,৩২০` / `Place order · ৳4,320` |
| Below CTA | `CouponInput` (A19) then `TrustStrip Theme=Plain Layout=Compact` |
| Mobile sticky total bar | **390 × 72** fixed bottom (replacing `MobileNav` on the cart route? **No** — it sits above it, bottom offset 56): horizontal padding `spacing/4`, gap `spacing/3`: stack {`caption` `সর্বমোট`, `price-lg` value} + `Button/primary Size=lg` Fill `চেকআউটে যান`. A `chevron-up` IconButton 44 expands a 240-tall sheet with the full row list |
| Checkout mobile top bar | **390 × 56** sticky under the header: `shopping-bag` 20 + `body` `৩টি পণ্য · ৳৪,৩২০` / `3 items · ৳4,320` + `chevron-down` 20. Tapping expands the full summary inline (hug, pushes content down, 320ms `decelerate`) |
| Recalculation | Any change updates values with **no height change** (reserved row heights) and announces via `aria-live="polite"`: `সর্বমোট ৳৪,৩২০` / `Total ৳4,320` |
| Bangla | **Strategy (a)** — all row labels are 1-line at 28/32 tall and authored to fit a 56%-width label column; `ডেলিভারি চার্জ` is the longest and fits 440 and 358 both |

---

## A19. `CouponInput`

| Property | Type | Values |
|---|---|---|
| `State` | Variant | `Collapsed` · `Expanded` · `Validating` · `Applied` · `Invalid` · `Expired` · `MinNotMet` |

| Element | Value |
|---|---|
| `Collapsed` (**default, always**) | A single row, Fill × **40**, horizontal gap `spacing/2`, left-aligned: `ticket-percent` 20 `color/text/secondary` + text button `কুপন আছে?` / `Have a coupon?` `body-sm` 600 `color/text/link` + `chevron-down` 16. **No input is visible.** An open empty coupon field sends buyers off-site to hunt for a code — per §6.1 this stays closed. |
| `Expanded` | Same row with `chevron-up`, then a 44-tall horizontal stack gap `spacing/2`: `Input` Fill × 44 (`radius/md`, uppercase transform, placeholder `কুপন কোড` / `Coupon code`, autocapitalize on, autocorrect off) + `Button/secondary Size=md` hug 44 `প্রয়োগ করুন` / `Apply`. Total block 40 + `spacing/2` + 44 = 92 hug |
| Expand animation | Height 40→92 over 200ms `standard`; the input receives focus on expand. Everything below moves down inside the same auto-layout stack — the **total row is below it, so the total never jumps upward unexpectedly** |
| `Validating` | Apply button `loading`, input `aria-busy`, 300ms minimum so the state is perceivable |
| `Applied` | The block collapses to a 44-tall `color/bg/brand-subtle` `radius/md` row: `check-circle` 16 `color/status/in-stock` + `body-sm` 600 `SAVE10 প্রয়োগ হয়েছে` / `SAVE10 applied` + `caption` `color/text/discount` `−৳৪২৫` + `x` IconButton 44 to remove. `aria-live="polite"` announces the new total |
| `Invalid` | Input border 2px `color/status/out-of-stock`, `aria-invalid`, and a **20-tall reserved** error line below (present in all states as an empty 20px box, so errors do not reflow): `caption` `color/status/out-of-stock` `এই কোডটি সঠিক নয়` / `That code isn't valid` |
| `Expired` | `এই কুপনের সময় শেষ হয়ে গেছে` / `This coupon has expired` |
| `MinNotMet` | `এই কুপনের জন্য কমপক্ষে ৳২,০০০ লাগবে — আরও ৳৩১০` / `This coupon needs a ৳2,000 minimum — ৳310 to go`, with a `আরও দেখুন` / `Browse more` text button |
| Error rule | The message always names the fixable thing (§6.1). Never "Invalid coupon." |
| Bangla | **Strategy (b)** — the error line is reserved at 20 in `en` and **36 in `bn`** (two lines), set per locale |

---

## A20. `OrderTimeline`

| Property | Type | Values |
|---|---|---|
| `Orientation` | Variant | `Vertical` (390 and 1440 — chosen for both; a horizontal stepper cannot hold a timestamp + courier line legibly) |
| `Step` | Variant per node | `Done` · `Current` · `Upcoming` · `Failed` · `Skipped` |

| Element | 390 | 1440 |
|---|---|---|
| Frame | Fill × hug, padding `spacing/5`, `color/bg/surface`, `radius/lg`, 1px `color/border/default` | **640** × hug, padding `spacing/6` |
| Node row | Horizontal, Fill × hug min **72**, gap `spacing/4`, align top | min 80 |
| Rail | 2px vertical line in the 24-wide gutter, `color/border/default`; the segment **above** a `Done`/`Current` node is `color/bg/brand` | same |
| Marker | 24 round: `Done` `color/bg/brand` fill + `check` 14 `color/text/on-brand` · `Current` `color/bg/surface` + 2px `color/bg/brand` ring + 8 `color/bg/brand` core, with a 2s pulse ring (reduced-motion: static) · `Upcoming` `color/bg/subtle` + 1px `color/border/default` · `Failed` `color/status/out-of-stock` fill + `x` 14 · `Skipped` `color/bg/subtle` + `minus` 14 | same |
| Content | Vertical, Fill, gap `spacing/1`: label `body` 600 (`Upcoming` → `color/text/tertiary` 400) · timestamp `caption` `color/text/tertiary` (`১০ নভেম্বর, ২:১৪ অপরাহ্ণ` / `10 Nov, 2:14 pm`) · detail `body-sm` `color/text/secondary` (hug, wraps freely) | same |
| Steps | 1 `অর্ডার পেয়েছি` / `Order received` · 2 `কনফার্ম হয়েছে` / `Confirmed` (detail: `ফোনে কনফার্ম — ০১৭১২-xxxxxx`) · 3 `প্যাক করা হয়েছে` / `Packed` · 4 `কুরিয়ারে দেওয়া হয়েছে` / `Handed to courier` (detail: `স্টেডফাস্ট · কনসাইনমেন্ট CN-4471982` + `ট্র্যাক করুন` / `Track` link — **no competitor exposes shipment-level tracking**) · 5 `পথে আছে` / `Out for delivery` (detail: `ডেলিভারি ম্যান: ০১৯xx-xxxxxx`) · 6 `ডেলিভার হয়েছে` / `Delivered` |
| Exception steps | `Failed` → `ডেলিভারি ব্যর্থ` / `Delivery attempt failed` with the courier's reason and a `আবার চেষ্টা করতে বলুন` / `Ask for another attempt` `Button/secondary Size=sm`; `রিটার্ন হচ্ছে` / `Being returned`; `বাতিল` / `Cancelled` with reason; `আংশিক ডেলিভারি` / `Partially delivered` listing which items |
| Live | `aria-live="polite"` on the current node's label; `role="list"` / `listitem` |
| Bangla | **Strategy (b)** — every node hugs; the detail line wraps to as many lines as it needs |

---

## A21. `ReviewCard`

| Property | Type | Values |
|---|---|---|
| `Verified` | Variant boolean | from `order_item`, not self-declared |
| `HasPhotos` | Variant boolean | |
| `Expanded` | Variant boolean | |

| Element | 390 | 1440 |
|---|---|---|
| Frame | Fill × hug, padding `spacing/4` 0, bottom divider 1px `color/border/default` | **640** × hug, padding `spacing/5` 0 |
| Header | Horizontal, Fill × 44, gap `spacing/3`: `Avatar Size=md` 40 (initial on `color/bg/brand-subtle`) · stack {`body-sm` 600 name `সাবরিনা আক্তার` · `caption` `color/text/tertiary` `১২ অক্টোবর ২০২৬`} · (right) `Rating` display 5 × 14 | Avatar 44 |
| Verified badge | `Badge` hug × 22, `color/bg/subtle`, `caption`, `shield-check` 12 `color/status/in-stock`: `ভেরিফাইড ক্রেতা` / `Verified purchase` |
| Context line | `caption` `color/text/tertiary`: `কিনেছেন: লাল · ৪৮৪ পিস · সন্তানের বয়স ৩ বছর` / `Bought: Red · 484 pcs · child aged 3` — **age-of-child context is the single most useful review field in this category and no competitor collects it** |
| Body | `body-sm`, `color/text/primary`, **4-line clamp** then `আরও পড়ুন` / `Read more` text button; expands in place (hug) | `body` |
| Photos | Horizontal, gap `spacing/2`: up to 4 thumbs **72 × 72** `radius/md`, `+৩` overlay on the 4th if more; opens the lightbox | 88 × 88 |
| Helpful row | Horizontal, gap `spacing/3`, 44 tall: `thumbs-up` IconButton 44 + `caption` `৮ জনের উপকারে এসেছে` / `8 found this helpful`; `রিপোর্ট` / `Report` text button right |
| Shop reply | Indented `spacing/8`, `color/bg/subtle`, `radius/md`, padding `spacing/4`: `caption` 600 `টয় মার্ট বিডি` + `body-sm` reply |
| Empty (no reviews yet) | A 180-tall `EmptyState`: illustration 96, `h4` `এখনো কোনো রিভিউ নেই` / `No reviews yet`, `body-sm` `color/text/secondary` `কিনে প্রথম রিভিউ লিখুন — আমরা ডেলিভারির ৩ দিন পর SMS এ জিজ্ঞেস করব` / `Be the first — we ask by SMS three days after delivery`, `Button/outline Size=md` `রিভিউ লিখুন` / `Write a review` |
| Bangla | **Strategy (b)** throughout; the only clamp is the 4-line body, which is a line count, not a height |

---

## A22. `WishlistButton`

| Property | Type | Values |
|---|---|---|
| `Context` | Variant | `CardOverlay` (on a photo) · `Inline` (PDP, next to the CTA) · `BarIcon` (`AddToCartBar`) |
| `Saved` | Variant boolean | |
| `State` | Variant | `Default` · `Hover` · `Focus` · `Pressed` · `Loading` |

| Element | Value |
|---|---|
| `CardOverlay` | 36 × 36 (390) / 40 × 40 (1440) circle, `color/bg/surface` @ 92%, `e1`, `heart` 18/20. **44 × 44 hit area** via transparent padding, which means the transparent box overlaps the image edge by 4px on each side — acceptable and intentional |
| `Inline` | `IconButton Size=lg` 52 × 52, `radius/full`, 1px `color/border/default`, `color/bg/surface` |
| Off → On | `heart` outline `color/text/secondary` → filled `color/bg/brand`; 320ms `spring` scale 1 → 0.88 → 1.08 → 1; a 2px `color/bg/brand` ring expands from the icon to 1.6× and fades. Reduced motion → 120ms colour cross-fade only |
| `Loading` | Icon → 16 `Spinner`; the button keeps its exact size |
| Focus | 2px `color/border/focus`, 2px offset |
| Guest behaviour | Saving works **without login** (stored locally + a server cart token). A single `Toast` appears on first save: `পছন্দের তালিকায় রাখা হলো — নম্বর দিয়ে সেভ করে রাখুন` / `Saved — add your number to keep it` with a `সেভ করুন` / `Save it` action. Forcing login to wishlist is the same mistake as forcing login to buy. |
| a11y | `aria-pressed`, `aria-label` `{নাম} পছন্দের তালিকায় রাখুন` / `Save {name} to wishlist`; `aria-live="polite"` confirmation |

---

## A23. `WhatsAppFab`

| Property | Type | Values |
|---|---|---|
| `State` | Variant | `Collapsed` · `Expanded` · `Hidden` |
| `Position` | Variant | `MobileAboveBars` · `Desktop` |

| Element | 390 | 1440 |
|---|---|---|
| Frame | **56 × 56** circle, `radius/full`, `e3` | **56 × 56**, expands to a hug pill 180 × 56 on hover with the label `সাহায্য লাগবে?` / `Need help?` |
| Position | Bottom-right: right `spacing/4` (16), bottom = **`MobileNav` 56 + `AddToCartBar` 72 (when present) + safe-area + `spacing/4`**. On a PDP that is 148 + inset; elsewhere 76 + inset. **It must never cover the add-to-cart CTA** — §4 requirement, enforced by binding the offset to the bars' presence | right `spacing/8`, bottom `spacing/8` |
| Fill / icon | A dedicated `color/bg/accent` ground would be wrong (brand confusion with sale badges) — use **`color/bg/surface`** with a 1px `color/border/default`, `e3`, and the WhatsApp glyph at 28 in its own brand green as a **product logo asset**, not a token. This is the one permitted non-token colour and it is an imported logo, not a fill. |
| `Expanded` | Tapping opens a **hug-height bottom sheet** (390) / **320 × hug `Popover`** (1440), `radius/2xl` top, `color/bg/surface`, `e4`: `h4` `কেনার আগে সাহায্য দরকার?` / `Need help before ordering?` · `body-sm` `color/text/secondary` `বয়স, সাইজ, ডেলিভারি — যা জানতে চান জিজ্ঞেস করুন। সকাল ৯টা–রাত ৯টা।` / `Ask us about age, size or delivery. 9 am – 9 pm.` · two `Button/secondary Size=lg` Fill rows: `হোয়াটসঅ্যাপে মেসেজ` / `Message on WhatsApp` and `ফোন করুন ০৯৬১০-১২৩৪৫৬` / `Call 09610-123456` · `caption` `color/text/tertiary` `সাধারণত ১০ মিনিটে উত্তর দিই` / `We usually reply within 10 minutes` |
| Prefill | The WhatsApp deep link is prefilled with the current product's name, variant and URL, and with the district when the estimator has one. From `State=NotServiced` it is prefilled with the district and the words `এখানে ডেলিভারি দেন?` / `Do you deliver here?` |
| `Hidden` | On `/checkout` and in the lightbox. **Nothing floats over checkout.** |
| a11y | `aria-label`, `aria-expanded`; the sheet is a focus-trapped dialog returning focus to the FAB |

---

# PART B — Screen layouts

**Global chrome, used by every screen below.**

| Chrome | 390 | 1440 |
|---|---|---|
| `Header` | **390 × 56** sticky, `color/bg/surface`, bottom border 1px `color/border/default`, padding `spacing/4` h, horizontal gap `spacing/3`: hamburger `IconButton` 44 · wordmark 112 × 28 centred · `SearchBar Context=HeaderMobile` trigger 44 · `WishlistButton Context=BarIcon` 44. On scroll past 120px it gains `e2`. | **1440 × 72** sticky, content capped 1280: wordmark 148 × 36 · `SearchBar Context=HeaderDesktop` 560 centred · right cluster gap `spacing/4`: `ভাষা: বাংলা ▾` / `EN ▾` locale switch 40 · `০৯৬১০-১২৩৪৫৬` with a `phone` 16 (`body-sm`) · `পছন্দ` / `Wishlist` icon+count 40 · `অ্যাকাউন্ট` / `Account` 40 · `কার্ট` / `Cart` 40 with the A5 badge. Below it the **48**-tall `MegaMenu` L1 bar, also sticky → total sticky chrome **120**. |
| `SearchRow` | **390 × 56** (padding `spacing/2` v, `SearchBar` 44 Fill) directly under the header on Home, Category and Search only. Not sticky — it scrolls away and the header's icon trigger takes over. | not present (the bar is in the header) |
| `MobileNav` | A5, **390 × 56** + safe-area, fixed bottom, on every route except `/checkout` | not present |
| `Footer` | see B1.9 | see B1.9 |
| Scroll padding | Page scroll container has **136** bottom padding on the PDP (56 nav + 72 bar + `spacing/2`) and **112** elsewhere (56 nav + 56 sticky total bar where present), so no content ever sits under fixed chrome. | — |

---

## B1. Home

### B1.0 LCP element — stated explicitly

> **The Home LCP element is the hero's single product photograph** — `318 × 160` at 390, `608 × 400` at 1440. It is the only image on the route with `priority`, it is `<link rel="preload">`-ed, served AVIF with a WebP fallback, and budgeted at **≤90 KB at 390 / ≤140 KB at 1440**. Every other image on Home — category icons excepted, which are inline SVG — is `loading="lazy"` with explicit width and height. The hero headline is server-rendered text above it and paints first, but it is not the LCP candidate and is not treated as one. Budget: **LCP ≤ 2.5 s** on throttled mobile, **CLS ≤ 0.05**, which the fixed hero height (476) and the fixed card heights (A1) deliver by construction.

### B1.1 Hero — concept: **"Tell us the age"**

The hero is not a banner. It is the one thing no competitor has, made the first interaction on the site: **an age chooser**. Two sites navigate by age, one filters by it, and **none lets age combine with anything else** (COMPETITOR-ANALYSIS §2, §3.1). Putting the age chooser in the hero says what the shop is for in one glance, needs no illustration to carry the idea, and sends the visitor straight into a URL-addressable filtered listing. Playfulness is carried by the coral-tinted panel, the rounded `radius/2xl` corners, the two-tone age icons and one warm photograph — not by a cartoon mascot and not by the typography.

| | 390 | 1440 |
|---|---|---|
| Section frame | **358 × 476** fixed, `radius/2xl`, `color/bg/brand-subtle`, no elevation, `spacing/4` top margin below the search row | **1280 × 480** fixed, `radius/2xl`, `color/bg/brand-subtle` |
| Auto-layout | Vertical, padding `spacing/5`, gap 0 (explicit gaps below) | Horizontal, padding `spacing/12`, gap `spacing/12`, align centre; left column **528** Fill, right column **608** fixed |
| 1 Overline | 16 fixed · `overline` `color/text/discount` · `বয়স অনুযায়ী খেলনা` / `SHOP BY AGE` | 18 fixed |
| gap | `spacing/2` | `spacing/3` |
| 2 Headline | **70** fixed (2 × 35) · `display-1` `color/text/primary` · `বয়স বলুন, সঠিক খেলনা দেখুন` / `Tell us the age. See the right toys.` · `bn` variant **80** | **112** fixed (2 × 56) |
| gap | `spacing/2` | `spacing/4` |
| 3 Sub | **42** fixed (2 lines) · `body-sm` `color/text/secondary` · `০–১২ বছরের খেলনা। প্রতিটিতে বয়স, দাম আর স্টক পরিষ্কার লেখা।` / `Toys for 0–12 years. Age, price and stock stated on every one.` | **58** fixed, `body-lg` |
| gap | `spacing/4` | `spacing/6` |
| 4 Hero image | **318 × 160**, `radius/lg`, object-fit cover. Content: three real products shot together on `color/bg/surface` — the Hape Pound & Tap bench, the LEGO Classic box and the Fisher-Price puppy. **This is the LCP element.** | **608 × 400**, `radius/xl`, in the right column, vertically centred |
| gap | `spacing/4` | — |
| 5 Age chooser | **36** fixed · horizontal scroll row, gap `spacing/2`, 16px trailing inset · 7 pills, hug × 36, `radius/full`, `color/bg/surface`, 1px `color/border/default`, `body-sm` 600: `০–১ বছর` `১–২ বছর` `২–৪ বছর` `৪–৬ বছর` `৬–৮ বছর` `৮–১২ বছর` `১২+ বছর`. Hover/focus → 1px `color/border/strong`; tap → `/toys?age=24-48` (A7.5 URL) | **40** fixed, wrap, gap `spacing/3`, all 7 visible, pills hug × 40 |
| gap | `spacing/3` | `spacing/6` |
| 6 CTA | `Button/primary Size=lg` **Fill × 52**, `radius/full`: `সব খেলনা দেখুন` / `Browse all toys` | Horizontal gap `spacing/3`: `Button/primary Size=lg` hug `সব খেলনা দেখুন` + `Button/ghost Size=lg` hug `ফ্ল্যাশ সেল দেখুন` / `See the flash sale` |
| Bangla | Strategy (a) for rows 1–3 via a `Lang` variant; the hero height becomes **486** in `bn`. The pill row is a horizontal scroller, so pill labels may grow freely. | panel height 480 in both locales (the left column has 24px of slack) |

### B1.2 Category tiles

| | 390 | 1440 |
|---|---|---|
| Section header | **32** fixed, horizontal space-between: `h2` `ক্যাটাগরি` / `Shop by category` + text button `সব দেখুন` / `See all` `color/text/link` `body-sm` | **40** fixed, `h2` + `See all` |
| gap | `spacing/4` | `spacing/6` |
| Grid | 2 columns × 4 rows, gap `spacing/3`, `CategoryTile Size=sm` 173 × 128 → **548** | 4 columns × 2 rows, gap `spacing/6`, `CategoryTile Size=md` 302 × 168 → **360** |
| Section total | **596** hug | **424** hug |
| Content (8 tiles, `Theme` cycling Brand → Accent → Teal → Grape → Teal → Grape → Brand → Accent) | `বিল্ডিং ব্লক` / `Building Blocks` (84) · `গাড়ি ও রিমোট` / `Cars & Remote Control` (112) · `পাজল ও গেম` / `Puzzles & Games` (67) · `পুতুল ও সফট টয়` / `Dolls & Soft Toys` (93) · `শিশু ও বেবি` / `Baby & Toddler` (58) · `আউটডোর ও রাইড-অন` / `Outdoor & Ride-On` (41) · `আর্ট ও ক্রাফট` / `Art & Craft` (76) · `সায়েন্স ও লার্নিং` / `Science & Learning` (49) | same |

### B1.3 Flash-sale rail with countdown

Full-bleed band: **390** (1440: **1440**) wide, `color/bg/brand-subtle`, padding `spacing/6` vertical, inner content at 358 / 1280.

| | 390 | 1440 |
|---|---|---|
| Header | **40** fixed, horizontal space-between, align centre: stack {`h2` `ফ্ল্যাশ সেল` / `Flash sale`, `caption` `color/text/secondary` `আজ রাত ১২টা পর্যন্ত` / `Until midnight tonight`} + `CountdownTimer Size=sm` hug × 32 | **48**: `h2` + `CountdownTimer Size=md` 288 × 48 + `সব দেখুন` / `See all` text button |
| gap | `spacing/3` | `spacing/4` |
| Rail | Horizontal scroll, gap `spacing/3`, 32px trailing inset, `ProductCard Layout=Compact` 148 × **240** with the `CountdownTimer Size=xs` pinned to each image box's bottom edge. 6 cards. | 1280 viewport, gap `spacing/6`, `ProductCard Layout=Compact` 216 × **322**, 5 visible of 6, prev/next `IconButton` 40 circles `e2` pinned to the band's vertical centre, 20px outside the content column. **No auto-advance.** |
| Section total | **40 + 12 + 240 = 292** inner, **388** with the band padding | **48 + 16 + 322 = 386** inner, **546** with the band padding |
| Content (6 cards, all `Discounted=true`) | Rubik's Cube ৳৮৯০ / ৳১,১৫০ · Barbie Dreamhouse Pool Party ৳২,৪৮০ / ৳২,৯৫০ (`OutOfStock`) · Hape Pound & Tap ৳২,৯৫০ / ৳৩,৪০০ · Little Tikes First Slide ৳৭,৪৫০ / ৳৮,৯০০ (`LowStock 2`) · LEGO Classic 484 ৳৪,২৫০ / ৳৪,৯৯০ · Winfun Push Walker ৳৪,১২০ / ৳৪,৭৫০ | same |
| Ended | Band is **removed from the DOM**, not left showing `সেল শেষ`; the next scheduled sale's band replaces it, or the section is absent. | same |

### B1.4 Shop-by-age rail

| | 390 | 1440 |
|---|---|---|
| Header | **32**: `h2` `বয়স অনুযায়ী কিনুন` / `Shop by age` + `সব দেখুন` | **40** |
| gap | `spacing/3` | `spacing/6` |
| Rail | Horizontal scroll, gap `spacing/3`, 7 × `CategoryTile Size=sm` **148 × 128** with the age icons from A11 and counts | 7 × `CategoryTile Size=md` **172 × 168** in one 1280 row, gap `spacing/3` (7 × 172 + 6 × 12 = 1276; the remaining 4px sits in the trailing gutter). Not a rail — all seven age bands are visible at once, which is the point. |
| Section total | **172** | **220** |
| Content | `০–১ বছর` (58) · `১–২ বছর` (74) · `২–৪ বছর` (121) · `৪–৬ বছর` (96) · `৬–৮ বছর` (68) · `৮–১২ বছর` (52) · `১২+ বছর` (19) | same |

### B1.5 Best sellers

| | 390 | 1440 |
|---|---|---|
| Header | **32**: `h2` `সবচেয়ে বেশি বিক্রি` / `Best sellers` + `সব দেখুন` | **40** |
| Body | Rail, gap `spacing/3`, 8 × `ProductCard Layout=Compact` 148 × 240 | **Grid**, not a rail: 4 columns × 1 row of `ProductCard Layout=Grid` 302 × 552, gap `spacing/6`. A grid beats a rail on desktop because all four are visible without interaction; the mobile rail exists only because 2-up grid rows cost 384px each. |
| Section total | **32 + 12 + 240 = 284** | **40 + 24 + 552 = 616** |
| Content (order = units sold) | Hot Wheels 10-Car Pack · LEGO Classic 484 · Play-Doh Rainbow 10-Pack · Fisher-Price Smart Stages Puppy · Crayola Super Tips 20 · Mideer Shape Sorter · Hape Pound & Tap · Rubik's Cube 3×3 | first 4 on desktop, `See all` for the rest |

### B1.6 New arrivals

Identical geometry to B1.5 with `Badge=New` on every card. Header `h2` `নতুন এসেছে` / `New arrivals`.
Content: Clementoni My First Microscope · Bruder MAN Dump Truck · Melissa & Doug Doctor Kit · Intex Dinosaur Pool · Huanger Activity Gym · Winfun Push Walker · Little Tikes First Slide · Barbie Dreamhouse Pool Party.

### B1.7 USP strip

| | 390 | 1440 |
|---|---|---|
| Frame | Full-bleed **390**, `color/bg/subtle`, padding `spacing/6` v, inner `TrustStrip Layout=Grid2x2 Theme=Subtle` 358 × 136 (fill removed — the band is the fill) → section **232** | Full-bleed **1440**, `color/bg/subtle`, padding `spacing/8` v, inner `TrustStrip Layout=Row4` 1280 × 96 → section **160** |
| Content | A14's four items verbatim, with the sub-captions | same |

### B1.8 Blog teaser

Nobody in this market has working content — every competitor blog is empty, dormant or 2019 demo cruft (COMPETITOR-ANALYSIS §3.3). The teaser is on the home page from day one so the content programme has a front door.

| | 390 | 1440 |
|---|---|---|
| Header | **32** + a 36-tall sub-line `body-sm` `color/text/secondary` `কোন বয়সে কী খেলনা, কীভাবে বাছবেন` / `Which toy at which age, and how to choose` | **40** + 40 sub-line |
| gap | `spacing/3` | `spacing/6` |
| Body | Horizontal rail, gap `spacing/3`, 3 × blog card **280 × 268**: image 280 × 158 `radius/lg` top · padding `spacing/4` · `caption` `color/text/tertiary` category + read time (16) · `h4` title **2-line clamp 44 fixed** | 3 × blog card **410 × 344** (3 × 410 + 2 × 24 = 1278, 2px absorbed by the trailing gap), image 410 × 230 |
| Section total | **32 + 36 + 12 + 268 = 348** | **40 + 40 + 24 + 344 = 448** |
| Content | 1 `২ বছরের শিশুর জন্য কোন খেলনা সবচেয়ে ভালো?` / `What toys actually suit a 2-year-old?` — `বয়স গাইড · ৬ মিনিট` · 2 `লেগো আসল না নকল: ঢাকায় কীভাবে বুঝবেন` / `Real or fake LEGO: how to tell in Dhaka` — `কেনার গাইড · ৪ মিনিট` · 3 `ঈদে ৳১,০০০ এর নিচে ১০টি উপহার` / `10 Eid gifts under ৳1,000` — `উপহার গাইড · ৫ মিনিট` | same |

### B1.9 Footer

> **Redesign, owner 2026-11-11 ("colourful, kid-friendly, more modern"; must not mirror the reference site — a sky/hills version and a navy bunting version were both rejected).** Concept: **bento tiles + giant wordmark** on a raised `color/bg/surface` panel (top radius 48/32, soft upward rose shadow) with a centred six-colour rainbow pill strip as the page separator, and a 1px `color/border/default` rule above the legal bar (owner approved, 2026-11-11). Desktop row of five radius-28 pastel tiles (each with a soft corner blob): pink **Help** tile 420 wide ("Online now · 9 am – 9 pm" pill, h2 "Stuck choosing a toy? We love helping.", sub, primary "Chat on WhatsApp" + hotline), butter **Shop**, mint **Help**, sky **About** link tiles (white icon badge + Fredoka h3 + body-strong navy links), lavender **Say hi!** tile (tilted white social squares + email). Below: a decorative giant **"toymart"** in Fredoka Bold (300px desktop / 96px mobile), each letter a different pastel-400/500 — decorative logotype, hidden from screen readers. Legal bar: copyright · Cash on delivery pill · English · বাংলা. Mobile stacks Help tile, Shop+Help half tiles, About and Say hi full width. Heights ≈ 797 desktop / 1,280 mobile; the table below is superseded for size and content.

| | 390 | 1440 |
|---|---|---|
| Frame | Full-bleed, `color/bg/surface`, top border 1px `color/border/default`, padding `spacing/8` `spacing/4` | Full-bleed, padding `spacing/16` `spacing/8`, inner 1280 |
| 1 Brand block | Vertical gap `spacing/3`: wordmark 128 × 32 · `body-sm` `color/text/secondary` 2 lines `বাংলাদেশের শিশুদের জন্য আসল খেলনা। ঢাকায় ২৪ ঘণ্টায় ডেলিভারি।` / `Real toys for Bangladeshi children. Next-day delivery in Dhaka.` · social row 4 × 40 IconButtons | Column 1 of 5, **296** wide, same content + the hotline in `h4` |
| 2–4 Link columns | **`Accordion`**, 3 sections × 52-tall headers, expanding to hug lists of 44-tall rows. Headers: `কেনাকাটা` / `Shop` · `সহায়তা` / `Help` · `প্রতিষ্ঠান` / `Company` | 3 columns × **216** wide, open lists, `overline` heading + 32-tall `body-sm` rows |
| 5 Contact column | 390: a 136-tall block after the accordions | Column 5, **216**: `০৯৬১০-১২৩৪৫৬` · `hello@toymartbd.com` · `সকাল ৯টা–রাত ৯টা, শুক্রবার বন্ধ` / `9 am – 9 pm, closed Friday` · `Button/outline Size=sm` `হোয়াটসঅ্যাপ` |
| Shop links | `সব খেলনা` · `বয়স অনুযায়ী` · `ফ্ল্যাশ সেল` · `নতুন এসেছে` · `বেস্ট সেলার` · `ব্র্যান্ড` · `ব্লগ` | same |
| Help links | `অর্ডার ট্র্যাক করুন` / `Track your order` · `ডেলিভারি চার্জ` / `Delivery charges` · `রিটার্ন ও রিফান্ড` / `Returns & refunds` · `প্রশ্নোত্তর` / `FAQ` · `যোগাযোগ` / `Contact us` | same |
| Company links | `আমাদের সম্পর্কে` / `About us` · `গোপনীয়তা নীতি` / `Privacy policy` · `শর্তাবলী` / `Terms` · `খেলনার নিরাপত্তা` / `Toy safety` | same |
| Payment / delivery row | Horizontal wrap, gap `spacing/3`, 40-tall logo chips: `ক্যাশ অন ডেলিভারি` label chip · bKash · Nagad · SSLCommerz · Steadfast · Pathao. **Only rails that actually work are shown** — SK Toy shows bKash badges with bKash disabled in config, which is exactly the trust leak we avoid | same |
| Legal bar | Top border 1px `color/border/default`, padding `spacing/5` 0, `caption` `color/text/tertiary` centred: `© ২০২৬ টয় মার্ট বিডি · ট্রেড লাইসেন্স TRAD/DNCC/০৪৪৫৬৭/২০২৫` | left-aligned, with the locale switch right |
| Footer total | ≈ **820** collapsed | ≈ **480** |

### B1.10 Home section order and totals

| # | Section | 390 height | 1440 height |
|---|---|---|---|
| — | Header (sticky) | 56 | 72 + 48 |
| — | Search row | 56 | — |
| 1 | Hero | 476 | 480 |
| 2 | Category tiles | 596 | 424 |
| 3 | Flash-sale band | 388 | 546 |
| 4 | Shop-by-age rail | 172 | 220 |
| 5 | Best sellers | 284 | 616 |
| 6 | New arrivals | 284 | 616 |
| 7 | USP strip band | 232 | 160 |
| 8 | Blog teaser | 348 | 448 |
| 9 | Footer | ≈820 | ≈480 |
| — | Bottom nav | 56 + inset | — |
| Gaps | `spacing/12` (48) between sections 1–8; bands supply their own padding so the gap before and after a band is `spacing/8` (32) | | `spacing/20` (80) between sections |

---

## B2. Product detail

> **Detail content + cross/upsell pass (owner, 2026-11-11).** New component set **`ProductInfoPanel`** (Figma 157:2626, page 03 section 05) — `Tab` = Description · Specifications · Age & safety · Delivery & returns × `Size` = Desktop (1280, tab panel) · Mobile (326, `Accordion/Item` bodySlot). Description: intro (body-lg), "Why kids love it" 3 pastel feature tiles, "What's in the box" checklist, desktop photo + parent tip. Specifications: zebra key/value table (butter/white, radius 20; two tables side by side on desktop). Age & safety: lavender age card with a 6-band age scale (matching bands in grape/500), skill chips, `Alert Warning` "Small parts — choking hazard", three safety tiles, care text. Delivery & returns: zone table (৳70 / ৳100 / ৳130, same table as A12), free-over-৳3,000 pill, 3-step return tiles, policy note (**return policy wording to be confirmed by the owner**). Desktop tab bar = `Tabs/Item Underline` × 5 (the fifth, "Reviews 42", scrolls to the Reviews section). Mobile replaces the single accordion with four `Accordion/Item Flush` rows with leading icon + subtitle; only the first is open. Every state is a full screen next to "Product detail": desktop "Product detail — Specifications / Age & safety / Delivery & returns tab", mobile "Product detail — … open". Panels have no fill of their own (they sit on the screen background).
>
> **Cross-sell / upsell row** (below the tabs on desktop, replacing "Frequently bought together" on mobile): sky **Frequently bought together** card — three white item tiles with checked `Checkbox/Bare`, "+" joins on desktop, summary bar with total ৳6,130, struck ৳6,870 (corrected from ৳6,860), "You save ৳740" + "Free delivery" pills and `Button Primary` "Add all 3 to cart". Butter **Big-gift upgrade** card (392 desktop / full width mobile) — "Planning a birthday?", one higher-priced pick in the same age range (Bruder Dump Truck ৳5,650, "+৳1,400 more"), Outline "See the truck" + cart `IconButton`. Rule for picking the upsell item (same age band, 1.2–1.6× price, in stock) is a proposal for the owner.

**Section order at 390 follows UI-UX-DESIGN-SPEC §6.2 exactly.** Two additions are inserted without reordering anything: a breadcrumb above the gallery, and the `AgeRangeIndicator Size=md` block between the stock state and the variant selector. Nothing in the §6.2 list moves.

### B2.1 Price-never-reflows contract

| Guarantee | Mechanism |
|---|---|
| Price block has a fixed height | **96** fixed at 390 / **108** at 1440, regardless of whether the product is discounted, has a was-price, or shows a per-variant price. The undiscounted state leaves the discount row as an empty reserved 24px box. |
| Price is server-rendered | It is in the initial HTML, never hydrated in. No skeleton, no spinner, no client fetch. |
| Variant change does not resize it | The `price-lg` box is `tabular-nums` and width-fixed to 11 characters (`৳১২,৩৪৫.০০` worst case); a variant price change swaps digits inside a fixed box. |
| Everything above it is fixed-height | Breadcrumb 32, gallery stage 358 (aspect-locked), dots overlay (absolute), title box 2-line clamp **56** fixed, rating row **24** fixed. Sum above the price: **470** at 390, constant in every state and both locales (a `Lang` variant sets the title box to 64 in `bn`). |
| Font swap cannot shift it | Self-hosted `next/font` with `display: swap` and matched fallback metrics; `price-lg` uses the same metric-compatible fallback. |

### B2.2 390 section list

| # | Section | Height | Padding | Components | Content (example: LEGO Classic Creative Bricks 484) |
|---|---|---|---|---|---|
| 0 | Header (sticky) | 56 | `spacing/4` h | Header | On scroll past the gallery it swaps the wordmark for the product title at `body-sm` 1-line ellipsis |
| 1 | Breadcrumb | **32** | `spacing/4` h | `Breadcrumb` | `হোম / বিল্ডিং ব্লক / লেগো` — horizontal scroll, last crumb `color/text/primary`, others `color/text/link` `caption` |
| 2 | **Image gallery** | **358** | 0 (full 358 width) | `ProductGallery Layout=MobileSwipe` | 7 photos + 1 video. Badges: `−১৫%` accent top-left, `বেস্ট সেলার` below it, wishlist top-right, `AgeRangeIndicator Size=xs` `৪–১২ বছর` bottom-left |
| 3 | Brand + title | **80** (brand 20 + gap `spacing/1` + title 56) | `spacing/4` | text | `LEGO` (`caption` `color/text/tertiary`, links to the brand page) · `h1` **2-line clamp** `লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস ৪৮৪ পিস` / `LEGO Classic Creative Bricks 484 pcs` |
| 4 | **Rating + review count** | **24** | `spacing/4` | `Rating` display | 5 stars @16 · `৪.৭` `body-sm` 600 · `(৪২টি রিভিউ)` / `(42 reviews)` as a `color/text/link` jump-link to section 13 · `· ২১৭ বার বিক্রি` / `· 217 sold` `caption` `color/text/tertiary` |
| 5 | **Price block** | **96 fixed** | `spacing/4` | `Price` | Row 1 (40): `price-lg` `৳৪,২৫০` + `price-was` struck `৳৪,৯৯০` + `−১৫%` in `color/text/discount` `body-sm` 700 · Row 2 (24, **reserved even when empty**): `caption` `color/text/discount` `৳৭৪০ সাশ্রয়` / `Save ৳740` · Row 3 (24): `caption` `color/text/tertiary` `দাম ভ্যাট সহ` / `Price includes VAT` |
| 6 | **Stock state** | **28** | `spacing/4` | `StockIndicator Size=md` | `স্টকে আছে` + the 20-tall cut-off line from A13 → block hugs to **52** |
| 6b | Age block *(addition)* | **84** | `spacing/4` | `AgeRangeIndicator Size=md` | `৪–১২ বছরের জন্য` / `For ages 4–12` + `বয়স গাইড` link; no safety line (lower bound ≥ 36 months) |
| 7 | **Variant selector** | hug, min **136** | `spacing/4` | `VariantSelector` | Axis 1 `সেট` / `Set`: `৯০ পিস` `২১৩ পিস` `৪৮৪ পিস` (selected) — reason line reserved 20/40. Axis 2 `প্যাকিং` / `Packaging`: `বক্স` `টাব` (`Unavailable`, reason `টাব প্যাকিং এখন স্টকে নেই` / `Tub packaging is out of stock right now`) |
| 8 | **Quantity** | **44** | `spacing/4` | `QuantityStepper Size=md` | Label `পরিমাণ` / `Quantity` `body-sm` left, stepper hug right; `+` disabled at 23 with the A1.4 toast |
| 9 | **Delivery estimator** | hug, min **132** | `spacing/4` | `DeliveryEstimator` | `State=Empty` on a first visit, `Selected` when a district is remembered. Full A12 content |
| 10 | **Add to cart** | **120** | `spacing/4` | `Button` ×2 | `Button/primary Size=lg` Fill × 52 `কার্টে যোগ করুন` / `Add to cart`; gap `spacing/3`; `Button/secondary Size=lg` Fill × 52 `এখনই কিনুন` / `Buy now` (skips the cart, lands on checkout with this one item). `AddToCartBar` (A16) takes over once this scrolls past |
| 11 | **Trust strip** | **136** | `spacing/4` | `TrustStrip Layout=Grid2x2 Theme=Subtle` | A14 content |
| 12 | **Short description** | hug, ~**180** | `spacing/4` | text | `h3` `এক নজরে` / `At a glance` + a 5-bullet list, 28-tall rows, `check` 16 `color/status/in-stock` leading: `৪৮৪টি রঙিন ব্রিক, ৩৫টি আকার` · `বেস প্লেট ও চাকা সহ` · `সব লেগো সেটের সাথে মেলে` · `BPA-মুক্ত, CE ও ASTM সার্টিফাইড` · `আসল ডেনমার্ক-তৈরি, বক্সে হলোগ্রাম` |
| 13 | Tabbed detail | hug | 0 | `Accordion` (390) | Four 56-tall accordion headers, all **closed** by default except the first: `বিস্তারিত বিবরণ` / `Full description` · `স্পেসিফিকেশন` / `Specifications` (a 2-column key/value table: brand, origin, material, pieces, box size, weight, certification, warranty, SKU `TMB-LEG-CL484`) · `বয়স ও নিরাপত্তা` / `Age & safety` · `রিটার্ন ও ওয়ারেন্টি` / `Returns & warranty` |
| 14 | Reviews | hug | `spacing/4` | `ReviewCard` ×3 + summary | Summary block **132**: `price-lg` `৪.৭` + 5 stars + `৪২টি রিভিউ` + a 5-row histogram (each row 20: star label, `ProgressBar` Fill × 6, count). Then 3 `ReviewCard`s, then `Button/outline Size=md` Fill `সব ৪২টি রিভিউ দেখুন` / `See all 42 reviews` and `Button/primary Size=md` Fill `রিভিউ লিখুন` / `Write a review` |
| 15 | Age guidance | hug ~**160** | `spacing/4` | text + `AgeRangeIndicator` | `h3` `এই বয়সে কী শেখে` / `What this builds at this age` + 3 skill chips (`ফাইন মোটর` `কল্পনা` `সমস্যা সমাধান`) + 3 lines of plain copy |
| 16 | Frequently bought together | **272** | `spacing/4` | `FrequentlyBoughtTogether` | 3 × 72-tall rows with checkboxes (this item locked), a bundle price row `৳৬,৩৯০` vs `৳৭,১২০`, and `Button/primary Size=md` Fill `৩টি একসাথে কার্টে দিন` / `Add all 3 to cart`. **Only EduToyZone has bundles; nobody has FBT.** |
| 17 | Related products | **284** | `spacing/4` | rail of `Compact` | `h2` `একই ধরনের খেলনা` / `Similar toys` — 8 cards |
| 18 | Recently viewed | **284** | `spacing/4` | rail of `Compact` | `h2` `সম্প্রতি দেখেছেন` / `Recently viewed` — hidden when empty |
| 19 | Footer | ≈820 | — | Footer | B1.9 |
| — | `AddToCartBar` + `MobileNav` | 72 + 56 + inset, fixed | — | A16, A5 | Page bottom padding 136 |
| Gaps | `spacing/6` (24) between sections 3–12 (these are one continuous information block, not separate sections), `spacing/12` (48) between 12–18 | | | |

### B2.3 1440 section list

Two columns inside 1280: **gallery column 608** (left, `position: sticky; top: 136`) · gap **`spacing/8` (32)** · **details column 640** (right, scrolls). 608 + 32 + 640 = 1280.

| # | Section | Column | Height | Content |
|---|---|---|---|---|
| 0 | Header + MegaMenu bar | full | 120 sticky | B-global |
| 1 | Breadcrumb | full 1280 | **40**, `spacing/6` below | `হোম / বিল্ডিং ব্লক / লেগো / লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস ৪৮৪ পিস` |
| 2 | Gallery | left 608, sticky | 608 stage + `spacing/4` + 96 thumb rail = **720** | `ProductGallery Layout=DesktopRail`. **PDP LCP element = slot-1 image, 608 × 608, `priority`, ≤160 KB AVIF.** |
| 3 | Brand + title | right 640 | **100 fixed** | brand line 22 + `spacing/1` + `h1` 2-line clamp 78 (desktop 32/1.2). `bn` variant: 2 × 44 = 88 → block **110** |
| 4 | Rating | right | **28** | as 390 |
| 5 | Price block | right | **108 fixed** | rows 44 / 28 / 28 |
| 6 | Stock + cut-off | right | **56** | |
| 6b | Age block | right | **88** | |
| 7 | Variants | right | hug min **144** | |
| 8 | Quantity + CTAs | right | **64** | One row: `QuantityStepper` hug 44 · `Button/primary Size=lg` Fill 52 `কার্টে যোগ করুন` · `WishlistButton Context=Inline` 52. `এখনই কিনুন` is a second row, `Button/secondary` Fill 52 → block **128** |
| 9 | Delivery estimator | right | hug min **140** | A12 desktop variant |
| 10 | Trust strip | right | **96** | `Layout=Row4` |
| 11 | Short description | right | hug ~**200** | |
| 12 | Tabbed detail | **full 1280**, below both columns | hug | `Tabs` (not an accordion) — 4 tabs, 56-tall tab bar, bottom border 1px `color/border/default`, active tab 2px `color/bg/brand` underline; panel padding `spacing/8` 0, content capped at **840** for line length |
| 13 | Reviews | full 1280 | hug | Two columns: summary **400** sticky left (rating, histogram, write-review CTA) / review list **840** right |
| 14 | Age guidance | full 1280 | **200** | 3-column cards |
| 15 | Frequently bought together | full 1280 | **320** | Horizontal: 3 product cards with `+` glyphs between, then a 280-wide bundle-price panel right |
| 16 | Related | full 1280 | **616** | 4-up `Grid` cards |
| 17 | Recently viewed | full 1280 | **386** | `Compact` rail |
| 18 | Footer | full-bleed | ≈480 | |
| Sticky behaviour | The gallery column sticks at `top: 136` until the details column's end, then both scroll together. **No sticky add-to-cart bar on desktop** — the CTA is in the sticky-adjacent column and reachable, and a desktop sticky bar wastes 72px of a decision screen. | | | |

---

## B3. Category listing

Route: `/toys/building-blocks`. Filter state is in the URL per **A7.5** — this is the explicit gap we take from EduToyZone, whose filters are client-side with no `pushState`, so its filtered views cannot be shared on WhatsApp, bookmarked, or indexed by Google.

### B3.1 390 section list

| # | Section | Height | Padding | Components | Content |
|---|---|---|---|---|---|
| 0 | Header (sticky) | 56 | `spacing/4` h | Header | |
| 1 | Search row | 56 | `spacing/4` h | `SearchBar` | |
| 2 | Breadcrumb | **32** | `spacing/4` h | `Breadcrumb` | `হোম / বিল্ডিং ব্লক` |
| 3 | Page head | hug **112** | `spacing/4` | text | `h1` `বিল্ডিং ব্লক` / `Building Blocks` (32 fixed) · `body-sm` `color/text/secondary` **2-line clamp 42** intro `লেগো, ডুপ্লো আর কাঠের ব্লক — ১ বছর থেকে ১২ বছর পর্যন্ত। সব সেটে পিস সংখ্যা আর বয়স লেখা।` with a `আরও পড়ুন` / `Read more` expander (SEO copy, 600–900 characters, collapsed to 2 lines) |
| 4 | Sub-category chips | **44** | `spacing/4` h, horizontal scroll | `Chip` | `সব` (selected) · `লেগো` · `ডুপ্লো` · `কাঠের ব্লক` · `ম্যাগনেটিক` · `মেগা ব্লক` — these are navigation, visually distinct from filter chips (`Chip/outline` vs `Chip/filled`) |
| 5 | **Filter + sort bar (sticky)** | **56**, sticky at `top: 56` | `spacing/4` h | A7.4 trigger + `SortDropdown Context=Mobile` | Two Fill buttons, gap `spacing/3`. Filter button shows the active count badge |
| 6 | **Active-filter chips** | hug, 0 when empty | `spacing/4` h | `Chip` ×n | `বয়স: ২–৪ বছর ✕` · `ব্র্যান্ড: LEGO ✕` · `দাম: ৳১,০০০–২,৫০০ ✕` · `সব মুছুন`. Wraps to max 2 rows (32 each + `spacing/2`) → 0 / 40 / 80 |
| 7 | **Result count** | **24** | `spacing/4` h | text, `aria-live="polite"` | `২৪টি পণ্য` / `24 products` `body-sm` `color/text/secondary`. When filters are active: `২৪টি পণ্য · ৮৪টির মধ্যে` / `24 of 84 products` |
| 8 | Product grid | hug | `spacing/4` h | `ProductCard Layout=Grid` | 2 columns, gap `spacing/3`, 24 cards → 12 rows × 384 + 11 × 12 = **4,740**. While loading: 4 `ProductCardSkeleton` |
| 9 | Pagination | **88** | `spacing/4` | `Button` + `Pagination` | `Button/outline Size=lg` Fill × 52 `আরও ২৪টি দেখুন` / `Show 24 more` (also writes `?page=2`), with a `caption` `color/text/tertiary` below: `২৪ / ৮৪` . A real numbered `Pagination` is also rendered, visually hidden at 390 but present in the HTML for crawlers |
| 10 | Related categories | **172** | `spacing/4` h | `CategoryTile` rail | `h2` `সম্পর্কিত ক্যাটাগরি` / `Related categories` |
| 11 | Category FAQ | hug | `spacing/4` | `Accordion` | 4 questions, emitted as `FAQPage` schema. `২ বছরের শিশুর জন্য কোন ব্লক ভালো?` · `লেগো আসল কিনা কীভাবে বুঝব?` · `ব্লকের পিস হারিয়ে গেলে?` · `ডেলিভারি চার্জ কত?` — **only EduToyZone has FAQ schema; this makes it standard on every PLP** |
| 12 | Footer | ≈820 | — | Footer | |
| — | `MobileNav` | 56 + inset | | A5 | Bottom padding 112 |

### B3.2 1440 section list

| # | Section | Width | Height | Content |
|---|---|---|---|---|
| 0 | Header + MegaMenu | full | 120 sticky | |
| 1 | Breadcrumb | 1280 | **40** | |
| 2 | Page head | 1280 | **128** | `h1` 40 · intro `body` 2 lines, capped at **840** width for line length, no expander (full copy shown) |
| 3 | Sub-category chips | 1280 | **48** | Wrap, gap `spacing/3` |
| 4 | Toolbar | 1280 | **56** | Horizontal space-between: result count `body` `color/text/secondary` left (`aria-live`) · right cluster gap `spacing/4`: grid/list view toggle (2 × 40 IconButtons, `radius/md`, segmented) + `SortDropdown Context=Desktop` hug 40 |
| 5 | Active-filter chips | 1280 | hug 0 / 40 | A7.5 |
| 6 | Body | 1280 | hug | Horizontal, gap `spacing/8`: **`FilterPanel` sidebar 272 sticky at `top: 136`** · **grid 976**: 3 columns of 309, gap `spacing/6`, 24 cards → 8 rows × 552 + 7 × 24 = **4,584** |
| 7 | Pagination | 976 (grid column) | **72** | Numbered `Pagination` centred, plus `২৪–৪৮ / ৮৪` `body-sm` `color/text/tertiary` left |
| 8 | Related categories | 1280 | **220** | 4-up `CategoryTile Size=md` |
| 9 | Category FAQ | 1280 | hug | Two columns of 2 questions, 624 each, gap `spacing/8` |
| 10 | Footer | full-bleed | ≈480 | |

### B3.3 Zero-result filtered state (390 and 1440)

Replaces section 8 only; the filter bar, chips and count stay in place.

| Element | Content |
|---|---|
| Frame | Fill × **320**, `color/bg/surface`, `radius/lg`, 1px `color/border/default`, padding `spacing/8`, vertical gap `spacing/4`, centred |
| Illustration | 96 × 96, the one permitted empty-state illustration |
| Heading | `h3` `এই ফিল্টারে কোনো খেলনা নেই` / `No toys match these filters` |
| Body | `body-sm` `color/text/secondary` `বয়স বা দামের ফিল্টার একটু বদলে দেখুন।` / `Try widening the age or price filter.` |
| Actions | `Button/primary Size=md` `দামের ফিল্টার মুছুন` / `Clear the price filter` (names the single most restrictive active facet, computed) · `Button/outline Size=md` `সব ফিল্টার মুছুন` / `Clear all filters` |
| Below | `h3` `এই ক্যাটাগরির বেস্ট সেলার` / `Best sellers in this category` + an 8-card grid. **Never a dead end.** |

---

## B4. Checkout

Single route `/checkout`. **`MobileNav` and `WhatsAppFab` are both hidden here.** No illustration, no `radius/full` buttons — checkout buttons are `radius/md`. Guest checkout is the default and no account prompt appears before the order is placed.

### B4.1 390 section list

| # | Section | Height | Padding | Components | Content |
|---|---|---|---|---|---|
| 0 | Header (reduced) | **56** sticky | `spacing/4` h | — | Wordmark 112 centred · a `arrow-left` 44 back-to-cart left · a `lock` 16 + `caption` `নিরাপদ চেকআউট` / `Secure checkout` right. **No search, no nav, no cart icon** — nothing that leads out |
| 1 | Summary bar (sticky) | **56** sticky at `top: 56` | `spacing/4` h | `CartSummary Context=CheckoutMobile` | `shopping-bag` 20 · `body` `৩টি পণ্য · ৳৪,৩২০` / `3 items · ৳4,320` · `chevron-down`. Expands inline to hug (≈300) with the full row list and `CouponInput` |
| 2 | Progress | **40** | `spacing/4` h | — | 3 dots + labels, `caption`: `যোগাযোগ` · `ডেলিভারি` · `পেমেন্ট`. Completed dots `color/bg/brand` + `check`; it is a position indicator, **not** a navigation control |
| 3 | **Accordion §1 — Contact & delivery** | hug ≈ **680** open | `spacing/4` | `Accordion`, `Input`, `NativeSelect` | see B4.2 |
| 4 | **Accordion §2 — Delivery method & charge** | hug ≈ **280** open | `spacing/4` | `DeliveryEstimator` read-only, `Radio` | see B4.3 |
| 5 | **Accordion §3 — Payment & review** | hug ≈ **520** open | `spacing/4` | `Radio`, `CartLineItem Context=CheckoutReview` | see B4.4 |
| 6 | Coupon | hug 40 / 92 | `spacing/4` | `CouponInput` | A19, `Collapsed` |
| 7 | Totals | **180** | `spacing/4` | `CartSummary` rows | Subtotal · Discount · Delivery · Advance · divider · Total |
| 8 | Trust strip | **120** | `spacing/4` | `TrustStrip Theme=Plain Layout=Compact` | `ক্যাশ অন ডেলিভারি` · `৭ দিনে রিটার্ন` · `০৯৬১০-১২৩৪৫৬` |
| 9 | Policy links | **64** | `spacing/4` | text | `caption` `color/text/tertiary`: `অর্ডার করলে আপনি আমাদের শর্তাবলী ও রিটার্ন নীতিতে সম্মত হচ্ছেন।` with both as `color/text/link` |
| 10 | Footer (minimal) | **96** | `spacing/4` | — | Legal bar only |
| — | **Sticky place-order bar** | **80** fixed bottom + inset | `spacing/4` | `Button` | `Button/primary Size=lg` Fill × 56, `radius/md`: **`অর্ডার করুন · ৳৪,৩২০`** / **`Place order · ৳4,320`**. The amount is live and `aria-live="polite"`. Disabled only while a section has an unfixed error; disabled state carries a 20-tall `caption` above it naming the section: `ডেলিভারি ঠিকানা পূরণ করুন` / `Complete the delivery address` |
| Accordion behaviour | All three sections are **open on first load** (a single scrollable page — accordions are for *re-collapsing* a completed section, not for gating). On completing a section it collapses to a **64-tall summary row**: `check` 16 `color/status/in-stock` + section name + a 1-line `body-sm` `color/text/secondary` summary + `পরিবর্তন` / `Change` text button. Nothing is ever hidden behind a "next" button. |

### B4.2 Accordion §1 — Contact & delivery (field list, in order)

One field per row. All inputs **48** tall, `radius/md`, 1px `color/border/default`, `body`; labels **20** tall `body-sm` 600 above the input, gap `spacing/1`; a **20-tall reserved** helper/error line below each (36 in `bn`), so validation never reflows the form.

| # | Field | Type | Label (bn / en) | Placeholder / helper | Required | Validation message |
|---|---|---|---|---|---|---|
| 1 | Name | `Input text` | `আপনার নাম` / `Your name` | `যেমন: সাবরিনা আক্তার` | Yes | `নাম লিখুন` / `Please enter your name` |
| 2 | Phone | `Input tel`, `inputmode="numeric"`, `maxlength=11`, auto-formats `01712-345678` | `মোবাইল নম্বর` / `Mobile number` | helper `অর্ডার আপডেট এই নম্বরে SMS করা হবে` / `We'll SMS order updates to this number` | **Yes — the primary identifier** | `০১ দিয়ে শুরু ১১ ডিজিটের নম্বর লিখুন` / `Enter an 11-digit number starting with 01`; unknown operator prefix → `এই অপারেটর প্রিফিক্সটি চিনি না — আবার দেখুন` / `We don't recognise that operator prefix — please check` |
| 3 | Email | `Input email` | `ইমেইল (ঐচ্ছিক)` / `Email (optional)` | helper `ইনভয়েস পেতে চাইলে দিন` / `Only if you want the invoice by email` | **No** | `ইমেইল ঠিকানাটি সঠিক নয়` / `That email address isn't valid` |
| 4 | District | `NativeSelect`, 64 options, `ঢাকা বিভাগ` group first | `জেলা` / `District` | `জেলা নির্বাচন করুন` | Yes | `জেলা নির্বাচন করুন` / `Please select a district` |
| 5 | Upazila / Thana | `NativeSelect`, **disabled until district is set**, repopulated on change, searchable at >20 options | `উপজেলা / থানা` / `Upazila / Thana` | `উপজেলা নির্বাচন করুন` | Yes | `উপজেলা নির্বাচন করুন` |
| 6 | Area | `NativeSelect` + a `অন্য এলাকা` / `Other area` option that reveals a text input | `এলাকা` / `Area` | `এলাকা নির্বাচন করুন` | Yes | `এলাকা নির্বাচন করুন` |
| 7 | Full address | `Textarea` **80** tall, 2 rows | `সম্পূর্ণ ঠিকানা` / `Full address` | `বাসা/রোড/ব্লক — যেমন: বাসা ১২, রোড ৫, ব্লক সি` | Yes | `ঠিকানা লিখুন` / `Please enter the address` |
| 8 | **Landmark** | `Input text` | `ল্যান্ডমার্ক` / `Landmark` | `কোন দোকানের পাশে / কোন তলা — যেমন: আগোরার পাশে, ৩য় তলা` / `Beside which shop / which floor` | No, but **visually encouraged** with a `caption` `color/text/secondary`: `এটা দিলে ডেলিভারি ম্যান সহজে খুঁজে পায়` / `This helps the delivery rider find you` | — |
| 9 | Save address | `Checkbox` 44 row | `পরের বার ব্যবহারের জন্য ঠিকানা সেভ করুন` / `Save this address for next time` | checked by default; stores locally for guests | — | — |
| 10 | Order note | `Accordion` row 44 → `Textarea` 72 | `অর্ডার সম্পর্কে কিছু বলার আছে?` / `Anything to tell us about this order?` | collapsed | No | — |
| Cascade behaviour | District → upazila → area, each select repopulating the next and clearing any downstream value. The last-used address is remembered and **pre-fills all three selects**, so a returning buyer touches nothing. Changing the district re-renders §2's charge with `aria-live="polite"`. |
| Section total | 20 + 10 × (20 + 48 + 20 + `spacing/4`) ≈ **680** open; collapsed summary **64** reading `সাবরিনা আক্তার · ০১৭১২-৩৪৫৬৭৮ · ধানমন্ডি, ঢাকা` |

### B4.3 Accordion §2 — Delivery method & charge

| # | Element | Height | Content |
|---|---|---|---|
| 1 | Charge block | **96** | `DeliveryEstimator` read-only: `price` `৳৭০` · `body` `১০ নভেম্বর – ১১ নভেম্বর এর মধ্যে পৌঁছাবে` · `caption` `কুরিয়ার: স্টেডফাস্ট`. **Identical to the PDP figure for this address — one function, one zone table (A12.4).** A `caption` `color/text/tertiary` states it: `পণ্যের পাতায় দেখানো চার্জই এখানে` / `The same charge you saw on the product page` |
| 2 | Method radios | 2 × **64** | `Radio` rows, `radius/md`, 1px `color/border/default`, selected 2px `color/bg/brand` + `color/bg/brand-subtle`: **`স্ট্যান্ডার্ড ডেলিভারি · ৳৭০ · ১–২ দিন`** (pre-selected) and **`এক্সপ্রেস ডেলিভারি · ৳১৫০ · আজই (ঢাকা মেট্রো, বিকেল ২টার আগে অর্ডার)`**. Express renders only where available and is disabled with a visible reason elsewhere: `আপনার এলাকায় এক্সপ্রেস নেই` / `Express isn't available in your area` |
| 3 | Free-delivery line | **24** | When met: `caption` `color/status/in-stock` `৳৩,০০০+ অর্ডারে ডেলিভারি ফ্রি — চার্জ বাদ দেওয়া হয়েছে` / `Free over ৳3,000 — charge removed` |
| Section total | ≈ **280** open; collapsed summary `স্ট্যান্ডার্ড · ৳৭০ · ১০–১১ নভেম্বর` |

### B4.4 Accordion §3 — Payment & review

| # | Element | Height | Content |
|---|---|---|---|
| 1 | **COD option — first and pre-selected** | **96** | `Radio` card, selected by default, 2px `color/bg/brand`, `color/bg/brand-subtle`: `body` 600 `ক্যাশ অন ডেলিভারি` / `Cash on delivery` · `body-sm` `color/text/secondary` `পণ্য হাতে পেয়ে টাকা দিন` / `Pay the rider when it arrives` · a `banknote` 24 left. **COD is listed first and pre-selected per §6.1.** |
| 1b | COD advance note | **44**, only when required | Inside the COD card: `caption` `color/status/low-stock` `ঢাকার বাইরে ৳৫,০০০+ অর্ডারে ৳২০০ অগ্রিম — bKash এ পাঠাতে হবে` / `Orders over ৳5,000 outside Dhaka need a ৳200 advance by bKash`. **Already stated on the product page (A12.4); it is never first revealed here.** |
| 2 | Online payment | **88** | `Radio` card: `body` 600 `অনলাইনে পেমেন্ট` / `Pay online` · `body-sm` `কার্ড, bKash, Nagad, Rocket` · a 32-tall row of greyscale method logos (bKash, Nagad, Rocket, Visa, Mastercard) that colourise when the radio is selected. Selecting it reveals a 44-tall `caption` line: `অর্ডার করার পর নিরাপদ পেমেন্ট পাতায় যাবেন` / `You'll go to a secure payment page after placing the order` |
| 3 | Review list | hug, 3 × **96** | `CartLineItem Context=CheckoutReview` — image 72, title 2-line clamp, variant + qty `caption`, line total right. No stepper. A `কার্ট সম্পাদনা` / `Edit cart` text button at the top-right of the block |
| 4 | Reassurance | **48** | `caption` `color/text/secondary` + `shield-check` 16: `আপনার নম্বর শুধু অর্ডার আর ডেলিভারির জন্য ব্যবহার করা হয়।` / `We use your number only for this order and its delivery.` |
| Section total | ≈ **520** open |

### B4.5 1440 section list

| # | Section | Width | Height | Content |
|---|---|---|---|---|
| 0 | Header (reduced) | full | **72** | Wordmark left · 3-step progress centred (3 × 32 markers + labels, 320 wide) · `lock` + `নিরাপদ চেকআউট` + hotline right |
| 1 | Body | 1280 | hug | Horizontal, gap `spacing/12` (48), align top: **form column 760** · **summary rail 440** `position: sticky; top: 104` |
| 1a | Form column | 760 | hug | The three accordions of B4.2–B4.4. **Two fields per row** where they pair naturally and only there: Name + Phone (368 each, gap `spacing/6`), District + Upazila, Area + Landmark. Full address and order note are full-width. Every other rule is identical to 390. |
| 1b | Summary rail | 440 | hug ≈ **620** | `CartSummary Context=CheckoutDesktop`: heading · 3 `CartLineItem Context=CheckoutReview` compact rows (72 each) · divider · row list · `CouponInput Collapsed` · divider · total · **`Button/primary Size=lg` Fill × 56 `radius/md` `অর্ডার করুন · ৳৪,৩২০`** · `TrustStrip Theme=Plain` · policy caption. The button is inside the sticky rail, so no floating bottom bar is needed. |
| 2 | Footer (minimal) | full-bleed | **96** | Legal bar + the four policy links |

### B4.6 Failure handling

| Case | Behaviour |
|---|---|
| Validation error on submit | The page scrolls to the **first** invalid field, focuses it, and expands its section if collapsed. Each error is in the reserved 20/36-tall line, `color/status/out-of-stock`, `aria-describedby`, `aria-invalid`, with an `alert-circle` 16 — **never colour alone**. A summary `Alert/danger` appears above the first accordion listing the fields as jump-links. |
| Payment failure | The form state **survives** in full (fields, selects, coupon, chosen method). An `Alert/danger` at the top: `পেমেন্ট সম্পন্ন হয়নি — আপনার টাকা কাটা হয়নি। আবার চেষ্টা করুন বা ক্যাশ অন ডেলিভারি বেছে নিন।` / `The payment didn't go through — you haven't been charged. Try again, or switch to cash on delivery.` with two buttons: `আবার চেষ্টা করুন` / `Try again` and `ক্যাশ অন ডেলিভারিতে বদলান` / `Switch to cash on delivery`. The order is held, not lost. |
| Stock changed mid-checkout | Inline `Alert/warning` above the review list naming the item and the new quantity, total re-announced via `aria-live`; the place-order button stays enabled with the corrected amount. |
| Duplicate submit | Button → `loading` and `aria-disabled` on first press; an idempotency key prevents a second order. |

---

## B5. Cart and empty cart

### B5.1 Cart — 390

| # | Section | Height | Padding | Components | Content |
|---|---|---|---|---|---|
| 0 | Header | 56 sticky | `spacing/4` h | Header | |
| 1 | Page head | **56** | `spacing/4` | text | `h1` `কার্ট` / `Your cart` + `body-sm` `color/text/secondary` `৩টি পণ্য` / `3 items` |
| 2 | Free-delivery progress | **64** | `spacing/4` | `ProgressBar` | `color/bg/brand-subtle`, `radius/md`, padding `spacing/3`: `ProgressBar` Fill × 6 + `body-sm` `আরও ৳৫৫০ কিনলে ডেলিভারি ফ্রি` / `Add ৳550 more for free delivery` + `আরও দেখুন` / `Browse more` text button. **EduToyZone has this; it is table stakes, not a differentiator — but omitting it loses basket size.** |
| 3 | Line items | hug | `spacing/4` h | `CartLineItem` ×3 | 3 × ≈120 + 2 dividers → **364** |
| 4 | Delivery row | **72** | `spacing/4` | `DeliveryEstimator` read-only | `truck` 20 · `body-sm` `ধানমন্ডি, ঢাকা — ৳৭০, ১০–১১ নভেম্বর` · `পরিবর্তন করুন` / `Change` text button opening a 280-tall sheet |
| 5 | Coupon | 40 / 92 | `spacing/4` | `CouponInput` | `Collapsed` |
| 6 | Summary | **212** | `spacing/4` | `CartSummary` | Row list + divider + total + the `ক্যাশ অন ডেলিভারিতে পরিশোধ` caption. **No CTA here** — it lives in the sticky bar |
| 7 | Trust strip | **136** | `spacing/4` | `TrustStrip Grid2x2` | |
| 8 | You might also like | **284** | `spacing/4` h | `Compact` rail | `h2` `এগুলোও দেখুন` / `You might also like` — 8 cards, age-matched to the cart's contents |
| 9 | Footer | ≈820 | — | Footer | |
| — | **Sticky total bar** | **72** fixed, bottom offset **56** (above `MobileNav`) | `spacing/4` | A18 | stack {`caption` `সর্বমোট`, `price-lg` `৳৪,৩২০`} · `chevron-up` 44 · `Button/primary Size=lg` Fill × 52 `চেকআউটে যান` / `Proceed to checkout` |
| — | `MobileNav` | 56 + inset | | A5, `Active=Cart` | Page bottom padding **144** = sticky total bar 72 + nav 56 + `spacing/4` |

### B5.2 Cart — 1440

| # | Section | Width | Height | Content |
|---|---|---|---|---|
| 0 | Header + MegaMenu | full | 120 sticky | |
| 1 | Breadcrumb + head | 1280 | **40 + 56** | `হোম / কার্ট` · `h1` `কার্ট (৩টি পণ্য)` |
| 2 | Body | 1280 | hug | Horizontal, gap `spacing/8`: **items column 808** · **summary rail 440** sticky `top: 136` |
| 2a | Items column | 808 | hug | Free-delivery progress **64** · `CartLineItem` ×3 (136 each + dividers) · a 56-tall footer row: `কেনাকাটা চালিয়ে যান` / `Continue shopping` text button left, `কার্ট খালি করুন` / `Empty cart` text button right (requires a `ConfirmDialog`) |
| 2b | Summary rail | 440 | ≈ **560** | A18 `CartPageDesktop`: heading · rows · delivery row with `Change` · `CouponInput` · divider · total · `Button/primary Size=lg` Fill 52 `চেকআউটে যান` · `TrustStrip Theme=Plain` · payment-logo row |
| 3 | You might also like | 1280 | **616** | 4-up `Grid` cards |
| 4 | Recently viewed | 1280 | **386** | `Compact` rail |
| 5 | Footer | full-bleed | ≈480 | |

### B5.3 Empty cart

**Krystal — the competitor with the best checkout — has no cart page at all; `/cart/` 404s.** This screen is the cheapest possible win over it.

| Element | 390 | 1440 |
|---|---|---|
| Frame | Fill × **hug**, centred, padding `spacing/8` `spacing/4` | **640** centred, padding `spacing/16` 0 |
| 1 Illustration | **160 × 160** — an open, empty toy box with one block beside it, two-tone brand + accent. The one place playfulness is allowed in the purchase flow, because there is no decision being made here | **200 × 200** |
| 2 Heading | `h1` `আপনার কার্ট খালি` / `Your cart is empty`, centred, 32 | `h1` 40 |
| 3 Body | `body` `color/text/secondary`, centred, 2-line box **48**: `বয়স অনুযায়ী খেলনা খুঁজে শুরু করুন — ঢাকায় ২৪ ঘণ্টায় পৌঁছে যাবে।` / `Start with your child's age — toys reach Dhaka addresses in 24 hours.` | **56** |
| 4 Primary CTA | `Button/primary Size=lg` Fill × 52 `radius/full` `সব খেলনা দেখুন` / `Browse all toys` | hug × 52, centred |
| 5 Age chooser | **36**-tall horizontal scroll row of the 7 age pills from B1.1 — the same component, so the empty cart is a working entry point, not an apology | **40**, wrapped, centred |
| 6 Saved items | When the wishlist is non-empty: `h3` `আপনার পছন্দের তালিকা` / `Your wishlist` + 2-up `Grid` cards (up to 4) with an `কার্টে নিন` / `Move to cart` button per card | 4-up |
| 7 Best sellers | `h2` `সবচেয়ে বেশি বিক্রি` / `Best sellers` + 2-up grid, 4 cards | 4-up grid, 4 cards |
| 8 Recently viewed | `Compact` rail when non-empty | rail |
| Section total | ≈ **1,120** with sections 6–8 | ≈ **1,280** |
| Chrome | `MobileNav Active=Cart`, badge hidden at 0 | normal header |

---

## B6. Search results and zero results

### B6.1 Search results — 390

| # | Section | Height | Padding | Components | Content |
|---|---|---|---|---|---|
| 0 | Header | 56 sticky | `spacing/4` h | Header | |
| 1 | Search row | **56** sticky at `top: 56` | `spacing/4` h | `SearchBar Context=SearchPageMobile State=HasQuery` | Query retained and editable, `x` clear visible |
| 2 | Result head | **56** | `spacing/4` | text, `aria-live="polite"` | `h1` at `h2` size: `"পাজল" — ৩৭টি ফলাফল` / `37 results for "puzzle"`. When a correction was applied: a second **24**-tall line `caption` `color/text/secondary` `"puzle" এর বদলে "পাজল" দেখানো হচ্ছে — ` + `"puzle" ই খুঁজুন` / `Showing results for "পাজল" instead of "puzle" — search for "puzle" instead` |
| 3 | Scope chips | **44** | `spacing/4` h, scroll | `Chip` | `সব (৩৭)` selected · `পণ্য (৩৭)` · `ক্যাটাগরি (২)` · `ব্র্যান্ড (১)` · `ব্লগ (৩)` |
| 4 | Filter + sort bar | **56** sticky at `top: 112` | `spacing/4` h | A7.4 + `SortDropdown` | `Value=Relevance` by default here, not `Popular` |
| 5 | Active-filter chips | hug 0/40/80 | `spacing/4` h | `Chip` | A7.5 |
| 6 | Matched category / brand strip | **96**, shown only when a category or brand matched | `spacing/4` h | `CategoryTile` rail | `caption` `color/text/tertiary` `ক্যাটাগরি` + 2 × `CategoryTile Size=sm` 148 × 72 compacted — a category match jumps the visitor to a better page than a result list |
| 7 | Grid | hug | `spacing/4` h | `ProductCard Layout=Grid` | 2 columns × 37 results, paginated 24 at a time |
| 8 | Pagination | **88** | `spacing/4` | `Button` | `আরও ১৩টি দেখুন` / `Show 13 more` |
| 9 | Blog matches | **220**, when present | `spacing/4` h | blog card rail | `h2` `গাইড ও লেখা` / `Guides & articles` |
| 10 | Footer | ≈820 | — | Footer | |
| Search behaviour | 250ms debounce, min 2 characters, typo-tolerant, Banglish-equivalent (`গাড়ি` / `gari` / `car` → one set). **0 of 5 testable competitors survived a one-character typo** — `puzle` and `lgo` both returned "No products were found". Our correction line is therefore a first-visit, visible win. URL `?q=পাজল&corrected_from=puzle`, server-rendered. |

### B6.2 Search results — 1440

Identical to B3.2 with: the search bar in the header carrying the query; section 2's head at `h1` 40 tall + a 24 correction line; the scope chips at **48**; the matched category/brand strip as a **112**-tall full-width row of 4 `CategoryTile Size=sm`; the sidebar `FilterPanel` present; grid 3 × 309 in 976; and blog matches as a 3-up row of 410-wide cards.

### B6.3 Zero results

Three recoveries, in this order, because a dead end is the one unacceptable outcome.

| # | Element | 390 | Content |
|---|---|---|---|
| 0–1 | Header + search row | 56 + 56 | Query retained in the bar, selected, so the next keystroke replaces it |
| 2 | Head | **80** | `h1` at `h2` size `"হেলিকপ্তার" এর জন্য কিছু পাওয়া যায়নি` / `Nothing found for "helicoptar"` (24 reserved for a 2-line query) |
| 3 | **Correction** | **hug, ≈104** | `color/bg/brand-subtle`, `radius/lg`, padding `spacing/4`: `body` `আপনি কি "হেলিকপ্টার" খুঁজছেন?` / `Did you mean "helicopter"?` + `Button/primary Size=md` hug `হ্যাঁ, দেখান` / `Yes, show me` + up to 2 further suggestions as `Chip`s. Suggestions come from the search index's typo and synonym tables, not from a hand-written list |
| 4 | **Nearest category** | **hug, ≈168** | `h3` `সবচেয়ে কাছের ক্যাটাগরি` / `Closest category` + 2 × `CategoryTile Size=sm` 173 × 128: `গাড়ি ও রিমোট` (112) and `আউটডোর ও রাইড-অন` (41), chosen by vector proximity to the query |
| 5 | **Best sellers** | **hug, ≈812** | `h2` `সবচেয়ে বেশি বিক্রি` / `Best sellers` + 2-up grid, 4 cards (2 rows of 384 + gap) |
| 6 | Browse by age | **hug, ≈84** | `h3` `বয়স অনুযায়ী দেখুন` / `Browse by age` + the 7 age pills |
| 7 | Ask a human | **hug, ≈120** | `color/bg/subtle`, `radius/lg`, padding `spacing/4`: `body-sm` `যা খুঁজছেন পাচ্ছেন না? হোয়াটসঅ্যাপে বলুন, আমরা খুঁজে দেব।` / `Can't find it? Tell us on WhatsApp and we'll find it.` + `Button/secondary Size=md` Fill `হোয়াটসঅ্যাপে বলুন` / `Ask on WhatsApp` + a `caption` `সাধারণত ১০ মিনিটে উত্তর` |
| 8 | Footer | ≈820 | |
| Illustration | A single 120 × 120 illustration sits beside section 2's heading (right-aligned at 390, inline at 1440) — a magnifying glass over an empty toy bin. **One illustration only**; sections 3–7 are plain. |
| 1440 | Same order; section 3 is a 1280-wide band 96 tall, section 4 is a 4-up `CategoryTile` row, section 5 is a 4-up `Grid` row (616), sections 6–7 sit side by side as two 624 columns |
| a11y | The result count is `aria-live="polite"`: `কোনো ফলাফল নেই` / `No results`; the correction link is the first focusable element after the search input |
| Logging | Every zero-result query is logged and surfaced in the admin so the Meilisearch synonym set can be seeded from real search logs in month one (§6.3) |

---

## B7. Order tracking

Route `/track`. No login. Two fields: order number + the **last 4 digits of the phone** — enough to be useful, too little to enumerate. **EduToyZone has `/findorder`; Kids Republic's tracking page 404s; no competitor exposes shipment-level tracking.**

### B7.1 Lookup form

| # | Element | 390 | 1440 |
|---|---|---|---|
| 0 | Header | 56 sticky | 120 sticky |
| 1 | Page head | **hug ≈ 108**, padding `spacing/4`: `h1` `অর্ডার ট্র্যাক করুন` / `Track your order` (32) + `body-sm` `color/text/secondary` 2 lines **42**: `অর্ডার নম্বর আর মোবাইল নম্বরের শেষ ৪ ডিজিট দিন। লগইন লাগবে না।` / `Enter your order number and the last 4 digits of your phone. No login needed.` | centred at **480**, `h1` 40 |
| 2 | Card | Fill × **hug ≈ 300**, `color/bg/surface`, `radius/lg`, 1px `color/border/default`, padding `spacing/5`, vertical gap `spacing/4` | **480** centred, padding `spacing/6` |
| 2a | Order number | label `অর্ডার নম্বর` / `Order number` (20) · `Input` 48, `inputmode="numeric"`, prefix chip `TMB-` inside the input's left padding, placeholder `২৬১১০৪৫৭` · reserved error line 20/36 | same |
| 2b | Last 4 digits | label `মোবাইল নম্বরের শেষ ৪ ডিজিট` / `Last 4 digits of your phone` (20, **2-line box 40 in `bn`**) · `Input` 48, `inputmode="numeric"`, `maxlength=4`, `autocomplete="off"`, placeholder `৫৬৭৮` · helper `caption` `যে নম্বরে অর্ডার করেছিলেন` / `The number you ordered with` | same |
| 2c | Submit | `Button/primary Size=lg` Fill × 52 `radius/md` `অর্ডার দেখুন` / `Find my order` | same |
| 2d | Fallbacks | Two text buttons, 44 each: `অর্ডার নম্বর মনে নেই?` / `Don't have your order number?` (→ reveals a 96-tall block: full phone number + an SMS OTP) and `হোয়াটসঅ্যাপে জিজ্ঞেস করুন` / `Ask on WhatsApp` | same |
| 3 | Help strip | `TrustStrip Theme=Plain Layout=Compact` **120** | **96** |
| 4 | Footer | ≈820 | ≈480 |
| Errors | Not found → `Alert/warning` 72 inside the card: `এই অর্ডার নম্বর আর ডিজিট মিলছে না। SMS এ পাঠানো নম্বরটি আবার দেখুন।` / `That order number and those digits don't match. Please check the number in your SMS.` · Rate limited after 5 attempts in 10 minutes → `অনেকবার চেষ্টা হয়েছে — ১০ মিনিট পর আবার দেখুন, বা আমাদের ফোন করুন ০৯৬১০-১২৩৪৫৬` / `Too many attempts — try again in 10 minutes, or call us on 09610-123456` · Empty field → the reserved line, `aria-describedby` |

### B7.2 Result view

| # | Section | 390 | 1440 |
|---|---|---|---|
| 1 | Status header | **hug ≈ 140**, `color/bg/brand-subtle`, `radius/lg`, padding `spacing/5`, vertical gap `spacing/2`: `caption` `color/text/secondary` `অর্ডার TMB-২৬১১০৪৫৭` · `h2` `পথে আছে` / `Out for delivery` · `body-sm` `আজ সন্ধ্যা ৬টার মধ্যে পৌঁছানোর কথা` / `Expected by 6 pm today` · `caption` `কুরিয়ার: স্টেডফাস্ট · CN-৪৪৭১৯৮২` with a `কুরিয়ারে ট্র্যাক করুন` / `Track with the courier` link | **640** centred, `h1` for the status |
| 2 | **Timeline** | `OrderTimeline Orientation=Vertical`, hug ≈ **480** | **640**, hug |
| 3 | Items | `CartLineItem Context=CheckoutReview` ×n, 96 each | 640 |
| 4 | Delivery details | **hug ≈ 180**, `color/bg/surface`, `radius/lg`, 1px border, padding `spacing/5`: name · masked phone `০১৭১২-৩৪৫৬**` · full address + landmark · delivery charge · payment method `ক্যাশ অন ডেলিভারি · ৳৪,৩২০ দিতে হবে` / `Cash on delivery · ৳4,320 due` | 640 |
| 5 | Actions | Vertical gap `spacing/3`: `Button/secondary Size=lg` Fill `ডেলিভারি ম্যানকে ফোন করুন` / `Call the delivery rider` (only in `Out for delivery`) · `Button/outline Size=lg` Fill `ইনভয়েস ডাউনলোড` / `Download invoice` · `Button/ghost Size=lg` Fill `অর্ডার বাতিল করুন` / `Cancel this order` (only before `Packed`, opens a `ConfirmDialog` requiring the order number to be typed) · text button `সমস্যা জানান` / `Report a problem` | Horizontal row of 3 + the ghost button below |
| 6 | Review prompt | Shown only in `Delivered`: **hug ≈ 160**, `color/bg/accent-subtle`, `radius/lg`: `h3` `কেমন লাগল?` / `How was it?` + per-item 5-star interactive `Rating` rows (56 each) + `Button/primary Size=md` `রিভিউ পাঠান` / `Send review`. **This is the mechanism that produces the review volume no competitor has.** | 640 |
| 7 | Help | `TrustStrip Theme=Plain` + `হোয়াটসঅ্যাপে সাহায্য` / `Help on WhatsApp` | |
| Refresh | Status polls every 60 s while the tab is visible; a changed step animates in and announces via `aria-live="polite"`. The timestamp of the last refresh is a `caption` under the timeline: `সর্বশেষ আপডেট: ২:১৪ অপরাহ্ণ` / `Last updated 2:14 pm` |

---

# PART C — Real content

No lorem ipsum appears anywhere in this specification or in the Figma file built from it. A screen built with placeholder text hides exactly the problems this document exists to prevent: Bangla line counts, price column widths, two-line title clamps and button label overflow.

## C1. Catalogue — 16 products

Every field below is final. `Age` is stored **in months** (the single source of truth; A11 derives the displayed label). `Stock` is units on hand. `Rating` is `score / review count`. SKU pattern `TMB-{BRAND3}-{SLUG}`.

| # | English name | Bangla name | Brand | Category | Age (months) | Price ৳ | Was ৳ | Disc. | Stock state | Rating | SKU |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | LEGO Classic Creative Bricks 484 pcs | লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস ৪৮৪ পিস | LEGO | Building Blocks | 48–144 | **4,250** | 4,990 | −15% | `InStock` (23) | 4.7 / 42 | `TMB-LEG-CL484` |
| 2 | Fisher-Price Laugh & Learn Smart Stages Puppy | ফিশার-প্রাইস স্মার্ট স্টেজেস মিউজিক্যাল পাপি | Fisher-Price | Baby & Toddler | 6–36 | **3,180** | 3,650 | −13% | `LowStock` (4) | 4.8 / 31 | `TMB-FIS-SSPUP` |
| 3 | Hot Wheels 10-Car Gift Pack | হট হুইলস ১০টি গাড়ির গিফট প্যাক | Hot Wheels | Cars & Remote Control | 36–120 | **1,790** | — | — | `InStock` (41) | 4.6 / 58 | `TMB-HOT-10PK` |
| 4 | Hape Pound & Tap Bench with Xylophone | হেপ পাউন্ড অ্যান্ড ট্যাপ বেঞ্চ (জাইলোফোন সহ) | Hape | Baby & Toddler | 12–36 | **2,950** | 3,400 | −13% | `InStock` (12) | 4.9 / 27 | `TMB-HAP-PTB` |
| 5 | Mideer Wooden Shape Sorting Puzzle | মিডির কাঠের শেপ সর্টিং পাজল | Mideer | Puzzles & Games | 18–48 | **1,250** | — | — | `LowStock` (3) | 4.5 / 19 | `TMB-MID-SHSORT` |
| 6 | Huanger Baby Activity Gym & Play Mat | হুয়াংগার বেবি অ্যাক্টিভিটি জিম ও প্লে ম্যাট | Huanger | Baby & Toddler | 0–12 | **2,390** | 2,850 | −16% | `InStock` (18) | 4.4 / 36 | `TMB-HUA-GYM` |
| 7 | Bruder MAN TGA Dump Truck 1:16 | ব্রুডার MAN TGA ডাম্প ট্রাক ১:১৬ | Bruder | Cars & Remote Control | 36–144 | **5,650** | — | — | `InStock` (7) | 4.9 / 14 | `TMB-BRU-MANDT` |
| 8 | Rubik's Cube 3×3 Original | রুবিক্স কিউব ৩×৩ অরিজিনাল | Rubik's | Puzzles & Games | 96–192 | **890** | 1,150 | −23% | `InStock` (56) | 4.7 / 73 | `TMB-RUB-3X3` |
| 9 | Play-Doh Rainbow Starter 10-Pack | প্লে-ডো রেইনবো স্টার্টার ১০ প্যাক | Play-Doh | Art & Craft | 24–96 | **1,120** | — | — | `InStock` (33) | 4.6 / 45 | `TMB-PLA-RB10` |
| 10 | Barbie Dreamhouse Pool Party Doll | বার্বি ড্রিমহাউস পুল পার্টি ডল | Barbie | Dolls & Soft Toys | 36–120 | **2,480** | 2,950 | −16% | `OutOfStock` (0) | 4.3 / 22 | `TMB-BAR-DHPP` |
| 11 | Clementoni Galileo My First Microscope | ক্লেমেন্টনি গ্যালিলিও মাই ফার্স্ট মাইক্রোস্কোপ | Clementoni | Science & Learning | 72–144 | **3,890** | — | — | `InStock` (9) | 4.5 / 11 | `TMB-CLE-MICRO` |
| 12 | Little Tikes First Slide | লিটল টাইকস ফার্স্ট স্লাইড | Little Tikes | Outdoor & Ride-On | 18–60 | **7,450** | 8,900 | −16% | `LowStock` (2) | 4.8 / 17 | `TMB-LIT-SLIDE` |
| 13 | Intex Dinosaur Inflatable Kids Pool | ইনটেক্স ডাইনোসর ইনফ্ল্যাটেবল কিডস পুল | Intex | Outdoor & Ride-On | 24–72 | **1,650** | — | — | `InStock` (27) | 4.2 / 29 | `TMB-INT-DINOP` |
| 14 | Crayola Washable Super Tips 20 Markers | ক্রেয়োলা ওয়াশেবল সুপার টিপস ২০ মার্কার | Crayola | Art & Craft | 36–120 | **760** | — | — | `InStock` (64) | 4.7 / 51 | `TMB-CRA-ST20` |
| 15 | Winfun Baby Walker Push Car | উইনফান বেবি ওয়াকার পুশ কার | Winfun | Baby & Toddler | 9–24 | **4,120** | 4,750 | −13% | `InStock` (15) | 4.4 / 24 | `TMB-WIN-PUSHW` |
| 16 | Melissa & Doug Wooden Doctor Kit | মেলিসা অ্যান্ড ডাগ কাঠের ডক্টর কিট | Melissa & Doug | Dolls & Soft Toys | 36–96 | **2,760** | — | — | `InStock` (11) | 4.6 / 33 | `TMB-MEL-DOCK` |

### C1.1 Derived values the layouts need

| Field | Rule | Example |
|---|---|---|
| Discount badge | `round((was − price) / was × 100)` → `−১৫%` / `−15%` | product 1 |
| Savings line | `was − price` → `৳৭৪০ সাশ্রয়` / `Save ৳740` | product 1 |
| Age label | A11 derivation | 48–144 → `৪–১২ বছর` / `4–12 years` |
| Safety line | lower bound < 36 months → the A11 safety row | products 2, 4, 5, 6, 12, 15 |
| Free delivery | price ≥ 3,000 → a single-item order already qualifies | products 1, 2, 7, 11, 12, 15 |
| COD advance | price > 5,000 **and** zone = `Outside` → ৳200 | products 7, 12 |
| Low-stock pill | stock ≤ 5 → `মাত্র {n}টি বাকি` / `Only {n} left` | products 2, 5, 12 |
| Bestseller badge | top 8 by units sold | 3, 1, 9, 2, 14, 5, 4, 8 |
| New badge | added in the last 21 days | 11, 7, 16, 13, 6, 15, 12, 10 |

### C1.2 Variant axes used in the layouts

| Product | Axis 1 | Axis 2 | Unavailable combination (for the A10.3 reason line) |
|---|---|---|---|
| 1 LEGO Classic | `সেট` / `Set`: ৯০ পিস ৳১,৪৫০ · ২১৩ পিস ৳২,৬৫০ · ৪৮৪ পিস ৳৪,২৫০ | `প্যাকিং` / `Packaging`: বক্স · টাব | টাব is out of stock in every set → `টাব প্যাকিং এখন স্টকে নেই` / `Tub packaging is out of stock right now` |
| 3 Hot Wheels | `সেট` / `Set`: ৫টি ৳৯৫০ · ১০টি ৳১,৭৯০ · ২০টি ৳৩,৩৫০ | — | ২০টি → `২০টির প্যাক ১৮ নভেম্বর স্টকে আসবে` / `The 20-car pack arrives 18 November` |
| 12 Little Tikes First Slide | `রঙ` / `Colour`: লাল-নীল · সবুজ-হলুদ · গোলাপি | — | গোলাপি → `গোলাপি রঙ এখন স্টকে নেই` / `Pink is out of stock right now` |
| 13 Intex Dinosaur Pool | `সাইজ` / `Size`: ছোট (১১৪ সেমি) · বড় (১৫২ সেমি) | — | — |
| 14 Crayola Super Tips | `সেট` / `Set`: ১২টি ৳৫২০ · ২০টি ৳৭৬০ · ৫০টি ৳১,৬৮০ | — | ৫০টি → `৫০টির সেটে এখন স্টক নেই — ২০টি বেছে নিন` / `The 50-marker set is out of stock — pick 20` |

### C1.3 Review content used in B2 section 14

| # | Name | Date | Rating | Context line | Body (Bangla, as a real customer writes) |
|---|---|---|---|---|---|
| 1 | সাবরিনা আক্তার | ১২ অক্টোবর ২০২৬ | ৫ | কিনেছেন: ৪৮৪ পিস · বক্স · সন্তানের বয়স ৫ বছর | বক্সে হলোগ্রাম ছিল, আসল লেগো। ছেলে তিন দিন ধরে এটা নিয়েই আছে। বেস প্লেটটা থাকলে আরও ভালো হতো, আলাদা কিনতে হলো। ঢাকার ভেতরে এক দিনে পেয়েছি। |
| 2 | রাকিবুল হাসান | ৮ অক্টোবর ২০২৬ | ৪ | কিনেছেন: ২১৩ পিস · বক্স · সন্তানের বয়স ৩ বছর | পিস গুনে দেখেছি, ঠিক আছে। তিন বছরের বাচ্চার জন্য কিছু পিস ছোট, তাই পাশে বসে থাকতে হয়। দাম অন্য জায়গার চেয়ে কম পেয়েছি। |
| 3 | নুসরাত জাহান | ২ অক্টোবর ২০২৬ | ৫ | কিনেছেন: ৪৮৪ পিস · বক্স · সন্তানের বয়স ৭ বছর | ডেলিভারি চার্জ আগেই দেখতে পেয়েছিলাম, তাই কোনো সারপ্রাইজ হয়নি। মেয়ে নিজে নিজে বাড়ি বানাচ্ছে। প্যাকেট অক্ষত ছিল। |

### C1.4 Categories with counts (used in B1.2, A7.2, MegaMenu)

| Category (bn / en) | Slug | Count | L2 children (for the MegaMenu) |
|---|---|---|---|
| বিল্ডিং ব্লক / Building Blocks | `building-blocks` | 84 | লেগো · ডুপ্লো · কাঠের ব্লক · ম্যাগনেটিক · মেগা ব্লক |
| গাড়ি ও রিমোট / Cars & Remote Control | `cars-rc` | 112 | ডাই-কাস্ট গাড়ি · রিমোট কন্ট্রোল · ট্রাক ও কনস্ট্রাকশন · ট্রেন সেট · ট্র্যাক সেট |
| পাজল ও গেম / Puzzles & Games | `puzzles-games` | 67 | জিগস পাজল · কাঠের পাজল · বোর্ড গেম · কিউব ও ব্রেইন টিজার |
| পুতুল ও সফট টয় / Dolls & Soft Toys | `dolls-soft-toys` | 93 | ফ্যাশন ডল · বেবি ডল · সফট টয় · রোল প্লে সেট |
| শিশু ও বেবি / Baby & Toddler | `baby-toddler` | 58 | অ্যাক্টিভিটি জিম · র‍্যাটেল ও টিদার · পুশ ও পুল · মিউজিক্যাল |
| আউটডোর ও রাইড-অন / Outdoor & Ride-On | `outdoor-ride-on` | 41 | স্লাইড ও সুইং · সাইকেল ও ট্রাইসাইকেল · পুল ও ওয়াটার · বল ও স্পোর্টস |
| আর্ট ও ক্রাফট / Art & Craft | `art-craft` | 76 | ক্লে ও ডো · রং ও মার্কার · ক্রাফট কিট · স্টিকার ও স্ট্যাম্প |
| সায়েন্স ও লার্নিং / Science & Learning | `science-learning` | 49 | এক্সপেরিমেন্ট কিট · মাইক্রোস্কোপ ও টেলিস্কোপ · STEM বিল্ডিং · ফ্ল্যাশ কার্ড |

---

## C2. Microcopy — every string in every layout, both languages

Rules applied throughout: no baby-talk, no exclamation marks outside the empty-cart heading area, every error names the fixable thing, every button says what it does rather than "Submit" or "Continue", and no string promises anything the system does not enforce (one delivery threshold, one hotline, one return window).

### C2.1 Buttons and links

| Surface | Bangla | English |
|---|---|---|
| Card / PDP primary | কার্টে যোগ করুন | Add to cart |
| PDP secondary | এখনই কিনুন | Buy now |
| Out-of-stock replacement | স্টকে এলে জানান | Notify me |
| Wishlist off / on | পছন্দের তালিকায় রাখুন / পছন্দের তালিকায় আছে | Save to wishlist / Saved |
| Cart CTA | চেকআউটে যান | Proceed to checkout |
| Checkout CTA (with amount) | অর্ডার করুন · ৳৪,৩২০ | Place order · ৳4,320 |
| Coupon trigger / apply / remove | কুপন আছে? / প্রয়োগ করুন / কুপন সরান | Have a coupon? / Apply / Remove coupon |
| Filters trigger / apply / clear | ফিল্টার (২) / ২৪টি পণ্য দেখুন / সব মুছুন | Filters (2) / Show 24 products / Clear all |
| Sort trigger | সাজান: জনপ্রিয় | Sort: Popular |
| Pagination | আরও ২৪টি দেখুন | Show 24 more |
| Section "see all" | সব দেখুন | See all |
| Delivery check | ডেলিভারি দেখুন / পরিবর্তন করুন | Check delivery / Change |
| Browse CTAs | সব খেলনা দেখুন / আরও দেখুন / কেনাকাটা চালিয়ে যান | Browse all toys / Browse more / Continue shopping |
| Tracking | অর্ডার দেখুন / অর্ডার ট্র্যাক করুন / কুরিয়ারে ট্র্যাক করুন | Find my order / Track your order / Track with the courier |
| Order actions | ইনভয়েস ডাউনলোড / অর্ডার বাতিল করুন / সমস্যা জানান / ডেলিভারি ম্যানকে ফোন করুন | Download invoice / Cancel this order / Report a problem / Call the delivery rider |
| Reviews | রিভিউ লিখুন / সব ৪২টি রিভিউ দেখুন / আরও পড়ুন / রিভিউ পাঠান | Write a review / See all 42 reviews / Read more / Send review |
| Help | হোয়াটসঅ্যাপে মেসেজ / ফোন করুন ০৯৬১০-১২৩৪৫৬ / হোয়াটসঅ্যাপে বলুন | Message on WhatsApp / Call 09610-123456 / Ask on WhatsApp |
| Bundle | ৩টি একসাথে কার্টে দিন | Add all 3 to cart |
| Variant nudge | রঙ বেছে নিন / সেট বেছে নিন | Choose a colour / Choose a set |
| Undo | ফিরিয়ে আনুন | Undo |
| Wishlist → cart | কার্টে নিন | Move to cart |
| Guide links | বয়স গাইড / সাইজ গাইড | Age guide / Size guide |
| Expand SEO copy | আরও পড়ুন / কম দেখুন | Read more / Show less |

### C2.2 Labels and field copy

| Field | Bangla label | English label | Bangla helper | English helper |
|---|---|---|---|---|
| Name | আপনার নাম | Your name | যেমন: সাবরিনা আক্তার | e.g. Sabrina Akter |
| Phone | মোবাইল নম্বর | Mobile number | অর্ডার আপডেট এই নম্বরে SMS করা হবে | We'll SMS order updates to this number |
| Email | ইমেইল (ঐচ্ছিক) | Email (optional) | ইনভয়েস পেতে চাইলে দিন | Only if you want the invoice by email |
| District | জেলা | District | জেলা নির্বাচন করুন | Select your district |
| Upazila | উপজেলা / থানা | Upazila / Thana | জেলা বাছলে তালিকা আসবে | Pick a district first |
| Area | এলাকা | Area | — | — |
| Address | সম্পূর্ণ ঠিকানা | Full address | বাসা/রোড/ব্লক — যেমন: বাসা ১২, রোড ৫, ব্লক সি | House / road / block — e.g. House 12, Road 5, Block C |
| Landmark | ল্যান্ডমার্ক | Landmark | কোন দোকানের পাশে / কোন তলা — এটা দিলে ডেলিভারি ম্যান সহজে খুঁজে পায় | Beside which shop, which floor — this helps the rider find you |
| Order note | অর্ডার সম্পর্কে কিছু বলার আছে? | Anything to tell us about this order? | ঐচ্ছিক | Optional |
| Save address | পরের বার ব্যবহারের জন্য ঠিকানা সেভ করুন | Save this address for next time | — | — |
| Order number | অর্ডার নম্বর | Order number | SMS এ পাঠানো নম্বর | The number in your SMS |
| Last 4 digits | মোবাইল নম্বরের শেষ ৪ ডিজিট | Last 4 digits of your phone | যে নম্বরে অর্ডার করেছিলেন | The number you ordered with |
| Coupon | কুপন কোড | Coupon code | — | — |
| Quantity | পরিমাণ | Quantity | — | — |
| Search | খেলনা, ব্র্যান্ড বা বয়স খুঁজুন | Search toys, brands or age | — | — |
| Age select | আমার সন্তানের বয়স | My child's age | — | — |

### C2.3 Status, price and delivery strings

| Meaning | Bangla | English |
|---|---|---|
| In stock | স্টকে আছে | In stock |
| Low stock | মাত্র ৩টি বাকি | Only 3 left |
| Out of stock | স্টকে নেই | Out of stock |
| Pre-order | প্রি-অর্ডার · ১৫ নভেম্বর | Pre-order · 15 Nov |
| Back soon | শীঘ্রই আসছে | Back soon |
| Ship-today cut-off | আজ বিকেল ৫টার আগে অর্ডার করলে আজই পাঠানো হবে | Order before 5 pm today and it ships today |
| Price includes VAT | দাম ভ্যাট সহ | Price includes VAT |
| Savings | ৳৭৪০ সাশ্রয় | Save ৳740 |
| Sold count | ২১৭ বার বিক্রি | 217 sold |
| Delivery charge | ডেলিভারি চার্জ | Delivery charge |
| Published rates line | ঢাকায় ৳৭০ · ঢাকার বাইরে ৳১৩০ · ৳৩,০০০+ অর্ডারে ফ্রি | ৳70 in Dhaka · ৳130 outside · free over ৳3,000 |
| ETA | ১০ নভেম্বর – ১১ নভেম্বর এর মধ্যে পৌঁছাবে (১–২ কর্মদিবস) | Arrives 10 Nov – 11 Nov (1–2 working days) |
| Courier named | কুরিয়ার: স্টেডফাস্ট | Courier: Steadfast |
| Free delivery met | ফ্রি ডেলিভারি পেয়েছেন | You've got free delivery |
| Free delivery progress | আরও ৳৫৫০ কিনলে ডেলিভারি ফ্রি | Add ৳550 more for free delivery |
| COD advance | ঢাকার বাইরে ৳৫,০০০+ অর্ডারে ৳২০০ অগ্রিম লাগবে | Orders over ৳5,000 outside Dhaka need a ৳200 advance |
| Pay on delivery | ক্যাশ অন ডেলিভারিতে পরিশোধ | Pay cash on delivery |
| Amount due | ক্যাশ অন ডেলিভারি · ৳৪,৩২০ দিতে হবে | Cash on delivery · ৳4,320 due |
| Same-as-PDP assurance | পণ্যের পাতায় দেখানো চার্জই এখানে | The same charge you saw on the product page |
| Totals | মোট পণ্যমূল্য / ছাড় / ডেলিভারি চার্জ / অগ্রিম / সর্বমোট | Subtotal / Discount / Delivery charge / Advance payable / Total |
| Verified review | ভেরিফাইড ক্রেতা | Verified purchase |
| Review context | কিনেছেন: লাল · ৪৮৪ পিস · সন্তানের বয়স ৩ বছর | Bought: Red · 484 pcs · child aged 3 |
| Helpful | ৮ জনের উপকারে এসেছে | 8 found this helpful |
| Trust strip | ক্যাশ অন ডেলিভারি (সারা দেশে) · ৭ দিনে রিটার্ন (অক্ষত প্যাকেট হলে) · ১০০% অরিজিনাল (ব্র্যান্ড ওয়ারেন্টি) · ০৯৬১০-১২৩৪৫৬ (সকাল ৯টা–রাত ৯টা) | Cash on delivery (nationwide) · 7-day return (if unopened) · 100% authentic (brand warranty) · 09610-123456 (9 am – 9 pm) |
| Safety | ৩ বছরের নিচে ছোট অংশ — বড়দের নজরদারি দরকার | Small parts — not for under 3 without supervision |
| Privacy | আপনার নম্বর শুধু অর্ডার আর ডেলিভারির জন্য ব্যবহার করা হয়। | We use your number only for this order and its delivery. |
| Secure checkout | নিরাপদ চেকআউট | Secure checkout |
| Last updated | সর্বশেষ আপডেট: ২:১৪ অপরাহ্ণ | Last updated 2:14 pm |
| Flash sale | ফ্ল্যাশ সেল · আজ রাত ১২টা পর্যন্ত · সেল শেষ | Flash sale · Until midnight tonight · Sale ended |
| Countdown units | দিন · ঘণ্টা · মিনিট · সেকেন্ড | d · h · m · s |

### C2.4 Empty states

| Screen | Bangla heading | English heading | Bangla body | English body |
|---|---|---|---|---|
| Empty cart | আপনার কার্ট খালি | Your cart is empty | বয়স অনুযায়ী খেলনা খুঁজে শুরু করুন — ঢাকায় ২৪ ঘণ্টায় পৌঁছে যাবে। | Start with your child's age — toys reach Dhaka addresses in 24 hours. |
| Empty wishlist | পছন্দের তালিকা খালি | Your wishlist is empty | পছন্দের খেলনা হার্ট আইকনে সেভ করে রাখুন, পরে কিনতে পারবেন। | Tap the heart on any toy to keep it here for later. |
| Zero filter results | এই ফিল্টারে কোনো খেলনা নেই | No toys match these filters | বয়স বা দামের ফিল্টার একটু বদলে দেখুন। | Try widening the age or price filter. |
| Zero search results | "হেলিকপ্তার" এর জন্য কিছু পাওয়া যায়নি | Nothing found for "helicoptar" | বানান ঠিক করে দেখুন, নাকি নিচের ক্যাটাগরি থেকে দেখবেন? | Check the spelling, or try the category below. |
| No reviews yet | এখনো কোনো রিভিউ নেই | No reviews yet | কিনে প্রথম রিভিউ লিখুন — আমরা ডেলিভারির ৩ দিন পর SMS এ জিজ্ঞেস করব। | Be the first — we ask by SMS three days after delivery. |
| No orders | এখনো কোনো অর্ডার নেই | No orders yet | প্রথম অর্ডারটি করুন — ঢাকায় ২৪ ঘণ্টায় ডেলিভারি, ক্যাশ অন ডেলিভারি। | Place your first order — next-day in Dhaka, cash on delivery. |
| Empty recently viewed | *(section is removed, not shown empty)* | | | |
| Search dropdown, no quick match | কিছু পাওয়া যায়নি — Enter চাপুন পুরো সার্চের জন্য | No quick matches — press Enter for full search | | |
| Delivery not serviced | এই উপজেলায় আমরা এখনো ডেলিভারি দিই না | We don't deliver to this upazila yet | {উপজেলা} এখনো আমাদের কুরিয়ার নেটওয়ার্কে নেই। | {Upazila} isn't on our courier network yet. |

### C2.5 Errors, warnings and confirmations

| Case | Bangla | English |
|---|---|---|
| Name empty | নাম লিখুন | Please enter your name |
| Phone format | ০১ দিয়ে শুরু ১১ ডিজিটের নম্বর লিখুন | Enter an 11-digit number starting with 01 |
| Phone operator prefix | এই অপারেটর প্রিফিক্সটি চিনি না — আবার দেখুন | We don't recognise that operator prefix — please check |
| Email format | ইমেইল ঠিকানাটি সঠিক নয় | That email address isn't valid |
| District empty | জেলা নির্বাচন করুন | Please select a district |
| Upazila empty | উপজেলা নির্বাচন করুন | Please select an upazila |
| Area empty | এলাকা নির্বাচন করুন | Please select an area |
| Address empty | ঠিকানা লিখুন | Please enter the address |
| Checkout blocked | ডেলিভারি ঠিকানা পূরণ করুন | Complete the delivery address |
| Coupon invalid | এই কোডটি সঠিক নয় | That code isn't valid |
| Coupon expired | এই কুপনের সময় শেষ হয়ে গেছে | This coupon has expired |
| Coupon minimum | এই কুপনের জন্য কমপক্ষে ৳২,০০০ লাগবে — আরও ৳৩১০ | This coupon needs a ৳2,000 minimum — ৳310 to go |
| Stock ceiling | স্টকে আর নেই | That's all we have in stock |
| Stock reduced in cart | স্টকে ২টি আছে — পরিমাণ কমানো হয়েছে | Only 2 in stock — quantity reduced |
| Variant unavailable | লাল রঙ এখন স্টকে নেই | Red is out of stock right now |
| Combination impossible | লাল রঙে ৪৮৪ পিসের সেট নেই — ৯০ পিস বেছে নিন | Red doesn't come in the 484-piece set — pick 90 pieces |
| Variant arriving | নীল ১৫ নভেম্বর স্টকে আসবে | Blue arrives 15 November |
| Facet has no products | এই ফিল্টারের সাথে কোনো পণ্য নেই | No products match this with your other filters |
| Express unavailable | আপনার এলাকায় এক্সপ্রেস নেই | Express isn't available in your area |
| Delivery rate fetch failed | চার্জ আনতে সমস্যা হয়েছে — প্রকাশিত হার দেখানো হচ্ছে | Couldn't fetch the live rate — showing our published rate |
| Payment failed | পেমেন্ট সম্পন্ন হয়নি — আপনার টাকা কাটা হয়নি। আবার চেষ্টা করুন বা ক্যাশ অন ডেলিভারি বেছে নিন। | The payment didn't go through — you haven't been charged. Try again, or switch to cash on delivery. |
| Tracking mismatch | এই অর্ডার নম্বর আর ডিজিট মিলছে না। SMS এ পাঠানো নম্বরটি আবার দেখুন। | That order number and those digits don't match. Please check the number in your SMS. |
| Tracking rate limited | অনেকবার চেষ্টা হয়েছে — ১০ মিনিট পর আবার দেখুন, বা আমাদের ফোন করুন ০৯৬১০-১২৩৪৫৬ | Too many attempts — try again in 10 minutes, or call us on 09610-123456 |
| Added to cart | কার্টে যোগ হয়েছে | Added to cart |
| Removed from cart | কার্ট থেকে সরানো হয়েছে | Removed from cart |
| Item removed (undo strip) | {নাম} সরানো হয়েছে | {name} removed |
| Wishlist saved (guest) | পছন্দের তালিকায় রাখা হলো — নম্বর দিয়ে সেভ করে রাখুন | Saved — add your number to keep it |
| Filters discarded | পরিবর্তন বাতিল হয়েছে | Changes discarded |
| Coupon applied | SAVE10 প্রয়োগ হয়েছে | SAVE10 applied |
| Result count announced | ২৪টি পণ্য পাওয়া গেছে | 24 products found |
| Total announced | সর্বমোট ৳৪,৩২০ | Total ৳4,320 |
| Flash-sale warning | ফ্ল্যাশ সেল শেষ হতে ১০ মিনিট | 10 minutes left in the flash sale |
| Search correction | "puzle" এর বদলে "পাজল" দেখানো হচ্ছে | Showing results for "পাজল" instead of "puzle" |
| Did you mean | আপনি কি "হেলিকপ্টার" খুঁজছেন? | Did you mean "helicopter"? |
| Policy consent | অর্ডার করলে আপনি আমাদের শর্তাবলী ও রিটার্ন নীতিতে সম্মত হচ্ছেন। | By placing this order you agree to our Terms and Return policy. |

### C2.6 Strings that must come from one place (anti-contradiction list)

Audit finding: SK Toy states three different free-delivery thresholds, Krystal's shipping page and checkout disagree, EduToyZone states its threshold three ways, and Krystal prints `xxxx-xxxxx` as a live hotline. Each of the following is a **single data field**, rendered everywhere from that field, with a CI test asserting one value per field across all rendered surfaces.

| Field | Single value | Rendered on |
|---|---|---|
| Free-delivery threshold | **৳3,000** | PDP estimator, cart progress bar, checkout §2, policy page, footer, flash-sale banner, blog posts |
| Dhaka Metro charge | **৳70** | PDP, cart, checkout, `/delivery-charge`, footer line, policy page |
| Dhaka Outer charge | **৳100** | same |
| Outside-Dhaka charge | **৳130** | same |
| Express charge | **৳150** | checkout §2 only (and the PDP estimator where available) |
| COD advance rule | **৳200 when order > ৳5,000 and zone = Outside** | PDP estimator, checkout §1 COD card, policy page |
| Return window | **7 days, unopened** | trust strip, PDP returns accordion, policy page, order tracking |
| Hotline | **09610-123456** | header, footer, trust strip, every error that offers a phone fallback |
| Support hours | **9 am – 9 pm, closed Friday** | footer, WhatsApp sheet, trust strip sub-caption |
| Order cut-off | **5:00 pm Asia/Dhaka** | PDP stock line, estimator ETA, express radio |
| Courier name per zone | **Steadfast** (Dhaka + national), **Pathao** (Dhaka Metro express) | estimator, order timeline, tracking, footer |

---

## D. Build order for the lead, and the three things to check first

1. **Build `ProductCard` before anything else.** Nine of the sixteen layouts in Part B are mostly instances of it. Build `Grid` first, at 173 × 384, with `Lang=en|bn` as a real variant axis.
2. **Build `DeliveryEstimator` second**, all five `State` values, with the min-height 132 reserved in every one. It is the component that carries the §2 delivery-transparency opening, and it is the one the lead is most likely to under-build because its empty state looks trivial.
3. Then `StockIndicator` → `AgeRangeIndicator` → `Price` → `QuantityStepper` (Tier 1, but `ProductCard` needs it) → `CartSummary` → `FilterPanel` (sidebar, then sheet) → `SearchBar` → the screens.

**Check these three things on the first screen that is built, because everything downstream inherits them:**
- Put the longest Bangla string in the shortest box. `২৪টি পণ্য দেখুন` in the filter sheet footer, `কার্টে যোগ করুন` in the 173px card CTA, and `মোবাইল নম্বরের শেষ ৪ ডিজিট` as a field label. If any of the three wraps where this document says it does not, the type scale or the label needs changing, not the box.
- Toggle `Discounted` and `CartState` on a `ProductCard` inside a built grid and confirm **nothing below it moves by a single pixel**. If it does, a height is hugging where this document says fixed.
- Set the PDP to an undiscounted, out-of-stock, single-variant product and confirm the price block is still exactly 96 tall and the delivery estimator is still exactly 132.

**Open items that need the owner, not the lead:** the real hotline number (09610-123456 is a placeholder shape, not a confirmed line), the trade-licence number in the footer, confirmation that Steadfast and Pathao are the launch couriers, and whether Bangla UI ships at launch or as a fast follow — this document specifies both locales as real variants, which is the more expensive but safer assumption.
