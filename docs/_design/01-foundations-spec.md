# Toy Mart BD — Foundations Specification (FINAL)

**Owner:** Design Agent 1 · **Date:** 2026-11-08 · **Status:** Final — execute as written
**Revised 2026-11-11:** colour palette re-anchored to a soft toy-shop pastel direction (§14) a kid-friendly style pass on type, radius, buttons and shadows (§15), an exact colour match to the reference shop (§16, since withdrawn) and its replacement — a similar-not-exact palette with gradient candy buttons and zero contrast failures (§17), all at the owner's request. Values in §2–§4 are the revised ones; the §2.4 log is kept as history.
**Supersedes:** `docs/UI-UX-DESIGN-SPEC.md` §2 and §3 in full.
**Audience:** the lead agent, who types these values into Figma. Every number here is final. There is no TBD and no range where a single value is required.
**Owner's brief, verbatim:** *"modern creative, premium and user friendly like kids."*

---

## 1. Brand rationale

**Rickshaw-plate chromatics, Swiss-grid discipline.**

> **2026-11-11 revision (§14):** the hues below were softened toward a pastel toy-shop palette — coral rose, sunflower, sky and lavender on cream paper with navy ink. The flatness, the 12% area budget and the decision-surface ban are unchanged.

Bangladesh already owns a native visual language for cheap joy: the painted rickshaw back-plate — vermilion, marigold, peacock teal and orchid violet laid down as flat, hard-edged, fully opaque fields, never blended into each other. We take the palette and the flatness and nothing else. No gradients, no glows, no glass, no coloured drop shadows, no 3D.

The one thing that makes this premium rather than merely colourful is **the area budget**. Saturated colour is capped at roughly 12% of any viewport, and it is forbidden inside a decision surface — product-card body, cart, checkout, address form, account, any admin form. Everything structural is warm ink (`#232E4A`) on warm paper (`#FEF8F2`) on a strict 4px grid, in one humanist sans with deliberately tight display tracking and tabular figures. Richness comes from colour relationships and generous macro-spacing, not from effects. The page reads as a well-printed object, not a bright app.

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
| **`brand`** coral rose (OKLCh h 20) | `#FEF5F8` | `#FFE1EA` | `#FFD5E3` | `#FEBDC6` | `#FB6265` | **`#DC3442`** | `#BD303A` | `#961022` | `#841520` | `#60000F` |
| **`accent`** sunflower (h 84) | `#FEFBEC` | `#FBEEBA` | `#F9E695` | `#F8DD77` | `#F8D568` | **`#F7CE54`** | `#BF9224` | `#856716` | `#58460B` | `#3C2F05` |
| **`teal`** sky (h 218) | `#F0FAFD` | `#D5EEF5` | `#BFECF8` | `#9BDDEF` | `#6EC8DF` | **`#42A2BF`** | `#38869E` | `#1E7188` | `#045063` | `#023644` |
| **`grape`** lavender (h 303) | `#FAF6FE` | `#E7D9F5` | `#DCC7F1` | `#CAAFE4` | `#B692E0` | **`#8B51DE`** | `#7540BF` | `#602EA1` | `#582099` | `#3F0076` |

### 2.2 Neutral ramp — cream paper / navy ink (light end OKLCh h 40–60, dark end h 265)

| 0 | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 950 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `#FFFFFF` | `#FEFBF8` | `#FEF8F2` | `#F6EEE6` | `#E9DFD5` | `#BABECB` | `#7C8199` | `#616580` | `#474F6A` | `#313A57` | `#232E4A` | `#151B30` |

The bias is deliberate and split: `50`–`300` are warm cream (OKLCh h 40–60, chroma ≈ 0.006–0.012) so pages read as paper, and `400`–`950` are navy ink (h 265, chroma 0.018 → 0.052) so text reads crisp rather than brown. Nothing in this ramp is a pure grey. Elevation shadows (§6.3) should be re-derived from the new `neutral/900`; until then the effect styles keep their warm tint.

Two alpha primitives also live in this collection, because semantic tokens may never carry raw colour:

| Token | Value |
|---|---|
| `neutral/ink-alpha-60` | `rgba(25, 36, 63, 0.60)` |
| `neutral/ink-alpha-72` | `rgba(10, 19, 40, 0.72)` |

### 2.3 Status ramps

Each carries **50 / 100 / 300 / 400 / 500 / 600 / 700 / 800 / 900** — more than the required minimum, for the reason given in §2.4.

| Ramp | 50 | 100 | 300 | 400 | 500 | 600 | 700 | 800 | 900 |
|---|---|---|---|---|---|---|---|---|---|
| **`success`** (h 145) | `#EFFBF5` | `#D6F5E5` | `#9DE3C1` | `#72CDA2` | `#49C08D` | `#1B9769` | `#017D54` | `#035639` | `#023A25` |
| **`warning`** (h 52) | `#FEF6EF` | `#FCE1CB` | `#FEC392` | `#F89F4E` | `#DF7D39` | `#B85E16` | `#994A01` | `#703500` | `#4E2301` |
| **`danger`** raspberry (h 356) | `#FEF3F7` | `#FDE5ED` | `#FA9EC0` | `#EE67A0` | `#DA3285` | `#B71D6C` | `#951557` | `#751345` | `#5A0F34` |
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
| `color/border/strong` | `neutral/500` | `neutral/400` | Input / control boundaries — meets 3:1 in both modes |
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
| `color/bg/teal` | `teal/700` | `teal/700` | The `teal` ramp existed with no fill token; category tiles need it. (`teal/700` since §14: white on `teal/600` fell to 3.4:1.) |
| `color/bg/teal-subtle` | `teal/50` | `teal/900` | Tinted "shop by age" bands. |
| `color/bg/grape` | `grape/500` | `grape/500` | Second category-tile fill. |
| `color/bg/grape-subtle` | `grape/50` | `grape/900` | Tinted editorial / blog bands. |
| `color/bg/danger` | `danger/700` | `danger/700` | Destructive admin buttons had no fill token. (`danger/700` since §14, so a destructive fill is clearly darker than the coral-rose CTA.) |
| `color/bg/tile-pink` / `-butter` / `-mint` / `-sky` / `-lavender` | `brand/100` / `accent/100` / `success/100` / `teal/100` / `grape/100` | the matching `/900` | Pastel category and promo tile surfaces (added §14). Text on them is `color/text/primary`; never behind a price, form or checkout. |
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
| `color/text/disabled` | `neutral/600` | `neutral/400` | Disabled label; exempt from 1.4.3 by design, so it must be a separate token rather than a misused `tertiary`. |
| `color/text/inverse` | `neutral/0` | `neutral/900` | Text on ink fills (toasts, tooltips). |
| `color/border/brand` | `brand/500` | `brand/400` | Selected card / checked control boundary at 3:1. |
| `color/border/danger` | `danger/500` | `danger/400` | Invalid field boundary at 3:1 without a red fill. |

### 3.3 The focus indicator is a composite, not a single stroke

A single-colour ring cannot clear 3:1 against both white paper *and* a coral-rose CTA fill (`teal/600` vs `brand/500` is only about 1.4:1). The ring is therefore two strokes:

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
| Body / heading <24px on page background | `neutral/900` · #232E4A | `neutral/100` · #FEF8F2 | **12.76** | 4.5:1 | PASS |
| Body / heading <24px on card | `neutral/900` · #232E4A | `neutral/0` · #FFFFFF | **13.45** | 4.5:1 | PASS |
| Body on striped row / inert fill | `neutral/900` · #232E4A | `neutral/200` · #F6EEE6 | **11.72** | 4.5:1 | PASS |
| Body on brand-tinted section | `neutral/900` · #232E4A | `brand/50` · #FEF5F8 | **12.57** | 4.5:1 | PASS |
| Body on accent-tinted section | `neutral/900` · #232E4A | `accent/50` · #FEFBEC | **12.95** | 4.5:1 | PASS |
| Body on teal-tinted tile | `neutral/900` · #232E4A | `teal/50` · #F0FAFD | **12.68** | 4.5:1 | PASS |
| Body on grape-tinted tile | `neutral/900` · #232E4A | `grape/50` · #FAF6FE | **12.61** | 4.5:1 | PASS |
| Secondary copy on page background | `neutral/700` · #474F6A | `neutral/100` · #FEF8F2 | **7.68** | 4.5:1 | PASS |
| Secondary copy on card | `neutral/700` · #474F6A | `neutral/0` · #FFFFFF | **8.09** | 4.5:1 | PASS |
| Secondary copy on striped row | `neutral/700` · #474F6A | `neutral/200` · #F6EEE6 | **7.05** | 4.5:1 | PASS |
| Secondary copy on brand-tinted section | `neutral/700` · #474F6A | `brand/50` · #FEF5F8 | **7.57** | 4.5:1 | PASS |
| Metadata / placeholder on page background | `neutral/600` · #616580 | `neutral/100` · #FEF8F2 | **5.41** | 4.5:1 | PASS |
| Metadata / placeholder on card | `neutral/600` · #616580 | `neutral/0` · #FFFFFF | **5.70** | 4.5:1 | PASS |
| Metadata on striped row | `neutral/600` · #616580 | `neutral/200` · #F6EEE6 | **4.97** | 4.5:1 | PASS |
| Button label on primary CTA | `neutral/0` · #FFFFFF | `brand/500` · #DC3442 | **4.55** | 4.5:1 | PASS |
| Badge label on sale badge | `neutral/900` · #232E4A | `accent/500` · #F7CE54 | **8.91** | 4.5:1 | PASS |
| Label on teal fill (category tile) | `neutral/0` · #FFFFFF | `teal/700` · #1E7188 | **5.57** | 4.5:1 | PASS |
| Label on grape fill (category tile) | `neutral/0` · #FFFFFF | `grape/600` · #7540BF | **6.48** | 4.5:1 | PASS |
| Current price on card | `neutral/900` · #232E4A | `neutral/0` · #FFFFFF | **13.45** | 4.5:1 | PASS |
| Current price on page background | `neutral/900` · #232E4A | `neutral/100` · #FEF8F2 | **12.76** | 4.5:1 | PASS |
| Struck original price on card | `neutral/600` · #616580 | `neutral/0` · #FFFFFF | **5.70** | 4.5:1 | PASS |
| Struck original price on striped row | `neutral/600` · #616580 | `neutral/200` · #F6EEE6 | **4.97** | 4.5:1 | PASS |
| Discount "−25%" on card | `brand/600` · #BD303A | `neutral/0` · #FFFFFF | **5.76** | 4.5:1 | PASS |
| Discount on brand-tinted strip | `brand/600` · #BD303A | `brand/50` · #FEF5F8 | **5.38** | 4.5:1 | PASS |
| Discount on page background | `brand/600` · #BD303A | `neutral/100` · #FEF8F2 | **5.46** | 4.5:1 | PASS |
| Inline link on card | `teal/700` · #1E7188 | `neutral/0` · #FFFFFF | **5.57** | 4.5:1 | PASS |
| Inline link on page background | `teal/700` · #1E7188 | `neutral/100` · #FEF8F2 | **5.28** | 4.5:1 | PASS |
| Inline link on striped row | `teal/700` · #1E7188 | `neutral/200` · #F6EEE6 | **4.85** | 4.5:1 | PASS |
| In-stock text on card | `success/700` · #017D54 | `neutral/0` · #FFFFFF | **5.17** | 4.5:1 | PASS |
| In-stock text in success pill | `success/700` · #017D54 | `success/50` · #EFFBF5 | **4.87** | 4.5:1 | PASS |
| Low-stock text on card | `warning/700` · #994A01 | `neutral/0` · #FFFFFF | **6.30** | 4.5:1 | PASS |
| Low-stock text in warning pill | `warning/700` · #994A01 | `warning/50` · #FEF6EF | **5.89** | 4.5:1 | PASS |
| Out-of-stock text on card | `neutral/600` · #616580 | `neutral/0` · #FFFFFF | **5.70** | 4.5:1 | PASS |
| Out-of-stock text on striped row | `neutral/600` · #616580 | `neutral/200` · #F6EEE6 | **4.97** | 4.5:1 | PASS |
| Success message in alert | `success/700` · #017D54 | `success/50` · #EFFBF5 | **4.87** | 4.5:1 | PASS |
| Warning message in alert | `warning/700` · #994A01 | `warning/50` · #FEF6EF | **5.89** | 4.5:1 | PASS |
| Error message in alert | `danger/700` · #951557 | `danger/50` · #FEF3F7 | **7.68** | 4.5:1 | PASS |
| Info message in alert | `info/700` · #004F9E | `info/50` · #F1F7FF | **7.46** | 4.5:1 | PASS |
| Destructive button label | `neutral/0` · #FFFFFF | `danger/700` · #951557 | **8.32** | 4.5:1 | PASS |
| Confirm button label | `neutral/0` · #FFFFFF | `success/700` · #017D54 | **5.17** | 4.5:1 | PASS |
| Info button label | `neutral/0` · #FFFFFF | `info/600` · #0065C4 | **5.74** | 4.5:1 | PASS |
| Chip label on brand chip | `brand/700` · #961022 | `brand/100` · #FFE1EA | **7.19** | 4.5:1 | PASS |
| Countdown digits on accent-tint strip | `accent/700` · #856716 | `accent/50` · #FEFBEC | **5.12** | 4.5:1 | PASS |
| Secondary brand text (admin) | `grape/700` · #602EA1 | `neutral/0` · #FFFFFF | **8.74** | 4.5:1 | PASS |

### Table B — Light mode, large text ≥24px (requirement 3:1)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| `display-1` / `display-2` in brand on card | `brand/500` · #DC3442 | `neutral/0` · #FFFFFF | **4.55** | 3.0:1 | PASS |
| `display-1` / `display-2` in brand on page bg | `brand/600` · #BD303A | `neutral/100` · #FEF8F2 | **5.46** | 3.0:1 | PASS |
| `price-lg` on accent badge (28px) | `neutral/900` · #232E4A | `accent/500` · #F7CE54 | **8.91** | 3.0:1 | PASS |
| `h1` in teal on card | `teal/600` · #38869E | `neutral/0` · #FFFFFF | **4.14** | 3.0:1 | PASS |
| `h1` in grape on card | `grape/600` · #7540BF | `neutral/0` · #FFFFFF | **6.48** | 3.0:1 | PASS |

### Table C — Light mode, non-text / UI boundary (requirement 3:1, SC 1.4.11)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| Input / control border on card | `neutral/500` · #7C8199 | `neutral/0` · #FFFFFF | **3.85** | 3.0:1 | PASS |
| Input / control border on page bg | `neutral/500` · #7C8199 | `neutral/100` · #FEF8F2 | **3.65** | 3.0:1 | PASS |
| Input / control border on striped row | `neutral/500` · #7C8199 | `neutral/200` · #F6EEE6 | **3.35** | 3.0:1 | PASS |
| Focus ring outer vs card | `teal/600` · #38869E | `neutral/0` · #FFFFFF | **4.14** | 3.0:1 | PASS |
| Focus ring outer vs page bg | `teal/600` · #38869E | `neutral/100` · #FEF8F2 | **3.93** | 3.0:1 | PASS |
| Focus ring outer vs striped row | `teal/600` · #38869E | `neutral/200` · #F6EEE6 | **3.61** | 3.0:1 | PASS |
| Focus ring outer vs disabled fill | `teal/600` · #38869E | `neutral/300` · #E9DFD5 | **3.15** | 3.0:1 | PASS |
| Focus halo (inner 2px) vs brand CTA fill | `neutral/0` · #FFFFFF | `brand/500` · #DC3442 | **4.55** | 3.0:1 | PASS |
| Focus halo vs grape fill | `neutral/0` · #FFFFFF | `grape/600` · #7540BF | **6.48** | 3.0:1 | PASS |
| Focus halo vs teal fill | `neutral/0` · #FFFFFF | `teal/700` · #1E7188 | **5.57** | 3.0:1 | PASS |
| Focus halo vs destructive fill | `neutral/0` · #FFFFFF | `danger/700` · #951557 | **8.32** | 3.0:1 | PASS |
| Focus halo vs ink fill (admin) | `neutral/0` · #FFFFFF | `neutral/900` · #232E4A | **13.45** | 3.0:1 | PASS |
| Selected-state brand border on card | `brand/500` · #DC3442 | `neutral/0` · #FFFFFF | **4.55** | 3.0:1 | PASS |
| Checked checkbox fill vs card | `brand/500` · #DC3442 | `neutral/0` · #FFFFFF | **4.55** | 3.0:1 | PASS |
| Stock dot (in-stock) vs card | `success/600` · #1B9769 | `neutral/0` · #FFFFFF | **3.70** | 3.0:1 | PASS |
| Stock dot (low-stock) vs card | `warning/600` · #B85E16 | `neutral/0` · #FFFFFF | **4.52** | 3.0:1 | PASS |
| Error field border vs card | `danger/500` · #DA3285 | `neutral/0` · #FFFFFF | **4.40** | 3.0:1 | PASS |

### Table D — Dark mode (admin), body text (requirement 4.5:1)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| Body on canvas | `neutral/50` · #FEFBF8 | `neutral/950` · #151B30 | **16.54** | 4.5:1 | PASS |
| Body on panel | `neutral/50` · #FEFBF8 | `neutral/900` · #232E4A | **13.05** | 4.5:1 | PASS |
| Body on popover / raised | `neutral/50` · #FEFBF8 | `neutral/800` · #313A57 | **10.87** | 4.5:1 | PASS |
| Body on striped row | `neutral/50` · #FEFBF8 | `neutral/800` · #313A57 | **10.87** | 4.5:1 | PASS |
| Body on brand-tinted row | `neutral/50` · #FEFBF8 | `brand/900` · #60000F | **13.54** | 4.5:1 | PASS |
| Body on accent-tinted row | `neutral/50` · #FEFBF8 | `accent/900` · #3C2F05 | **12.72** | 4.5:1 | PASS |
| Body on teal-tinted row | `neutral/50` · #FEFBF8 | `teal/900` · #023644 | **12.62** | 4.5:1 | PASS |
| Body on grape-tinted row | `neutral/50` · #FEFBF8 | `grape/900` · #3F0076 | **13.83** | 4.5:1 | PASS |
| Secondary copy on canvas | `neutral/300` · #E9DFD5 | `neutral/950` · #151B30 | **12.98** | 4.5:1 | PASS |
| Secondary copy on panel | `neutral/300` · #E9DFD5 | `neutral/900` · #232E4A | **10.24** | 4.5:1 | PASS |
| Secondary copy on raised | `neutral/300` · #E9DFD5 | `neutral/800` · #313A57 | **8.53** | 4.5:1 | PASS |
| Metadata on canvas | `neutral/400` · #BABECB | `neutral/950` · #151B30 | **9.19** | 4.5:1 | PASS |
| Metadata on panel | `neutral/400` · #BABECB | `neutral/900` · #232E4A | **7.25** | 4.5:1 | PASS |
| Metadata on raised | `neutral/400` · #BABECB | `neutral/800` · #313A57 | **6.04** | 4.5:1 | PASS |
| Button label on primary CTA | `neutral/0` · #FFFFFF | `brand/500` · #DC3442 | **4.55** | 4.5:1 | PASS |
| Badge label on sale badge | `neutral/900` · #232E4A | `accent/500` · #F7CE54 | **8.91** | 4.5:1 | PASS |
| Label on teal fill | `neutral/0` · #FFFFFF | `teal/700` · #1E7188 | **5.57** | 4.5:1 | PASS |
| Label on grape fill | `neutral/0` · #FFFFFF | `grape/600` · #7540BF | **6.48** | 4.5:1 | PASS |
| Current price on panel | `neutral/50` · #FEFBF8 | `neutral/900` · #232E4A | **13.05** | 4.5:1 | PASS |
| Struck price on panel | `neutral/400` · #BABECB | `neutral/900` · #232E4A | **7.25** | 4.5:1 | PASS |
| Discount on panel | `brand/300` · #FEBDC6 | `neutral/900` · #232E4A | **8.52** | 4.5:1 | PASS |
| Discount on brand-tinted row | `brand/300` · #FEBDC6 | `brand/900` · #60000F | **8.85** | 4.5:1 | PASS |
| Inline link on panel | `teal/300` · #9BDDEF | `neutral/900` · #232E4A | **8.96** | 4.5:1 | PASS |
| Inline link on canvas | `teal/300` · #9BDDEF | `neutral/950` · #151B30 | **11.36** | 4.5:1 | PASS |
| In-stock text on panel | `success/300` · #9DE3C1 | `neutral/900` · #232E4A | **9.09** | 4.5:1 | PASS |
| In-stock text in success pill | `success/300` · #9DE3C1 | `success/900` · #023A25 | **8.68** | 4.5:1 | PASS |
| Low-stock text on panel | `warning/300` · #FEC392 | `neutral/900` · #232E4A | **8.61** | 4.5:1 | PASS |
| Low-stock text in warning pill | `warning/300` · #FEC392 | `warning/900` · #4E2301 | **8.59** | 4.5:1 | PASS |
| Out-of-stock text on panel | `neutral/400` · #BABECB | `neutral/900` · #232E4A | **7.25** | 4.5:1 | PASS |
| Success message in alert | `success/300` · #9DE3C1 | `success/900` · #023A25 | **8.68** | 4.5:1 | PASS |
| Warning message in alert | `warning/300` · #FEC392 | `warning/900` · #4E2301 | **8.59** | 4.5:1 | PASS |
| Error message in alert | `danger/300` · #FA9EC0 | `danger/900` · #5A0F34 | **6.90** | 4.5:1 | PASS |
| Info message in alert | `info/300` · #94C0F7 | `info/900` · #013063 | **6.95** | 4.5:1 | PASS |
| Destructive button label | `neutral/0` · #FFFFFF | `danger/700` · #951557 | **8.32** | 4.5:1 | PASS |
| Confirm button label | `neutral/0` · #FFFFFF | `success/700` · #017D54 | **5.17** | 4.5:1 | PASS |

### Table E — Dark mode, non-text (requirement 3:1)

| Pair | Foreground | Background | Ratio | Req | Result |
|---|---|---|---|---|---|
| Input border on panel | `neutral/500` · #7C8199 | `neutral/900` · #232E4A | **3.50** | 3.0:1 | PASS |
| Input border on canvas | `neutral/500` · #7C8199 | `neutral/950` · #151B30 | **4.43** | 3.0:1 | PASS |
| Input border on raised / striped | `neutral/400` · #BABECB | `neutral/800` · #313A57 | **6.04** | 3.0:1 | PASS |
| Focus ring outer vs canvas | `teal/400` · #6EC8DF | `neutral/950` · #151B30 | **8.93** | 3.0:1 | PASS |
| Focus ring outer vs panel | `teal/400` · #6EC8DF | `neutral/900` · #232E4A | **7.04** | 3.0:1 | PASS |
| Focus ring outer vs raised | `teal/400` · #6EC8DF | `neutral/800` · #313A57 | **5.87** | 3.0:1 | PASS |
| Focus halo (canvas colour, dark) vs brand CTA fill | `neutral/950` · #151B30 | `brand/500` · #DC3442 | **3.75** | 3.0:1 | PASS |
| Focus halo vs focus ring | `neutral/950` · #151B30 | `teal/400` · #6EC8DF | **8.93** | 3.0:1 | PASS |
| Selected brand border on panel | `brand/400` · #FB6265 | `neutral/900` · #232E4A | **4.51** | 3.0:1 | PASS |
| Display text in brand on panel (≥24px) | `brand/400` · #FB6265 | `neutral/900` · #232E4A | **4.51** | 3.0:1 | PASS |

### 4.1 Surface-only colours — must NEVER carry text

Measured against white, these are all below 4.5:1 and most are below 3:1. Flagged explicitly so the CI gate and the lead both know.

| Colour | vs `#FFFFFF` | Verdict |
|---|---|---|
| `accent/500` `#F7CE54` | **2.12** | **Surface only.** The single most dangerous value in the palette. Text on accent is always `neutral/900`. |
| `accent/400` `#F8D568` | 1.81 | Surface only |
| `accent/300` `#F8DD77` | 1.52 | Surface only |
| `accent/600` `#BF9224` | 3.07 | Surface + ≥24px text only. First text-safe accent step is **`accent/700`** (4.75). |
| `brand/400` `#FB6265` | 2.97 | Surface only (light mode) |
| `brand/300` `#FEBDC6` | 1.95 | Surface only in light; it is the dark-mode discount text colour |
| `teal/400` `#6EC8DF` | 2.30 | Surface / stroke only in light |
| `teal/300` `#9BDDEF` | 1.67 | Surface only in light; dark-mode link colour |
| `grape/400` `#B692E0` | 2.88 | Surface only |
| `grape/300` `#CAAFE4` | 1.85 | Surface only |
| `success/500` `#49C08D` | 3.17 | Non-text indicator / dot only |
| `warning/500` `#DF7D39` | 3.11 | Non-text indicator / dot only |
| `danger/500` `#DA3285` | 4.37 | Non-text / border only — **just below 4.5, do not use for text** |
| `info/500` `#3387EB` | 3.62 | Non-text only |
| `neutral/200`–`neutral/400` | 1.25 – 2.25 | Surface / divider only |
| `neutral/500` `#7C8199` | 3.86 | Borders and disabled labels only |
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
| `shadow/e1` | `inset 0 0 0 1px #313A57` (`neutral/800`) |
| `shadow/e2` | `inset 0 0 0 1px #313A57, 0 2px 6px -1px rgba(0,0,0,0.60)` |
| `shadow/e3` | `inset 0 0 0 1px #474F6A, 0 12px 24px -6px rgba(0,0,0,0.70)` (`neutral/700`) |
| `shadow/e4` | `inset 0 0 0 1px #474F6A, 0 28px 56px -12px rgba(0,0,0,0.80)` |
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

**Form.** A 1:1 rounded-square tile, corner radius 24% of the side (optically `radius/xl` at logo scale), filled flat `brand/500` `#DC3442`. Inside it, on a 6×6u grid with 1u of clear space on all sides (so the drawing area is 4×4u), a single continuous white knockout shape built from exactly three modules:

1. A horizontal bar, 4u × 1u, flush to the top of the drawing area — the crossbar of a **T**.
2. A vertical stem, 1u × 3u, dropping from the bar's horizontal centre.
3. A quarter-circle arc of outer radius 2u and stroke weight 1u, joined to the foot of the stem and sweeping right and up, so the stem's base curves out like a rocker.

All knockout corners are radius 0.25u except the arc, which is a true quarter-circle. The result is a **T** that is simultaneously a rocking-horse runner and the opening stroke of the **M** that follows in the wordmark — the mark and the word interlock rather than sit beside each other.

**Colour usage.** Default: one flat `brand/500` tile, one white knockout. Two-colour variant, permitted only at ≥48px: the arc alone in `accent/500` `#F7CE54`. Below 48px the arc reverts to white. Inverse variant for dark backgrounds: tile in `neutral/0`, knockout in `brand/500`. There is no gradient, no outline and no shadow version.

**Wordmark.** `toymart` in Hanken Grotesk 800, all lowercase, tracking −0.02em, in `neutral/900` `#232E4A`; followed by `bd` in Hanken Grotesk 600 at 70% of the cap height, baseline-aligned, in `neutral/600` `#616580`. Horizontal lockup: tile at left, gap = 0.5 × tile height, wordmark cap-height = 0.52 × tile height. Stacked lockup for square placements: tile above, wordmark centred below at gap = 0.3 × tile height.

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

---

## 14. Palette re-anchor — pastel toy-shop direction (2026-11-11)

**Owner request:** follow the graphic style of a reference Bangladeshi toy shop (soft pastels, coral-pink actions, navy text, bright lifestyle product photography) with colour tokens *similar, not exact*. The reference was studied for direction only; no value was copied — every ramp below was regenerated in OKLCh and solved for contrast.

| Ramp | Before | After | Contrast anchor |
|---|---|---|---|
| `brand` | vermilion, h 30, `500` #DD2D1D | coral rose, h 20, `500` #D93647 | white on `500` 4.60:1; `600` discount text 5.79:1 on white |
| `accent` | marigold, h 78, `500` #EBA500 | sunflower, h 84, `500` #FDBD0C | ink on `500` 9.13:1; `700` #916B04 4.68:1 on `50` |
| `teal` | peacock, h 185, `600` #008076 | sky, h 218, `600` #04849E | focus ring 3.10:1 on `neutral/300`; `700` link 4.67:1 on `neutral/200` |
| `grape` | orchid, h 305, `500` #9A4CDF | lavender, h 303, `500` #8C60C2 | white on `500` 4.60:1 |
| `neutral` | warm paper/ink, h 50 | cream paper (h 40–60) / navy ink (h 265) | `900` #19243F on canvas 14.42:1 |
| `danger` | crimson, h 12 | raspberry, h 356 | `700` 8.32:1 on white; **24.6° from brand** |

**Why `danger` moved.** Coral rose landed at h 20.5 — within 9° of the old danger hue 12, which recreates exactly the "buy and error are the same red" collision §2.4 fixed. Rotating danger to raspberry restores the gap, and `color/bg/danger` moved to `danger/700` so a destructive button is also clearly darker than a primary one.

**Tokens added:** the five `color/bg/tile-*` pastel surfaces (§3.2). **Tokens repointed:** `color/bg/teal` → `teal/700`, `color/bg/danger` → `danger/700`, `color/bg/danger-shelf` → `danger/900`.

**Not changed:** typography (Hanken Grotesk / Anek Bangla), radius, spacing, the flat no-gradient rule, the 12% saturated-area budget, the decision-surface ban. **Open:** the elevation effect styles still carry the old warm shadow tint (§6.3) and should be re-derived from navy `neutral/900`.

All 111 contrast rows in §4 were recomputed against the new values and pass.

---

## 15. Kid-friendly style pass (2026-11-11)

**Owner request:** "all design should be fancy and kids like", including kid-like typography. This section overrides §5.1 (families), §6.2 (radius values, button radius) and §6.3 (light elevation colour) where they conflict. Contrast rules in §4 are unchanged and still pass.

| Area | Before | After | Why |
|---|---|---|---|
| Display type | Hanken Grotesk / Anek Bangla everywhere | **Fredoka** (Latin) and **Baloo Da 2** (Bangla) for display, h1–h4, buttons, prices, badges, chips, tab/accordion/modal/alert/empty-state titles, stepper and pagination numbers, avatar initials — 83 text styles, bound to the new `font/family/display-latin` / `font/family/display-bangla` variables | Rounded terminals read as toy-like and friendly; Baloo Da 2 is the rounded Bengali companion, so both scripts share one voice |
| Text type | — | unchanged: Hanken Grotesk / Anek Bangla for body, captions, labels, inputs | Paragraph legibility; rounded faces tire the eye at 14px over several lines |
| Card title | `body-sm` (Hanken) | new `Latin/Mobile/card-title` = Fredoka Medium 14/150 (`Bangla/…` = Baloo Da 2 Medium 14/172) | Product names are what a child points at |
| Radius | xs 4 · sm 8 · md 12 · lg 16 · xl 24 · 2xl 32 | **xs 6 · sm 10 · md 16 · lg 20 · xl 28 · 2xl 40** | Softer everywhere; 1,426 raw corner values on the Primitives page were bound to these tokens so they follow |
| Buttons | `radius/lg` | **`radius/full` pills** on the existing candy shelf | The single strongest playful signal; padding 16/20/24 keeps Bangla clear of the curve. Reverses the §1 "no pill buttons" refusal at the owner's request |
| Light elevation | warm-grey shadow from old `neutral/900` | **rose-tinted** (`#B82C48` at 6–28%), softer and wider; dark mode unchanged | A candy glow rather than a grey smudge |
| Focus ring style | old teal | new `teal/600` `#04849E` | Follows §14 |
| Gradients | refused | **four decorative paint styles** `Gradient/Bloom-candy / -sky / -mint / -sunrise` (two 100-step primitives each) | Hero, promo and section backgrounds ONLY — never a control, price, form, cart or checkout. Flat fills remain the rule everywhere else |
| Product card | photo edge-to-edge | photo inset 6px in a pastel "toy box" (`color/bg/tile-*`), `radius/xl` card, discount badge tilted −8° as a sticker | Playful framing without touching the fixed card height (384 / 552) |
| Doc pages | plain headers | gradient hero headers with pastel bubbles and stickers; section numbers as candy pills | The design system itself should feel like the product |

**Unchanged on purpose:** the 12% saturated-area budget, the decision-surface ban (cart, checkout, address, admin forms stay calm), every contrast floor, fixed card heights, Western numerals.

---

## 16. Exact colour match to the reference shop + candy buttons (2026-11-11) — SUPERSEDED by §17

**Owner request:** "update the colour tokens to match the reference site 100%, buttons should be fancy". The reference shop's live CSS was read (computed styles, not screenshots) and its colours pinned into the ramps at the steps where they sit; only the missing steps were generated in OKLCh. This supersedes the §14 values.

| Reference colour | Hex | Pinned at |
|---|---|---|
| Coral (CTA, prices, accents) | `#FF5B6E` | `brand/500` |
| Hot pink (gradient partner) | `#FF6FB1` | `pink/400` (new `pink` family) |
| Magenta (icons) | `#E5539B` | `pink/500` |
| Pastel pink tile / soft border | `#FFE0EC` / `#FFD4E6` | `brand/100` / `brand/200` |
| Sunshine yellow / butter tile | `#FFCB47` / `#FFEDB6` | `accent/500` / `accent/100` |
| Sky / sky deep / sky tile | `#6BC8E6` / `#3FA1C5` / `#D4EEF7` | `teal/400` / `teal/500` / `teal/100` |
| Lavender / violet / lavender tile | `#B093E8` / `#8B5CF6` / `#E5D9F8` | `grape/400` / `grape/500` / `grape/100` |
| Mint / green text / mint tile | `#4FC081` / `#15803D` / `#D7F5E2` | `success/500` / `success/700` / `success/100` |
| Orange / peach tile | `#E5783A` / `#FFE0CB` | `warning/500` / `warning/100` |
| Navy ink / muted grey / page cream | `#1F2F4A` / `#7A8299` / `#FFF8F2` | `neutral/900` / `neutral/500` / `neutral/100` |

**New tokens:** `pink/50–900`; `color/bg/pink` (→ `pink/400`), `color/bg/tile-peach` (→ `warning/100`), `color/text/brand` (→ `brand/600`, coral text on white, 4.67:1), `color/border/brand-subtle` (→ `brand/200`, the soft pink outline). **Remapped:** `color/bg/grape` → `grape/600` (white on `grape/500` is 4.23:1), `color/text/discount` → `brand/700`, dark `color/border/strong` → `neutral/400`.

**Candy buttons.** Primary = pill + paint style `Gradient/Button-primary` (135°, `#FF5B6E` → `#FF6FB1`, the reference gradient) + effect style `Glow/Brand` (coral glow `0 10 22 −10` at 55% plus a 1.5px white top gloss); hover `Glow/Brand-hover`. The §11 shelf is removed — the glow replaces it. Secondary = flat `brand/100`; Outline = white + 2px `color/border/brand-subtle` + `color/text/brand`; Ghost = text; Danger = raspberry `danger/700`. IconButton Filled/Outline follow the same treatment. Also added: `Gradient/Button-violet` + `Glow/Violet` for special CTAs, `Glow/Tile-*` coloured glows for pastel category cards, pink-tinted `Elevation/Light/e1–e4`, and the four `Gradient/Bloom-*` styles re-pinned to the reference pastels.

> **Known accessibility failure — owner decision required.** White label on `#FF5B6E` measures **3.01:1** (and 2.57:1 on the `#FF6FB1` end). WCAG 2.2 AA requires 4.5:1 for button text of this size, and `CLAUDE.md` rule 29 makes that a CI gate. It is shipped here because the owner asked for an exact match. The one-step fix, if wanted: start the gradient at `brand/600` `#CA4555` → `pink/600` `#C04882` (white 4.67:1 / 4.86:1), which keeps the same hue and glow and reads almost identically at a glance. Every other text pairing in §4 passes.

---

## 17. Similar-not-exact palette, zero contrast failures (2026-11-11)

**Owner request:** "don't use the exact colour tokens of the reference site — use similar", and "make sure there is no colour contrast issue". This supersedes the values in §16; the candy button treatment, new tokens and styles from §16 remain.

**Method.** Each reference colour was moved in OKLCh by about +4–6° hue and −6% chroma (navy and cream by +6° hue only), so every swatch reads as the same family but none is the reference value. Steps that carry text or white labels were then re-solved numerically for contrast. Ramps were checked for monotonic lightness.

| Role | Reference | Ours | Contrast anchor |
|---|---|---|---|
| CTA gradient start `brand/500` | #FF5B6E | **#DC3442** | white 4.66:1 |
| CTA gradient end `pink/500` | #FF6FB1 | **#CA3F8D** | white 4.69:1 |
| Bright coral (decorative) `brand/400` | #FF5B6E | #FB6265 | not used behind text |
| Discount / coral text `brand/700` / `brand/600` | — | #961022 / #C1343D | 8.0:1 / 5.2:1 on canvas |
| Sunshine `accent/500` | #FFCB47 | #F7CE54 | navy on it 8.9:1 |
| Sky `teal/400` / `teal/500` | #6BC8E6 / #3FA1C5 | #6EC8DF / #42A2BF | focus `teal/600` #38869E 3.15:1 on `neutral/300`; link `teal/700` #1E7188 4.85:1 |
| Violet `grape/500` | #8B5CF6 | #8B51DE | white 4.85:1 |
| Mint `success/500` / text `success/700` | #4FC081 / #15803D | #49C08D / #017D54 | 5.17:1 |
| Orange `warning/500` | #E5783A | #DF7D39 | text `warning/700` 6.3:1 |
| Navy ink / cream page | #1F2F4A / #FFF8F2 | #232E4A / #FEF8F2 | 12.8:1 |
| Pastel tiles | #FFE0EC #FFEDB6 #D7F5E2 #D4EEF7 #E5D9F8 #FFE0CB | #FFE1EA #FBEEBA #D6F5E5 #D5EEF5 #E7D9F5 #FCE1CB | navy text on each ≥12:1 |

**Contrast fixes made in the same pass.** `Gradient/Button-primary` = `brand/500` → `pink/500` (both ends pass with white). `color/text/disabled` → `neutral/600` light / `neutral/400` dark — disabled labels now pass 4.5:1 even though WCAG exempts them; disabled controls still read as disabled through the flat `bg/disabled` fill, no glow and muted icons. `color/text/discount` → `brand/700` (the −25% chip was 4.497:1). `color/bg/grape` back to `grape/500` (now 4.85:1).

**Audit.** A script walked every visible text layer in every component on the Primitives and Storefront Components pages (1,164 checks), found the actual painted background (parent fills, absolutely positioned faces, semi-transparent fills blended down, both ends of gradients) and tested Light and Dark against 4.5:1 / 3:1-large. Result: **0 real failures**. The only flags were Primary buttons nested inside Modal, Drawer, EmptyState and ProductCard, where the checker cannot read an instance's gradient style; those were verified by rendering (white on the coral→berry gradient, 4.66–4.69:1). All §4 rows pass. In dark mode the focus halo uses the canvas colour (`neutral/950`) rather than the panel colour, because navy-on-coral measured 2.88:1.

**Buttons, icons.** Button default icon is now the filled `icon/shopping-bag-open-fill` (outline cart read thin on the gradient); "Notify me" uses `icon/bell-fill`. Icon colour per variant: white on Primary/Danger, `color/text/brand` on Secondary/Outline, `color/text/primary` on Ghost.

**Update (2026-11-11, later):** button drop shadows removed at the owner's request — Primary, Danger and IconButton Filled now use `Gloss/Button` (a 1.5px white top gloss only; hover feedback is the `_scrim`). The gradient was lightened as far as AA allows and made more vivid: `brand/500` → **#DC3442**, `pink/500` → **#CA3F8D** (white label 4.55:1 at both ends). Any lighter and the white "Add to cart" label would fail 4.5:1.

**Update — price colour (2026-11-11, later):** the dark wine `brand/700` read muddy as a price colour, so `color/text/discount` (prices on Version B cards and every discount figure) moved back to `brand/600`, re-tuned to **#BD303A** — a bright coral that passes on every price background: white 5.76:1, cream canvas 5.46:1, pink discount chip 4.72:1, `brand/50` 5.38:1.

