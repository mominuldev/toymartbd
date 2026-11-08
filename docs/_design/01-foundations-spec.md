# Toy Mart BD — Foundations Specification (FINAL)

**Owner:** Design Agent 1 · **Date:** 2026-11-08 · **Status:** Final — execute as written
**Supersedes:** `docs/UI-UX-DESIGN-SPEC.md` §2 and §3 in full.
**Audience:** the lead agent, who types these values into Figma. Every number here is final. There is no TBD and no range where a single value is required.
**Owner's brief, verbatim:** *"modern creative, premium and user friendly like kids."*

---

## 1. Brand rationale

**Rickshaw-plate chromatics, Swiss-grid discipline.**

Bangladesh already owns a native visual language for cheap joy: the painted rickshaw back-plate — vermilion, marigold, peacock teal and orchid violet laid down as flat, hard-edged, fully opaque fields, never blended into each other. We take the palette and the flatness and nothing else. No gradients, no glows, no glass, no coloured drop shadows, no 3D.

The one thing that makes this premium rather than merely colourful is **the area budget**. Saturated colour is capped at roughly 12% of any viewport, and it is forbidden inside a decision surface — product-card body, cart, checkout, address form, account, any admin form. Everything structural is warm ink (`#241D19`) on warm paper (`#F7F3F1`) on a strict 4px grid, in one humanist sans with deliberately tight display tracking and tabular figures. Richness comes from colour relationships and generous macro-spacing, not from effects. The page reads as a well-printed object, not a bright app.

Children get the toy shelf: full-bleed category tiles, two-tone icons, illustrated empty states, one spring. Parents get a receipt: ink, grid, aligned numerals, no exclamation marks.

*(180 words)*

### What this direction explicitly refuses
Warm cream + serif + terracotta · near-black with one acid-green pop · purple-to-blue gradient heroes · Inter / Space Grotesk as the safe face · emoji as section markers · centred everything · uniform `rounded-lg` cards. Also refused: carousel auto-advance, pill-shaped primary buttons (see §6.2), and any decorative use of the danger ramp.

---

## 2. Colour primitives — final

All ramps were constructed in OKLCh for perceptual evenness, then each contrast-critical step was solved numerically against the WCAG relative-luminance formula and rounded to the shipping 8-bit hex. **The hexes below are the authority; the OKLCh coordinates are documentation only.**

### 2.1 Expressive ramps

| Ramp | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 |
|---|---|---|---|---|---|---|---|---|---|---|
| **`brand`** vermilion (OKLCh h 30) | `#FFF4F2` | `#FFE6E1` | `#FFCCC3` | `#FFA191` | `#F76854` | **`#DD2D1D`** | `#C22314` | `#9E1C10` | `#7D1B11` | `#5E150E` |
| **`accent`** marigold (h 78) | `#FFF8EE` | `#FFEFD7` | `#FFDFAE` | `#FFC972` | `#FCB31C` | **`#EBA500`** | `#C38801` | `#996A01` | `#785300` | `#5D3F00` |
| **`teal`** peacock (h 185) | `#EDFAF8` | `#D7F5F0` | `#B2EAE2` | `#7ED8CC` | `#4BBCB0` | **`#1C9E92`** | `#008076` | `#00655C` | `#054F49` | `#073C37` |
| **`grape`** orchid (h 305) | `#F9F5FF` | `#F2E8FF` | `#E6D2FF` | `#D4AFFF` | `#B97CF8` | **`#9A4CDF`** | `#843CC1` | `#6A2F9D` | `#54277C` | `#411E5F` |

### 2.2 Neutral ramp — warm paper / warm ink (OKLCh h 50, chroma 0.003 → 0.014)

| 0 | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 950 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `#FFFFFF` | `#FCFAF9` | `#F7F3F1` | `#EBE5E2` | `#D8D1CE` | `#B4ABA7` | `#89807B` | `#6E655F` | `#594F4A` | `#3D342F` | `#241D19` | `#16100C` |

The bias is deliberate and single-hue: every neutral sits on OKLCh hue 50 (warm, sand-to-ink), chroma rising from 0.003 at `50` to 0.014 at `500` and holding. Nothing in this ramp is a pure grey, and the ramp never crosses into cool. That is what lets the warm-tinted elevation in §6.3 derive honestly from `neutral/900` instead of being an arbitrary tint.

Two alpha primitives also live in this collection, because semantic tokens may never carry raw colour:

| Token | Value |
|---|---|
| `neutral/ink-alpha-60` | `rgba(36, 29, 25, 0.60)` |
| `neutral/ink-alpha-72` | `rgba(22, 16, 12, 0.72)` |

### 2.3 Status ramps

Each carries **50 / 100 / 300 / 400 / 500 / 600 / 700 / 800 / 900** — more than the required minimum, for the reason given in §2.4.

| Ramp | 50 | 100 | 300 | 400 | 500 | 600 | 700 | 800 | 900 |
|---|---|---|---|---|---|---|---|---|---|
| **`success`** (h 145) | `#F0FBF0` | `#DCF6DC` | `#93D594` | `#6DBC71` | `#4EA253` | `#35813B` | `#2A682F` | `#215225` | `#183D1B` |
| **`warning`** (h 52) | `#FFF7F2` | `#FFEBDF` | `#FFB78C` | `#F9904A` | `#E07526` | `#B25600` | `#914500` | `#743601` | `#592904` |
| **`danger`** (h 12) | `#FFF3F4` | `#FFE5E7` | `#FF9EAA` | `#F56680` | `#E22F5E` | `#BE184A` | `#9B113B` | `#7A112E` | `#5D0E23` |
| **`info`** (h 255) | `#F1F7FF` | `#E1EEFF` | `#94C0F7` | `#5E9DEE` | `#3387EB` | `#0065C4` | `#004F9E` | `#003F7F` | `#013063` |

### 2.4 Every change from the draft, with its one-line justification

| Change | Justification |
|---|---|
| `brand/500` `#F4453A` → **`#DD2D1D`** | The draft claimed 4.6:1; `#F4453A` measures **4.05:1** on white, so white-on-primary-CTA failed AA. `#DD2D1D` is the lightest vermilion that clears 4.5 with margin (4.70). |
| `accent/500` `#F7B500` → **`#EBA500`** | Deeper marigold reads as brass/turmeric rather than highlighter, and gives 7.84:1 under ink while staying unmistakably "sale". |
| `teal` ramp re-anchored; `teal/500` `#13A5A2` → **`#1C9E92`**, focus moved to `teal/600` `#008076` | The draft's focus ring measured **2.98:1** against the page background — a straight SC 1.4.11 failure. `teal/600` gives 4.38:1 on canvas and 3.87:1 on a striped admin row. |
| `grape/500` `#8255EE` → **`#9A4CDF`**, hue 295 → 305 | `#8255EE` was 4.03:1 under white labels (fail), and at hue 295 it collided with `info` blue in peripheral vision. Orchid at 305 separates them and clears 4.69:1. |
| `neutral` zinc (cool, h ≈ 265) → **warm paper/ink at h 50** | A cool grey beside vermilion goes muddy, and the brief requires a deliberate hue bias. Also re-anchored two steps: `neutral/500` is now the minimum legal control border (3.10:1 even on `neutral/200`) and `neutral/600` the minimum legal metadata colour (4.56:1 on the same). |
| **Added `neutral/0` = `#FFFFFF`** | Semantic tokens may never hold a raw hex, and the draft's `color.bg.surface` did exactly that. White must exist as a primitive. |
| **Added `neutral/950` = `#16100C`** | Dark mode needs a canvas *darker* than the darkest surface. Without it, `bg/canvas` and `bg/surface` collapse onto one value. |
| **Added `neutral/ink-alpha-60` / `-72`** | `color/bg/overlay` must alias a primitive; a scrim needs alpha. |
| `success/500` `#12A150` → ramp at hue 145; text now uses `success/700` | `success/600` measures 4.82:1 on white but only **4.22:1** on `success/50`, so the alert case failed. `success/700` passes both (6.73 / 6.33). |
| `warning` hue set to **52**, not amber ~65 | Keeps warning clearly apart from `accent` marigold at hue 78, so a low-stock warning can never be mistaken for a sale badge on the same product card. |
| `danger` hue moved to **12** (cool crimson) | At the draft's ~25 it was the same hue family as the vermilion brand, meaning "buy" and "error" were the same red. Hue 12 reads raspberry, not vermilion. Reinforced by a form rule: **on the storefront, errors are never a fill** — they are `danger/700` text on `bg/danger-subtle` with a 2px `danger/500` left rule and an icon. Red fills are admin-destructive only. |
| Status ramps extended to 9 steps | 50/500/600 alone cannot build an accessible dark-mode alert: dark needs a `300` for text and a `900` for the tinted background. |

---

## 3. Semantic token table — final

Light and dark values are **aliases to primitives only**. No raw hex appears below. Components may reference nothing but these.

### 3.1 Required tokens

| Token | Light | Dark | Role |
|---|---|---|---|
| `color/bg/canvas` | `neutral/100` | `neutral/950` | Page background |
| `color/bg/surface` | `neutral/0` | `neutral/900` | Cards, panels, sheets, table body |
| `color/bg/surface-raised` | `neutral/0` | `neutral/800` | Popovers, dropdowns, tooltips, modals |
| `color/bg/subtle` | `neutral/200` | `neutral/800` | Table stripes, inert fills, input rest fill |
| `color/bg/brand` | `brand/500` | `brand/500` | Primary CTA, active nav indicator |
| `color/bg/brand-subtle` | `brand/50` | `brand/900` | Tinted section, selected row, brand chip |
| `color/bg/accent` | `accent/500` | `accent/500` | Sale badge, countdown strip, flash-sale band |
| `color/bg/accent-subtle` | `accent/50` | `accent/900` | Promo strip background |
| `color/text/primary` | `neutral/900` | `neutral/50` | Body, headings, product titles |
| `color/text/secondary` | `neutral/700` | `neutral/300` | Supporting copy, descriptions |
| `color/text/tertiary` | `neutral/600` | `neutral/400` | Metadata, placeholders, timestamps |
| `color/text/on-brand` | `neutral/0` | `neutral/0` | Text on `bg/brand` |
| `color/text/on-accent` | `neutral/900` | `neutral/900` | Text on `bg/accent` — **never white** |
| `color/text/price` | `neutral/900` | `neutral/50` | Current price |
| `color/text/price-was` | `neutral/600` | `neutral/400` | Struck original price |
| `color/text/discount` | `brand/600` | `brand/300` | "−25%" |
| `color/text/link` | `teal/700` | `teal/300` | Inline links |
| `color/border/default` | `neutral/300` | `neutral/700` | Dividers, card edges — **decorative only** |
| `color/border/strong` | `neutral/500` | `neutral/500` | Input / control boundaries — meets 3:1 in both modes |
| `color/border/focus` | `teal/600` | `teal/400` | Focus ring outer stroke |
| `color/status/in-stock` | `success/700` | `success/300` | "In stock" text + icon |
| `color/status/low-stock` | `warning/700` | `warning/300` | "Only 3 left" text + icon |
| `color/status/out-of-stock` | `neutral/600` | `neutral/400` | "Out of stock" text + icon |
| `color/feedback/success` | `success/700` | `success/300` | Success alert text / icon |
| `color/feedback/warning` | `warning/700` | `warning/300` | Warning alert text / icon |
| `color/feedback/danger` | `danger/700` | `danger/300` | Error alert text / icon |
| `color/feedback/info` | `info/700` | `info/300` | Info alert text / icon |

> **Note on `status/*` and `feedback/*`:** these are **text-and-icon** tokens and therefore carry the 4.5:1 requirement. The matching *dot* or *fill* uses `success/500` / `warning/500` / `danger/500`, which are verified at 3:1 non-text (§4, Table C). This is the single most common place a playful palette fails a CI gate; splitting the roles prevents it.

### 3.2 Tokens added — genuinely missing from the draft

| Token | Light | Dark | Why it is needed |
|---|---|---|---|
| `color/bg/teal` | `teal/600` | `teal/600` | The `teal` ramp existed with no fill token; category tiles need it. |
| `color/bg/teal-subtle` | `teal/50` | `teal/900` | Tinted "shop by age" bands. |
| `color/bg/grape` | `grape/500` | `grape/500` | Second category-tile fill. |
| `color/bg/grape-subtle` | `grape/50` | `grape/900` | Tinted editorial / blog bands. |
| `color/bg/danger` | `danger/600` | `danger/600` | Destructive admin buttons had no fill token. |
| `color/bg/success-subtle` | `success/50` | `success/900` | Alerts cannot be built from `feedback/*` alone. |
| `color/bg/warning-subtle` | `warning/50` | `warning/900` | ditto |
| `color/bg/danger-subtle` | `danger/50` | `danger/900` | ditto — and it is the storefront error treatment (§2.4). |
| `color/bg/info-subtle` | `info/50` | `info/900` | ditto |
| `color/bg/overlay` | `neutral/ink-alpha-60` | `neutral/ink-alpha-72` | Modal / bottom-sheet scrim. |
| `color/bg/skeleton` | `neutral/200` | `neutral/800` | Loading placeholders, needed on day one for the CLS budget. |
| `color/bg/disabled` | `neutral/200` | `neutral/800` | Disabled control fill. |
| `color/text/on-teal` | `neutral/0` | `neutral/0` | Label on `bg/teal`. |
| `color/text/on-grape` | `neutral/0` | `neutral/0` | Label on `bg/grape`. |
| `color/text/on-danger` | `neutral/0` | `neutral/0` | Label on `bg/danger`. |
| `color/text/disabled` | `neutral/500` | `neutral/500` | Disabled label; exempt from 1.4.3 by design, so it must be a separate token rather than a misused `tertiary`. |
| `color/text/inverse` | `neutral/0` | `neutral/900` | Text on ink fills (toasts, tooltips). |
| `color/border/brand` | `brand/500` | `brand/400` | Selected card / checked control boundary at 3:1. |
| `color/border/danger` | `danger/500` | `danger/400` | Invalid field boundary at 3:1 without a red fill. |

### 3.3 The focus indicator is a composite, not a single stroke

A single-colour ring cannot clear 3:1 against both white paper *and* a vermilion CTA fill (`teal/600` vs `brand/500` is only 1.42:1). The ring is therefore two strokes:

```
shadow/focus = 0 0 0 2px var(--color-bg-surface),   /* inner halo */
               0 0 0 4px var(--color-border-focus)  /* outer ring */
```

Verified in §4 Table C and Table E: the halo clears 3:1 against every interactive fill in the system, and the ring clears 3:1 against every background. **Corollary rule: `accent/500` is never the fill of a focusable control** (white halo on marigold is 2.12:1). Accent fills badges and bands, which are not focusable. No exceptions.

---

## 4. Contrast verification

Computed with the WCAG 2.x relative-luminance formula (sRGB, linearised, `0.2126R + 0.7152G + 0.0722B`), evaluated on the **quantised 8-bit shipping hexes**, reported to 2 decimal places. 111 pairs. **0 failures.** Every pair that failed during construction was fixed by moving the token, and the corrected ratio is what appears here.

### Table A — Light mode, body text (requirement 4.5:1)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| Body / heading <24px on page background | `neutral/900` · #241D19 | `neutral/100` · #F7F3F1 | **15.06** | 4.5:1 | PASS |
| Body / heading <24px on card | `neutral/900` · #241D19 | `neutral/0` · #FFFFFF | **16.60** | 4.5:1 | PASS |
| Body on striped row / inert fill | `neutral/900` · #241D19 | `neutral/200` · #EBE5E2 | **13.31** | 4.5:1 | PASS |
| Body on brand-tinted section | `neutral/900` · #241D19 | `brand/50` · #FFF4F2 | **15.40** | 4.5:1 | PASS |
| Body on accent-tinted section | `neutral/900` · #241D19 | `accent/50` · #FFF8EE | **15.74** | 4.5:1 | PASS |
| Body on teal-tinted tile | `neutral/900` · #241D19 | `teal/50` · #EDFAF8 | **15.52** | 4.5:1 | PASS |
| Body on grape-tinted tile | `neutral/900` · #241D19 | `grape/50` · #F9F5FF | **15.44** | 4.5:1 | PASS |
| Secondary copy on page background | `neutral/700` · #594F4A | `neutral/100` · #F7F3F1 | **7.21** | 4.5:1 | PASS |
| Secondary copy on card | `neutral/700` · #594F4A | `neutral/0` · #FFFFFF | **7.95** | 4.5:1 | PASS |
| Secondary copy on striped row | `neutral/700` · #594F4A | `neutral/200` · #EBE5E2 | **6.37** | 4.5:1 | PASS |
| Secondary copy on brand-tinted section | `neutral/700` · #594F4A | `brand/50` · #FFF4F2 | **7.37** | 4.5:1 | PASS |
| Metadata / placeholder on page background | `neutral/600` · #6E655F | `neutral/100` · #F7F3F1 | **5.16** | 4.5:1 | PASS |
| Metadata / placeholder on card | `neutral/600` · #6E655F | `neutral/0` · #FFFFFF | **5.69** | 4.5:1 | PASS |
| Metadata on striped row | `neutral/600` · #6E655F | `neutral/200` · #EBE5E2 | **4.56** | 4.5:1 | PASS |
| Button label on primary CTA | `neutral/0` · #FFFFFF | `brand/500` · #DD2D1D | **4.70** | 4.5:1 | PASS |
| Badge label on sale badge | `neutral/900` · #241D19 | `accent/500` · #EBA500 | **7.84** | 4.5:1 | PASS |
| Label on teal fill (category tile) | `neutral/0` · #FFFFFF | `teal/600` · #008076 | **4.83** | 4.5:1 | PASS |
| Label on grape fill (category tile) | `neutral/0` · #FFFFFF | `grape/500` · #9A4CDF | **4.69** | 4.5:1 | PASS |
| Current price on card | `neutral/900` · #241D19 | `neutral/0` · #FFFFFF | **16.60** | 4.5:1 | PASS |
| Current price on page background | `neutral/900` · #241D19 | `neutral/100` · #F7F3F1 | **15.06** | 4.5:1 | PASS |
| Struck original price on card | `neutral/600` · #6E655F | `neutral/0` · #FFFFFF | **5.69** | 4.5:1 | PASS |
| Struck original price on striped row | `neutral/600` · #6E655F | `neutral/200` · #EBE5E2 | **4.56** | 4.5:1 | PASS |
| Discount "−25%" on card | `brand/600` · #C22314 | `neutral/0` · #FFFFFF | **5.92** | 4.5:1 | PASS |
| Discount on brand-tinted strip | `brand/600` · #C22314 | `brand/50` · #FFF4F2 | **5.49** | 4.5:1 | PASS |
| Discount on page background | `brand/600` · #C22314 | `neutral/100` · #F7F3F1 | **5.37** | 4.5:1 | PASS |
| Inline link on card | `teal/700` · #00655C | `neutral/0` · #FFFFFF | **6.96** | 4.5:1 | PASS |
| Inline link on page background | `teal/700` · #00655C | `neutral/100` · #F7F3F1 | **6.31** | 4.5:1 | PASS |
| Inline link on striped row | `teal/700` · #00655C | `neutral/200` · #EBE5E2 | **5.58** | 4.5:1 | PASS |
| In-stock text on card | `success/700` · #2A682F | `neutral/0` · #FFFFFF | **6.73** | 4.5:1 | PASS |
| In-stock text in success pill | `success/700` · #2A682F | `success/50` · #F0FBF0 | **6.33** | 4.5:1 | PASS |
| Low-stock text on card | `warning/700` · #914500 | `neutral/0` · #FFFFFF | **6.87** | 4.5:1 | PASS |
| Low-stock text in warning pill | `warning/700` · #914500 | `warning/50` · #FFF7F2 | **6.49** | 4.5:1 | PASS |
| Out-of-stock text on card | `neutral/600` · #6E655F | `neutral/0` · #FFFFFF | **5.69** | 4.5:1 | PASS |
| Out-of-stock text on striped row | `neutral/600` · #6E655F | `neutral/200` · #EBE5E2 | **4.56** | 4.5:1 | PASS |
| Success message in alert | `success/700` · #2A682F | `success/50` · #F0FBF0 | **6.33** | 4.5:1 | PASS |
| Warning message in alert | `warning/700` · #914500 | `warning/50` · #FFF7F2 | **6.49** | 4.5:1 | PASS |
| Error message in alert | `danger/700` · #9B113B | `danger/50` · #FFF3F4 | **7.64** | 4.5:1 | PASS |
| Info message in alert | `info/700` · #004F9E | `info/50` · #F1F7FF | **7.46** | 4.5:1 | PASS |
| Destructive button label | `neutral/0` · #FFFFFF | `danger/600` · #BE184A | **6.14** | 4.5:1 | PASS |
| Confirm button label | `neutral/0` · #FFFFFF | `success/700` · #2A682F | **6.73** | 4.5:1 | PASS |
| Info button label | `neutral/0` · #FFFFFF | `info/600` · #0065C4 | **5.74** | 4.5:1 | PASS |
| Chip label on brand chip | `brand/700` · #9E1C10 | `brand/100` · #FFE6E1 | **6.72** | 4.5:1 | PASS |
| Countdown digits on accent-tint strip | `accent/700` · #996A01 | `accent/50` · #FFF8EE | **4.51** | 4.5:1 | PASS |
| Secondary brand text (admin) | `grape/700` · #6A2F9D | `neutral/0` · #FFFFFF | **8.38** | 4.5:1 | PASS |

### Table B — Light mode, large text ≥24px (requirement 3:1)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| `display-1` / `display-2` in brand on card | `brand/500` · #DD2D1D | `neutral/0` · #FFFFFF | **4.70** | 3.0:1 | PASS |
| `display-1` / `display-2` in brand on page bg | `brand/500` · #DD2D1D | `neutral/100` · #F7F3F1 | **4.26** | 3.0:1 | PASS |
| `price-lg` on accent badge (28px) | `neutral/900` · #241D19 | `accent/500` · #EBA500 | **7.84** | 3.0:1 | PASS |
| `h1` in teal on card | `teal/600` · #008076 | `neutral/0` · #FFFFFF | **4.83** | 3.0:1 | PASS |
| `h1` in grape on card | `grape/600` · #843CC1 | `neutral/0` · #FFFFFF | **6.18** | 3.0:1 | PASS |

### Table C — Light mode, non-text / UI boundary (requirement 3:1, SC 1.4.11)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| Input / control border on card | `neutral/500` · #89807B | `neutral/0` · #FFFFFF | **3.86** | 3.0:1 | PASS |
| Input / control border on page bg | `neutral/500` · #89807B | `neutral/100` · #F7F3F1 | **3.50** | 3.0:1 | PASS |
| Input / control border on striped row | `neutral/500` · #89807B | `neutral/200` · #EBE5E2 | **3.10** | 3.0:1 | PASS |
| Focus ring outer vs card | `teal/600` · #008076 | `neutral/0` · #FFFFFF | **4.83** | 3.0:1 | PASS |
| Focus ring outer vs page bg | `teal/600` · #008076 | `neutral/100` · #F7F3F1 | **4.38** | 3.0:1 | PASS |
| Focus ring outer vs striped row | `teal/600` · #008076 | `neutral/200` · #EBE5E2 | **3.87** | 3.0:1 | PASS |
| Focus ring outer vs disabled fill | `teal/600` · #008076 | `neutral/300` · #D8D1CE | **3.20** | 3.0:1 | PASS |
| Focus halo (inner 2px) vs brand CTA fill | `neutral/0` · #FFFFFF | `brand/500` · #DD2D1D | **4.70** | 3.0:1 | PASS |
| Focus halo vs grape fill | `neutral/0` · #FFFFFF | `grape/500` · #9A4CDF | **4.69** | 3.0:1 | PASS |
| Focus halo vs teal fill | `neutral/0` · #FFFFFF | `teal/600` · #008076 | **4.83** | 3.0:1 | PASS |
| Focus halo vs destructive fill | `neutral/0` · #FFFFFF | `danger/600` · #BE184A | **6.14** | 3.0:1 | PASS |
| Focus halo vs ink fill (admin) | `neutral/0` · #FFFFFF | `neutral/900` · #241D19 | **16.60** | 3.0:1 | PASS |
| Selected-state brand border on card | `brand/500` · #DD2D1D | `neutral/0` · #FFFFFF | **4.70** | 3.0:1 | PASS |
| Checked checkbox fill vs card | `brand/500` · #DD2D1D | `neutral/0` · #FFFFFF | **4.70** | 3.0:1 | PASS |
| Stock dot (in-stock) vs card | `success/500` · #4EA253 | `neutral/0` · #FFFFFF | **3.17** | 3.0:1 | PASS |
| Stock dot (low-stock) vs card | `warning/500` · #E07526 | `neutral/0` · #FFFFFF | **3.11** | 3.0:1 | PASS |
| Error field border vs card | `danger/500` · #E22F5E | `neutral/0` · #FFFFFF | **4.37** | 3.0:1 | PASS |

### Table D — Dark mode (admin), body text (requirement 4.5:1)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| Body on canvas | `neutral/50` · #FCFAF9 | `neutral/950` · #16100C | **18.12** | 4.5:1 | PASS |
| Body on panel | `neutral/50` · #FCFAF9 | `neutral/900` · #241D19 | **15.96** | 4.5:1 | PASS |
| Body on popover / raised | `neutral/50` · #FCFAF9 | `neutral/800` · #3D342F | **11.66** | 4.5:1 | PASS |
| Body on striped row | `neutral/50` · #FCFAF9 | `neutral/800` · #3D342F | **11.66** | 4.5:1 | PASS |
| Body on brand-tinted row | `neutral/50` · #FCFAF9 | `brand/900` · #5E150E | **12.70** | 4.5:1 | PASS |
| Body on accent-tinted row | `neutral/50` · #FCFAF9 | `accent/900` · #5D3F00 | **9.27** | 4.5:1 | PASS |
| Body on teal-tinted row | `neutral/50` · #FCFAF9 | `teal/900` · #073C37 | **11.80** | 4.5:1 | PASS |
| Body on grape-tinted row | `neutral/50` · #FCFAF9 | `grape/900` · #411E5F | **12.81** | 4.5:1 | PASS |
| Secondary copy on canvas | `neutral/300` · #D8D1CE | `neutral/950` · #16100C | **12.51** | 4.5:1 | PASS |
| Secondary copy on panel | `neutral/300` · #D8D1CE | `neutral/900` · #241D19 | **11.01** | 4.5:1 | PASS |
| Secondary copy on raised | `neutral/300` · #D8D1CE | `neutral/800` · #3D342F | **8.05** | 4.5:1 | PASS |
| Metadata on canvas | `neutral/400` · #B4ABA7 | `neutral/950` · #16100C | **8.37** | 4.5:1 | PASS |
| Metadata on panel | `neutral/400` · #B4ABA7 | `neutral/900` · #241D19 | **7.37** | 4.5:1 | PASS |
| Metadata on raised | `neutral/400` · #B4ABA7 | `neutral/800` · #3D342F | **5.39** | 4.5:1 | PASS |
| Button label on primary CTA | `neutral/0` · #FFFFFF | `brand/500` · #DD2D1D | **4.70** | 4.5:1 | PASS |
| Badge label on sale badge | `neutral/900` · #241D19 | `accent/500` · #EBA500 | **7.84** | 4.5:1 | PASS |
| Label on teal fill | `neutral/0` · #FFFFFF | `teal/600` · #008076 | **4.83** | 4.5:1 | PASS |
| Label on grape fill | `neutral/0` · #FFFFFF | `grape/500` · #9A4CDF | **4.69** | 4.5:1 | PASS |
| Current price on panel | `neutral/50` · #FCFAF9 | `neutral/900` · #241D19 | **15.96** | 4.5:1 | PASS |
| Struck price on panel | `neutral/400` · #B4ABA7 | `neutral/900` · #241D19 | **7.37** | 4.5:1 | PASS |
| Discount on panel | `brand/300` · #FFA191 | `neutral/900` · #241D19 | **8.51** | 4.5:1 | PASS |
| Discount on brand-tinted row | `brand/300` · #FFA191 | `brand/900` · #5E150E | **6.77** | 4.5:1 | PASS |
| Inline link on panel | `teal/300` · #7ED8CC | `neutral/900` · #241D19 | **9.95** | 4.5:1 | PASS |
| Inline link on canvas | `teal/300` · #7ED8CC | `neutral/950` · #16100C | **11.30** | 4.5:1 | PASS |
| In-stock text on panel | `success/300` · #93D594 | `neutral/900` · #241D19 | **9.63** | 4.5:1 | PASS |
| In-stock text in success pill | `success/300` · #93D594 | `success/900` · #183D1B | **7.08** | 4.5:1 | PASS |
| Low-stock text on panel | `warning/300` · #FFB78C | `neutral/900` · #241D19 | **9.81** | 4.5:1 | PASS |
| Low-stock text in warning pill | `warning/300` · #FFB78C | `warning/900` · #592904 | **7.11** | 4.5:1 | PASS |
| Out-of-stock text on panel | `neutral/400` · #B4ABA7 | `neutral/900` · #241D19 | **7.37** | 4.5:1 | PASS |
| Success message in alert | `success/300` · #93D594 | `success/900` · #183D1B | **7.08** | 4.5:1 | PASS |
| Warning message in alert | `warning/300` · #FFB78C | `warning/900` · #592904 | **7.11** | 4.5:1 | PASS |
| Error message in alert | `danger/300` · #FF9EAA | `danger/900` · #5D0E23 | **6.91** | 4.5:1 | PASS |
| Info message in alert | `info/300` · #94C0F7 | `info/900` · #013063 | **6.95** | 4.5:1 | PASS |
| Destructive button label | `neutral/0` · #FFFFFF | `danger/600` · #BE184A | **6.14** | 4.5:1 | PASS |
| Confirm button label | `neutral/0` · #FFFFFF | `success/700` · #2A682F | **6.73** | 4.5:1 | PASS |

### Table E — Dark mode, non-text (requirement 3:1)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| Input border on panel | `neutral/500` · #89807B | `neutral/900` · #241D19 | **4.30** | 3.0:1 | PASS |
| Input border on canvas | `neutral/500` · #89807B | `neutral/950` · #16100C | **4.88** | 3.0:1 | PASS |
| Input border on raised / striped | `neutral/500` · #89807B | `neutral/800` · #3D342F | **3.14** | 3.0:1 | PASS |
| Focus ring outer vs canvas | `teal/400` · #4BBCB0 | `neutral/950` · #16100C | **8.19** | 3.0:1 | PASS |
| Focus ring outer vs panel | `teal/400` · #4BBCB0 | `neutral/900` · #241D19 | **7.21** | 3.0:1 | PASS |
| Focus ring outer vs raised | `teal/400` · #4BBCB0 | `neutral/800` · #3D342F | **5.27** | 3.0:1 | PASS |
| Focus halo (panel colour) vs brand CTA fill | `neutral/900` · #241D19 | `brand/500` · #DD2D1D | **3.53** | 3.0:1 | PASS |
| Focus halo vs focus ring | `neutral/900` · #241D19 | `teal/400` · #4BBCB0 | **7.21** | 3.0:1 | PASS |
| Selected brand border on panel | `brand/400` · #F76854 | `neutral/900` · #241D19 | **5.58** | 3.0:1 | PASS |
| Display text in brand on panel (≥24px) | `brand/400` · #F76854 | `neutral/900` · #241D19 | **5.58** | 3.0:1 | PASS |

### 4.1 Surface-only colours — must NEVER carry text

Measured against white, these are all below 4.5:1 and most are below 3:1. Flagged explicitly so the CI gate and the lead both know.

| Colour | vs `#FFFFFF` | Verdict |
|---|---|---|
| `accent/500` `#EBA500` | **2.12** | **Surface only.** The single most dangerous value in the palette. Text on accent is always `neutral/900`. |
| `accent/400` `#FCB31C` | 1.81 | Surface only |
| `accent/300` `#FFC972` | 1.52 | Surface only |
| `accent/600` `#C38801` | 3.07 | Surface + ≥24px text only. First text-safe accent step is **`accent/700`** (4.75). |
| `brand/400` `#F76854` | 2.97 | Surface only (light mode) |
| `brand/300` `#FFA191` | 1.95 | Surface only in light; it is the dark-mode discount text colour |
| `teal/400` `#4BBCB0` | 2.30 | Surface / stroke only in light |
| `teal/300` `#7ED8CC` | 1.67 | Surface only in light; dark-mode link colour |
| `grape/400` `#B97CF8` | 2.88 | Surface only |
| `grape/300` `#D4AFFF` | 1.85 | Surface only |
| `success/500` `#4EA253` | 3.17 | Non-text indicator / dot only |
| `warning/500` `#E07526` | 3.11 | Non-text indicator / dot only |
| `danger/500` `#E22F5E` | 4.37 | Non-text / border only — **just below 4.5, do not use for text** |
| `info/500` `#3387EB` | 3.62 | Non-text only |
| `neutral/200`–`neutral/400` | 1.25 – 2.25 | Surface / divider only |
| `neutral/500` `#89807B` | 3.86 | Borders and disabled labels only |
| Every ramp's `50`, `100`, `200` | ≤ 1.5 | Surface only, in both modes |

**Text allow-list, enforceable as a lint rule.**
*Light mode text may only be:* `neutral/0` (on dark/saturated fills), `neutral/600`–`neutral/950`, `brand/600`–`900`, `accent/700`–`900`, `teal/600`–`900`, `grape/600`–`900`, `success/700`–`900`, `warning/700`–`900`, `danger/600`–`900`, `info/600`–`900`.
*Dark mode text may only be:* `neutral/900` (on accent fills), `neutral/0`–`neutral/400`, `brand/300`–`400`, `teal/300`–`400`, `grape/300`, `success/300`–`400`, `warning/300`–`400`, `danger/300`–`400`, `info/300`–`400`.

---

## 5. Typography

### 5.1 Family decision — the draft's Latin face is **replaced**

| Role | Family | Google Fonts family string | Weights shipped | Verified |
|---|---|---|---|---|
| Latin UI, headings, **all numerals** | **Hanken Grotesk** | `Hanken Grotesk` | variable `wght 400..800` (ship the 400–800 range; 100–900 exists) | `fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400..800` → 200 OK |
| Bangla | **Anek Bangla** | `Anek Bangla` | static **400, 500, 600, 700** | `...?family=Hind+Siliguri:wght@300;400;500;600;700` → 200 OK |

**Exact request string:**
```
https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400..800&family=Hind+Siliguri:wght@400;500;600;700&display=swap
```

**Why Anek Bangla is confirmed.** It is the most battle-tested screen Bangla face in the market, renders reliably on old Android WebViews, and its short extenders keep 14–16px Bangla legible on a low-DPI panel. Kept without change.

**Why Plus Jakarta Sans is replaced.** Two reasons, one structural and one strategic. Structural: Plus Jakarta Sans is a *geometric* sans — wide, circular, large counters — while Anek Bangla is a *humanist* skeleton with a large x-height and moderate width. This site mixes scripts inside single strings constantly (Banglish search, Bangla product names in an English admin table), and a geometric/humanist mismatch is visible at exactly that boundary. Hanken Grotesk is humanist with a tall x-height and moderate width; it sits on Anek Bangla's baseline without a visible step. Strategic: Jakarta has become the default "friendly premium" face and reads as templated, which the owner's brief explicitly works against.

**Hard constraint discovered — act on it.** *Anek Bangla has no weight 800.* Google Fonts returns HTTP 400 for `Hind+Siliguri:wght@800`. Therefore **every Bangla display style caps at weight 700**, and the type scale below lists Latin and Bangla weights separately. Do not create a Bangla text style at 800; it will silently synthesise a fake bold.

**Numeral strategy (Western 0–9 everywhere, including Bangla UI).** The font stack must put the Latin face **first** so that U+0030–0039 resolve to Hanken Grotesk and Bangla glyphs fall through to Anek Bangla:

```css
--font-latin: 'Hanken Grotesk', system-ui, sans-serif;
--font-bangla: 'Hanken Grotesk', 'Anek Bangla', 'Noto Sans Bengali', sans-serif;
```

Never put `'Anek Bangla'` first, or Bangla UI will render Bengali numerals. Prices, quantities, phone numbers, order numbers and admin tables all add `font-variant-numeric: tabular-nums`; because `tnum` support cannot be guaranteed across the subset, **right-alignment is the guarantee and `tnum` is the enhancement** — every numeric column in the admin is right-aligned regardless.

**Loading.** Self-host via `next/font/google`, `display: swap`, subsets `latin` + `bengali` only, preload the Latin 400/600/700 and Bangla 400/700 faces. Budget: no single font file over 40KB, total font payload ≤ 120KB, zero layout shift (size-adjust fallback metrics generated by `next/font`).

### 5.2 Type scale

1.250 major third, 16px base. Line-height is given as a unitless multiplier; **Figma expects a percentage — multiply by 100.** Bangla line-height is Latin × 1.15, rounded to 2 d.p., as the brief requires.

| Token | Mobile px | Desktop px | Weight (Latin) | Weight (Bangla) | LH Latin | LH Bangla | Tracking (Latin) | Used for |
|---|---|---|---|---|---|---|---|---|
| `display-1` | 32 | 52 | 800 | **700** | 1.06 | 1.22 | −0.022em | Home hero headline. One per page, never more. |
| `display-2` | 26 | 40 | 800 | **700** | 1.12 | 1.29 | −0.018em | Campaign / flash-sale landing headline |
| `h1` | 24 | 34 | 700 | 700 | 1.18 | 1.36 | −0.014em | Page title, PDP product name |
| `h2` | 20 | 27 | 700 | 700 | 1.25 | 1.44 | −0.010em | Section heading ("Best sellers") |
| `h3` | 18 | 21 | 600 | 600 | 1.30 | 1.50 | −0.006em | Card-group title, PDP tab heading |
| `h4` | 16 | 18 | 600 | 600 | 1.35 | 1.55 | 0 | Sub-heading, admin form-section title |
| `body-lg` | 17 | 18 | 400 | 400 | 1.60 | 1.84 | 0 | Product description, blog body |
| `body` | 15 | 16 | 400 | 400 | 1.60 | 1.84 | 0 | Default UI text. **15px is the mobile floor.** |
| `body-sm` | 14 | 14 | 400 | 400 | 1.50 | 1.73 | 0 | Supporting copy, table cells, helper text |
| `caption` | 12 | 12 | 500 | 500 | 1.40 | 1.61 | 0.005em | Metadata, timestamps, badge text |
| `overline` | 11 | 11 | 700 | **600 @ 12px** | 1.30 | 1.50 | 0.09em | Eyebrow label. Latin is uppercase; **Bangla is not transformed** (no case) and steps up to 12px/600 with tracking 0.02em to stay legible. |
| `price-lg` | 24 | 30 | 800 | **700** | 1.15 | 1.32 | −0.012em | PDP price, order total |
| `price` | 17 | 18 | 700 | 700 | 1.25 | 1.44 | −0.008em | Product-card price, cart line total |
| `price-was` | 14 | 14 | 400 | 400 | 1.25 | 1.44 | 0 | Struck original price |
| `button` | 15 | 16 | 600 | 600 | 1.20 | 1.38 | 0.002em | All button labels |
| `label` | 13 | 13 | 600 | 600 | 1.35 | 1.55 | 0.004em | Form field labels, filter facet names |

**Bangla tracking rule, absolute:** *all Bangla tracking is 0* regardless of the Latin value in that row. Negative tracking breaks Bangla conjuncts and the matra line; positive tracking above 0.02em separates ligatures. The `overline` row's 0.02em is the only exception and the maximum.

**Bangla string-length rule.** Bangla labels run roughly 15–25% longer than English. Consequences, all mandatory:
- Buttons have `min-height`, never fixed `height`, and wrap to two lines rather than truncate. `button` line-height 1.38 in Bangla is sized for that second line.
- No button, chip or tab has a fixed width. Auto-layout hug, with `space/4` (16px) minimum horizontal padding at `md`.
- Bottom-tab-bar labels use `caption` at 11px in Bangla (one step down) rather than truncating; five labels must fit 360px.
- Product titles clamp to two lines in both scripts, never one, and never truncate mid-word.

---

## 6. Spacing, radius, elevation, motion

### 6.1 Spacing — 4px base

| Token | px | Token | px |
|---|---|---|---|
| `space/0` | 0 | `space/7` | 28 |
| `space/0.5` | 2 | `space/8` | 32 |
| `space/1` | 4 | `space/10` | 40 |
| `space/2` | 8 | `space/12` | 48 |
| `space/3` | 12 | `space/14` | 56 |
| `space/4` | 16 | `space/16` | 64 |
| `space/5` | 20 | `space/20` | 80 |
| `space/6` | 24 | `space/24` | 96 |
| | | `space/32` | 128 |

Layout and sizing tokens (same Figma collection):

| Token | Value | Note |
|---|---|---|
| `layout/gutter-mobile` | 16 | <768px |
| `layout/gutter-tablet` | 24 | 768–1023px |
| `layout/gutter-desktop` | 32 | ≥1024px |
| `layout/container-max` | 1280 | Max content width |
| `layout/section-mobile` | 48 | Vertical rhythm between sections |
| `layout/section-desktop` | 80 | ditto |
| `size/touch-min` | 44 | WCAG 2.2 SC 2.5.8 target minimum |
| `size/icon-sm` | 16 | |
| `size/icon-md` | 20 | Default |
| `size/icon-lg` | 24 | |
| `size/icon-category` | 48 | Custom two-tone category icons |
| `size/control-sm` | 36 | Admin-dense control height |
| `size/control-md` | 44 | Default control height |
| `size/control-lg` | 52 | Storefront primary CTA |
| `border-width/hairline` | 1 | Dividers, input rest |
| `border-width/strong` | 2 | Hover, selected, focus ring stroke |

### 6.2 Radius — one system across storefront and admin

| Token | px |
|---|---|
| `radius/none` | 0 |
| `radius/xs` | 4 |
| `radius/sm` | 8 |
| `radius/md` | 12 |
| `radius/lg` | 16 |
| `radius/xl` | 24 |
| `radius/2xl` | 32 |
| `radius/full` | 9999 |

**The draft's split is changed.** The draft specified pill (`full`) buttons on the storefront and `md` in the admin. I am replacing that with **`radius/md` buttons everywhere**, for three reasons: (1) a system with two different button shapes depending on which half of the product you are in is not one system, and the premium read in §1 comes from exactly that kind of consistency; (2) pill buttons read as generic consumer-app, which is the look the brief refuses; (3) decisively — **pills fight the Bangla constraint.** A pill needs horizontal padding of roughly half its height to look intentional, so a 44px pill wants ~22px side padding; add 15–25% Bangla string growth and primary CTAs either overflow the 360px viewport or must shrink their type. `radius/md` tolerates a two-line Bangla label without looking broken. The playful signal is relocated to the category tiles and badges below, where it costs nothing.

| Component role | Radius | Why |
|---|---|---|
| Button (all variants, storefront + admin) | `md` 12 | One shape everywhere; tolerates two-line Bangla; reads engineered, not cute |
| Icon button | `md` 12 | Matches buttons; `full` only for avatar-style controls |
| Input, textarea, select, stepper | `md` 12 | Visually paired with the button it sits beside |
| Checkbox | `xs` 4 | Must not be confused with a radio |
| Radio, switch, avatar | `full` | Shape *is* the affordance |
| Badge, chip, pill, stock indicator, filter token | `full` | **The only pills in the system.** Small, non-focusable, high-frequency — this is where the playfulness is cheapest and most visible |
| Product card, product image box | `lg` 16 | Generous but not bubbly; image inherits the card's top corners |
| Category tile (colour-carrying) | `xl` 24 | The playful surface. Larger radius + flat saturated fill = toy shelf |
| Hero / campaign banner | `xl` 24 | ditto |
| Admin card / panel / data-table container | `md` 12 | Tighter than the storefront card, reflecting density. Table cells `none` |
| Modal, drawer, mobile bottom sheet | `xl` 24 | Bottom sheet: `xl` on top corners only |
| Popover, dropdown, tooltip, toast | `sm` 8 | Transient and small; large radius wastes the corners |
| Alert / inline message | `md` 12 | |
| Gallery thumbnail rail | `sm` 8 | |
| Accordion | `none` | Divider-based, not boxed |
| Skeleton | matches the element it replaces | Prevents a radius pop on load |

### 6.3 Elevation — four levels, warm-tinted

Shadow colour is derived from `neutral/900` = `rgb(36, 29, 25)`, not from black. A neutral-grey shadow goes visibly cold and cheap against a vermilion/marigold palette. Each level is two layers — a tight contact shadow plus a diffuse ambient one — because a single blur reads as a sticker.

| Token | Light-mode `box-shadow` | Used for |
|---|---|---|
| `shadow/e1` | `0 1px 2px 0 rgba(36,29,25,0.07)` | Resting product card, input rest, admin panel |
| `shadow/e2` | `0 1px 2px 0 rgba(36,29,25,0.05), 0 4px 10px -2px rgba(36,29,25,0.09)` | Hovered card, sticky header, sticky add-to-cart bar, admin sticky save bar |
| `shadow/e3` | `0 2px 4px -1px rgba(36,29,25,0.05), 0 12px 24px -6px rgba(36,29,25,0.12)` | Dropdown, popover, mega menu, mobile filter sheet |
| `shadow/e4` | `0 4px 8px -2px rgba(36,29,25,0.06), 0 28px 56px -12px rgba(36,29,25,0.18)` | Modal, drawer, confirm dialog |
| `shadow/focus` | `0 0 0 2px var(--color-bg-surface), 0 0 0 4px var(--color-border-focus)` | Every `:focus-visible` state. Not optional, not replaceable by `outline: none`. |

**Dark-mode substitutes.** Shadows are near-invisible on dark surfaces, so each level becomes an inset hairline plus a *pure-black* ambient shadow (warm tint muddies on dark):

| Token | Dark-mode value |
|---|---|
| `shadow/e1` | `inset 0 0 0 1px #3D342F` (`neutral/800`) |
| `shadow/e2` | `inset 0 0 0 1px #3D342F, 0 2px 6px -1px rgba(0,0,0,0.60)` |
| `shadow/e3` | `inset 0 0 0 1px #594F4A, 0 12px 24px -6px rgba(0,0,0,0.70)` (`neutral/700`) |
| `shadow/e4` | `inset 0 0 0 1px #594F4A, 0 28px 56px -12px rgba(0,0,0,0.80)` |
| `shadow/focus` | unchanged — it already resolves through `bg/surface` and `border/focus` |

### 6.4 Motion

| Token | Value | Used for |
|---|---|---|
| `duration/instant` | 0ms | `prefers-reduced-motion: reduce` substitute for everything below |
| `duration/fast` | 120ms | Hover, active, colour and opacity changes, icon state, ripple-free press |
| `duration/base` | 200ms | Dropdown, accordion, tooltip, tab switch, chip add/remove, toast in |
| `duration/slow` | 320ms | Modal, drawer, bottom sheet, mega-menu open, page-section reveal |
| `duration/deliberate` | 480ms | Mobile filter bottom sheet travelling full viewport height. Nothing else. |
| `easing/standard` | `cubic-bezier(0.2, 0, 0, 1)` | Default for anything that moves and stays |
| `easing/decelerate` | `cubic-bezier(0, 0, 0.2, 1)` | Entering the screen |
| `easing/accelerate` | `cubic-bezier(0.4, 0, 1, 1)` | Leaving the screen |
| `easing/spring` | `cubic-bezier(0.34, 1.42, 0.64, 1)` | **One place only** — see below |

**The one permitted spring.** `easing/spring` is used exclusively on the **cart-count badge** in the header and the mobile bottom tab bar, when an item is added to the cart: `transform: scale(1 → 1.18 → 1)` over `duration/base`. That is the moment a child's energy belongs in the interface and a parent reads it as confirmation. It appears nowhere else — not on buttons, not on cards, not on page transitions, and never on anything in checkout.

Non-negotiables: `prefers-reduced-motion: reduce` collapses every duration to `duration/instant` and disables the spring. No carousel auto-advances. Nothing animates `width`, `height`, `top` or `left` — transform and opacity only, to hold INP ≤ 200ms on a mid-range Android.

---

## 7. Figma variable collection plan

Build order is strict: **Primitives → Color → Spacing → Radius → Typography.** A later collection aliases an earlier one; nothing aliases forwards.

### 7.1 Collections and modes

| # | Collection | Modes | Contents | Publishing |
|---|---|---|---|---|
| 1 | `Primitives` | `Value` (1) | Every hex from §2: `brand/*`, `accent/*`, `teal/*`, `grape/*`, `neutral/*` (incl. `0`, `950`, `ink-alpha-60`, `ink-alpha-72`), `success/*`, `warning/*`, `danger/*`, `info/*`. **COLOR** type. | `hiddenFromPublishing: true` — designers must never pick a primitive directly |
| 2 | `Color` | `Light`, `Dark` | Every token from §3, each mode value an **alias** to a `Primitives` variable. **COLOR** type. | published |
| 3 | `Spacing` | `Value` (1) | `space/*`, `layout/*`, `size/*`, `border-width/*`. **FLOAT** type. | published |
| 4 | `Radius` | `Value` (1) | `radius/*`. **FLOAT** type. | published |
| 5 | `Typography` | `Value` (1) | `font/family/*` (STRING), `font/weight/*`, `font/size/*`, `font/line-height/*`, `font/tracking/*` (FLOAT). | published |

Name the modes exactly `Light` and `Dark` in collection 2, and exactly `Value` in collections 1, 3, 4 and 5. Mode names are referenced by the token-export script; a rename breaks it.

### 7.2 `scopes` array per token group

| Collection / group | `scopes` |
|---|---|
| `Primitives` — all | `["ALL_SCOPES"]` |
| `Color` → `color/bg/*` | `["FRAME_FILL", "SHAPE_FILL"]` |
| `Color` → `color/text/*` | `["TEXT_FILL"]` |
| `Color` → `color/border/*` | `["STROKE_COLOR"]` |
| `Color` → `color/status/*` | `["TEXT_FILL", "SHAPE_FILL", "STROKE_COLOR"]` |
| `Color` → `color/feedback/*` | `["TEXT_FILL", "STROKE_COLOR"]` |
| `Spacing` → `space/*`, `layout/gutter-*`, `layout/section-*` | `["GAP", "WIDTH_HEIGHT"]` |
| `Spacing` → `layout/container-max`, `size/*` | `["WIDTH_HEIGHT"]` |
| `Spacing` → `border-width/*` | `["STROKE_FLOAT"]` |
| `Radius` → `radius/*` | `["CORNER_RADIUS"]` |
| `Typography` → `font/family/*` | `["FONT_FAMILY"]` |
| `Typography` → `font/weight/*` | `["FONT_WEIGHT"]` |
| `Typography` → `font/size/*` | `["FONT_SIZE"]` |
| `Typography` → `font/line-height/*` | `["LINE_HEIGHT"]` |
| `Typography` → `font/tracking/*` | `["LETTER_SPACING"]` |

Scoping `color/text/*` to `TEXT_FILL` only is what stops a designer painting a frame with `color/text/primary` — the mechanical half of the §4.1 allow-list.

### 7.3 WEB `codeSyntax` pattern

Every variable gets `codeSyntax: { "WEB": "<string>" }`. The transform is mechanical: **take the variable's full path, replace every `/` with `-`, lowercase it, prefix `--`, wrap in `var()`.** The `var()` wrapper is mandatory so that Dev Mode emits a usable declaration rather than a bare name.

| Variable | `codeSyntax.WEB` |
|---|---|
| `brand/500` | `var(--brand-500)` |
| `neutral/ink-alpha-60` | `var(--neutral-ink-alpha-60)` |
| `color/bg/canvas` | `var(--color-bg-canvas)` |
| `color/bg/surface-raised` | `var(--color-bg-surface-raised)` |
| `color/text/on-brand` | `var(--color-text-on-brand)` |
| `color/text/price-was` | `var(--color-text-price-was)` |
| `color/border/focus` | `var(--color-border-focus)` |
| `color/status/out-of-stock` | `var(--color-status-out-of-stock)` |
| `color/feedback/danger` | `var(--color-feedback-danger)` |
| `space/4` | `var(--space-4)` |
| `space/0.5` | `var(--space-0-5)` *(the only special case: `.` → `-`)* |
| `layout/gutter-mobile` | `var(--layout-gutter-mobile)` |
| `size/touch-min` | `var(--size-touch-min)` |
| `border-width/strong` | `var(--border-width-strong)` |
| `radius/md` | `var(--radius-md)` |
| `font/family/latin` | `var(--font-family-latin)` |
| `font/weight/display-1-latin` | `var(--font-weight-display-1-latin)` |
| `font/size/body/mobile` | `var(--font-size-body-mobile)` |
| `font/line-height/body/bangla` | `var(--font-line-height-body-bangla)` |
| `font/tracking/display-1` | `var(--font-tracking-display-1)` |

### 7.4 Typography collection — exact variable naming

Because the scale is dual-script and dual-breakpoint, create four variables per scale step where they differ:

```
font/size/<token>/mobile          FLOAT   e.g. font/size/body/mobile = 15
font/size/<token>/desktop         FLOAT   e.g. font/size/body/desktop = 16
font/line-height/<token>/latin    FLOAT   percentage, e.g. 160
font/line-height/<token>/bangla   FLOAT   percentage, e.g. 184
font/tracking/<token>             FLOAT   px at the desktop size; Bangla styles use 0
font/weight/<token>/latin         FLOAT   e.g. 800
font/weight/<token>/bangla        FLOAT   e.g. 700
font/family/latin                 STRING  "Hanken Grotesk"
font/family/bangla                STRING  "Anek Bangla"
```

Then build **two parallel sets of Figma text styles** that consume them: `Latin/display-1`, `Latin/body`, … and `Bangla/display-1`, `Bangla/body`, …. Figma text styles are not variables and cannot carry modes, so the duality has to live in the style names. Mobile and desktop are separate styles too: `Latin/Mobile/body` and `Latin/Desktop/body`.

### 7.5 What does NOT go in a Figma variable — do not attempt

- **Elevation.** Figma variables cannot hold a shadow. Create **effect styles** instead: `Elevation/Light/e1`–`e4` and `Elevation/Dark/e1`–`e4`, with the exact values from §6.3. Effect styles have no mode support, which is why there are two named sets.
- **Motion.** Figma cannot bind prototype durations or easing to variables. Document §6.4 as a labelled frame on the Foundations page and treat the code token file as the source of truth. Do not create a `Motion` collection; it would be a lie the build could not consume.
- **`shadow/focus`.** It resolves through two `Color` variables, so build it as an effect style named `Focus/ring` that references `color/bg/surface` and `color/border/focus`, and re-point it per mode manually on the two Foundations artboards.

### 7.6 Parity contract with code

Figma variable name ↔ CSS custom property ↔ Tailwind token are the same string: `color/bg/brand` ⟷ `--color-bg-brand` ⟷ `bg-brand`. A screen containing a raw hex or an unaliased number is a defect, and it means a token is missing — raise it rather than hardcoding.

---

## 8. Logo and wordmark

No logo exists. Two options below, each described tightly enough to be drawn from this text alone. Construction grids are given in units (`u`) so both scale losslessly. **No image files generated.**

### Option 1 — "Blockmark" (container-first monogram) — RECOMMENDED

**Form.** A 1:1 rounded-square tile, corner radius 24% of the side (optically `radius/xl` at logo scale), filled flat `brand/500` `#DD2D1D`. Inside it, on a 6×6u grid with 1u of clear space on all sides (so the drawing area is 4×4u), a single continuous white knockout shape built from exactly three modules:

1. A horizontal bar, 4u × 1u, flush to the top of the drawing area — the crossbar of a **T**.
2. A vertical stem, 1u × 3u, dropping from the bar's horizontal centre.
3. A quarter-circle arc of outer radius 2u and stroke weight 1u, joined to the foot of the stem and sweeping right and up, so the stem's base curves out like a rocker.

All knockout corners are radius 0.25u except the arc, which is a true quarter-circle. The result is a **T** that is simultaneously a rocking-horse runner and the opening stroke of the **M** that follows in the wordmark — the mark and the word interlock rather than sit beside each other.

**Colour usage.** Default: one flat `brand/500` tile, one white knockout. Two-colour variant, permitted only at ≥48px: the arc alone in `accent/500` `#EBA500`. Below 48px the arc reverts to white. Inverse variant for dark backgrounds: tile in `neutral/0`, knockout in `brand/500`. There is no gradient, no outline and no shadow version.

**Wordmark.** `toymart` in Hanken Grotesk 800, all lowercase, tracking −0.02em, in `neutral/900` `#241D19`; followed by `bd` in Hanken Grotesk 600 at 70% of the cap height, baseline-aligned, in `neutral/600` `#6E655F`. Horizontal lockup: tile at left, gap = 0.5 × tile height, wordmark cap-height = 0.52 × tile height. Stacked lockup for square placements: tile above, wordmark centred below at gap = 0.3 × tile height.

**At 24px favicon.** The mark survives because it is container-first: the vermilion rounded square is itself the recognisable unit, and the knockout stays legible because every stroke is 1u = 4px at 24px — above the 3px floor where sub-pixel rendering destroys detail on a low-DPI Android. Drop the marigold arc; drop the wordmark entirely. The 16px variant is the tile with only modules 1 and 2 (plain T), which is the only reduction drawing ever needed.

**One colour on a courier label.** Already one colour by construction. On a 203dpi thermal printer the tile prints 100% K (or, on a pre-printed label, stays as the unprinted paper inside a K field) and the knockout stays paper-white. At a 12mm tile the 1u stroke is 2mm — comfortably above the ~0.4mm thermal floor. No tonal conversion, no halftone, no reduction artwork. The same file also embroiders onto a delivery bag and die-cuts as a sticker.

### Option 2 — "Ghuri" (four-hue kite, pictorial)

**Form.** A diamond — a square rotated 45°, 6u on the diagonal — divided by both its diagonals into four flat triangles, with a 0.5u white gap along each dividing line so the quadrants read as separate painted panels rather than a pinwheel gradient: top `brand/500`, left `accent/500`, right `teal/600`, bottom `grape/500`. A tail leaves the bottom vertex: a single 0.5u-weight stroke in `neutral/900`, one shallow S-curve of total length 4u, terminating in a 1.5u × 0.5u bar in `accent/500`. This is the Bangladeshi kite (*ghuri*), a child's object and a flat geometric form, and it is the rickshaw-plate chromatic thesis of §1 stated literally — all four brand hues, hard-edged, unblended, in one mark.

**Colour usage.** The four-hue version is the only full-colour version. Two-colour reduction: `brand/500` top and bottom, `neutral/900` left and right. One-colour: 100% K diamond with white 0.5u dividing lines and the tail in white when knocked out of a K field.

**Wordmark.** `TOYMART BD` in Hanken Grotesk 700, uppercase, tracking +0.06em, in `neutral/900`, set to the diamond's height. Uppercase here because the diamond is already the soft shape; a lowercase wordmark alongside it goes shapeless.

**At 24px favicon.** Weak. The tail must be dropped below 32px, so the favicon is the bare diamond, and at 24px the four 12px-wide quadrants plus three 2px white gaps turn to mush on a low-DPI panel. A dedicated 24px reduction — solid `brand/500` diamond with one white diagonal — is mandatory, which means two marks to maintain.

**One colour on a courier label.** The real cost. Four equally-weighted quadrants have no honest tonal order, so a one-colour label needs either a tint ladder (100% / 70% / 40% / 15% K) that thermal printers reproduce unpredictably, or the outline-plus-two-lines reduction, which discards the colour story that justifies the mark in the first place.

### Recommendation — Option 1

Option 2 has the better story and the stronger link to §1. Option 1 wins because it never needs a reduction drawing. It is pixel-identical in intent at 24px, at 2000px, on a thermal courier label, embroidered on a rider's bag and die-cut as a sticker — and crucially, a container-based mark is the only kind that survives the circular avatar crop on Facebook, WhatsApp and Instagram, which is where most Bangladeshi toy-shop discovery actually begins. A business with no logo today cannot afford an identity that requires four pieces of reduction artwork and a print-tolerance conversation before its first courier label goes out. Keep Option 2's four-hue diamond — it is a good motif, and it belongs in the pattern library for packaging tape, empty-state illustration and the 404 page, not in the logo slot.

---

## 9. Hand-off checklist for the lead

1. Create `Primitives` (1 mode `Value`), enter §2 verbatim, set `hiddenFromPublishing: true`, scopes `["ALL_SCOPES"]`, `codeSyntax` per §7.3.
2. Create `Color` (modes `Light`, `Dark`), enter §3 as aliases only. Any raw hex in this collection is a defect.
3. Create `Spacing`, `Radius`, `Typography` per §6.1, §6.2, §7.4, with the scopes in §7.2.
4. Create effect styles `Elevation/Light/e1`–`e4`, `Elevation/Dark/e1`–`e4`, `Focus/ring` per §6.3 and §7.5.
5. Create text styles `Latin/{Mobile,Desktop}/<token>` and `Bangla/{Mobile,Desktop}/<token>` per §5.2. **No Bangla style above weight 700.**
6. Lay out the Foundations page: colour ramps, the §4 contrast tables as a frame, the type scale rendered in both scripts with real Bangla strings, the radius-by-role table from §6.2, the elevation ladder in both modes, and the motion documentation frame from §7.5.
7. Do not build components until every token above exists and is bound.


---

## 10. Correction — Bangla face replaced (2026-11-08, after this spec was written)

**`Hind Siliguri` → `Anek Bangla`**, on the owner's instruction, applied in the live Figma file.

This spec's §5.1 confirmed Hind Siliguri and recorded that it has **no weight 800**, so §5.2 capped Bangla `display-1`, `display-2` and `price-lg` at 700 while Latin used 800. That cap is now removed: Anek Bangla ships Thin→ExtraBold, so **those three tokens are 800 in both scripts** and the two scripts carry identical weights at every step.

Changes applied:

| | Before | After |
|---|---|---|
| `font/family/bangla` | `Hind Siliguri` | **`Anek Bangla`** |
| `font/weight/display-1/bangla` | 700 | **800** |
| `font/weight/display-2/bangla` | 700 | **800** |
| `font/weight/price-lg/bangla` | 700 | **800** |
| All 32 `Bangla/*` text styles | Hind Siliguri | **Anek Bangla** |

Everything else in §5.2 is unchanged — sizes, the 1.15× Bangla line-heights, the absolute zero-tracking rule, and the `overline` exception (12px / 600 / 0.02em) all still hold, because they are properties of the script rather than of the face.

**Open check, owner-facing:** Anek Bangla is newer in Bangladesh than Hind Siliguri. A native reader must verify conjunct (যুক্তাক্ষর) and matra rendering at 15px on a real mid-range Android before launch. Fallback if it fails: **Noto Sans Bengali UI** (12 weights, tighter UI metrics) — a token change, not a redesign.

### Figma platform deviations found while building this spec

Two items in §7 did not survive contact with the Plugin API, and the file reflects the corrected approach:

1. **`space/0.5` is named `space/0-5`.** Figma rejects `.` in a variable name. Its CSS custom property is unchanged (`--space-0-5`), so the §7.3 parity contract still holds.
2. **Line-height is NOT variable-bound.** A FLOAT variable bound to `lineHeight` is interpreted by Figma as **pixels**, so the percentage values (106, 160, 184 …) were being applied as 106px / 160px / 184px — body text rendered with a 160px line-height. Line-height is therefore set explicitly as a PERCENT on each text style, which is also the better choice for a dual-script system: a percentage follows a size change, a pixel value does not. The `font/line-height/*` variables are kept, scoped to `LINE_HEIGHT`, as the source for the CSS export (where the value is a unitless multiplier). This belongs in the same category as §7.5's elevation and motion limitations.

`fontSize`, `letterSpacing` and `fontFamily` **are** bound to variables successfully on all 64 styles.


---

## 11. Button treatment — decided at build time (2026-11-08)

Three changes made while building the component, on the owner's instruction that the button "should be creative". All stay inside §1's refusal of gradients, glows and glass.

### 11.1 The plate

Filled variants (`Primary`, `Secondary`, `Danger`) sit on a **3px hard shelf** in a darker shade of the same hue. On press the shelf collapses to 0 and the face translates down 3px, so the control is physically pressable rather than merely coloured.

It is a **flat fill, never a shadow.** That is what keeps it inside the direction: a rickshaw back-plate is printed, hard-edged and fully opaque, and a blurred drop shadow would be the generic consumer-app look §1 explicitly refuses. It also earns its place functionally — it is a press affordance, not decoration — and it reads as a physical toy button, which is the brief's "user friendly like kids" without resorting to bubble shapes.

| Token | Light | Dark | Used by |
|---|---|---|---|
| `color/bg/brand-shelf` | `brand/700` | `brand/700` | Primary |
| `color/bg/brand-subtle-shelf` | `brand/200` | `brand/700` | Secondary |
| `color/bg/danger-shelf` | `danger/800` | `danger/800` | Danger |

`Outline` and `Ghost` carry **no** shelf — they are the quiet variants and a shelf on a borderless control reads as a rendering artefact. Outer height is identical across all five variants, so a row of mixed buttons still aligns.

### 11.2 Radius: `md` (12) → `lg` (16)

§6.2's argument was against **pills**, and it still holds: a pill needs roughly half its height in side padding, and Bangla labels run 15–25% longer, so a 44px pill either overflows 360px or has to shrink its type. `radius/lg` is not a pill — at 44px height a pill would be 22. 16 is friendlier than 12 at the same padding and costs nothing. **The one-shape rule is preserved: every button at every size is `radius/lg`.**

### 11.3 Icon-to-label gap: 8/8/12 → 12 everywhere

The measured gap was correct to spec, but the Lucide cart glyph fills its 16px box almost edge to edge, so 8px read as touching at `Size=Small`. `space/3` (12) across all three sizes is uniform, token-valid, and changes no height. `iconLeft` now defaults to **true** — an icon on "Add to cart" is useful, not ornament.

### 11.4 Two Figma traps hit while building this

1. **`resize()` resets both sizing modes to FIXED.** Calling it after setting `primaryAxisSizingMode = 'AUTO'` silently locked every button to its min-width and clipped the label. Resize first, then set the modes. This would have truncated every Bangla label in the system.
2. **An instance child's transform cannot be overridden** (`relative-transform`), so the pressed state cannot be shown by moving the face down. It is rendered instead by extending the face over the shelf, which looks identical and is what the CSS produces.


---

## 12. Icon set — Phosphor (decided 2026-11-08)

**Lucide → Phosphor Icons (regular weight), on the owner's instruction to use a premium set.**

### Why

1. **Lucide is the default.** It is what every Tailwind/shadcn starter ships with, so it reads as the templated look §1 refuses. Phosphor is a deliberate choice rather than an inherited one.
2. **It is drawn for this pairing.** Phosphor's geometric-humanist forms on a 256 grid sit beside Hanken Grotesk without a skeleton mismatch, the same reason Anek Bangla replaced Hind Siliguri.
3. **It fixes a real rendering bug.** Lucide icons are **stroked**; Phosphor's are **filled outlines**. A stroke does not scale with its node, so a 24px Lucide component instanced at 16px rendered at the wrong weight — and because the instance was resized without `SCALE` constraints, the artwork was being cropped rather than scaled. Filled paths scale perfectly at any size and the whole class of bug disappears.
4. **Six weights, including Duotone**, which the content spec already needs for the two-tone category icons.

### How it was done

Path data was **fetched from `@phosphor-icons/core@2.1.1`**, never hand-written. Fabricated path data renders as a broken glyph, which was the defect being fixed. 51 icons are in the library.

The 20 existing components were **rebuilt in place** rather than replaced, so every existing instance and every `INSTANCE_SWAP` default id stayed valid. Children carry `constraints: SCALE`.

**Button icon:** `shopping-cart-simple`, not `shopping-bag` — the bag reads as a plain box at 16px, while the cart is unambiguous for "Add to cart".

**Colour is a FILL, not a stroke.** Any leftover stroke override from the Lucide era leaves a hairline outline on the glyph and must be cleared.

---

## 13. Three more Figma traps, all hit while building components

These cost a rebuild each and are now in `CLAUDE.md`.

1. **A component property has ONE default across the whole set, and it overrides per-variant content.** Binding Badge's `icon` visibility and `iconSwap` put the same circle on all seven types and forced an icon onto the three that must not have one. Where content differs per variant — Badge labels, Badge icons — it must be **per-variant, not a property**. The nested instance is still swappable on an instance without one.
2. **The same applies to visibility.** A layer whose `visible` is bound to a property cannot be toggled directly on an instance; the property wins. Use `instance.setProperties({...})`. This is why the focus ring would not appear on the states board.
3. **`componentPropertyReferences` cannot be set to `null`**, nor to `{field: null}`. To unbind, delete the property — `deleteComponentProperty` removes the bindings with it.

Also confirmed: `addComponentProperty` with type `INSTANCE_SWAP` wants the component's **node id**, not its `key`.
