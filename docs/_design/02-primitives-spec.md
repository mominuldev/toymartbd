# Toy Mart BD — Tier 1 Primitives: Figma Build Specification

**Owner:** Design Agent 2 · **Date:** 2026-11-08 · **Status:** Ready to build
**Consumed by:** Design Lead (executes in Figma, sequentially)
**Source of truth for tokens:** `docs/UI-UX-DESIGN-SPEC.md` §3
**Target Figma page:** `Toy Mart BD — Design System › 02 Primitives`

This document is written to be executed mechanically. Every number is final. Where a value is not a token, it is stated as a raw px value and that is intentional (border widths, icon sizes and fixed component heights are not tokenised in this system).

---

## 0. Global conventions

These apply to every component below and are not repeated in each section.

### 0.1 Naming

- Component sets are named exactly as the heading: `Button`, `IconButton`, `Input`, …
- Variant properties use `Property=Value` with capitalised values: `Variant=Primary`, `Size=Medium`.
- Variant property names match the React prop names one-for-one (`variant`, `size`, `state`) — Figma displays them capitalised, code uses camelCase. Code Connect maps them.
- Private helper layers are prefixed with `_` (`_scrim`, `_focus`, `_spinner`, `_aria-label`). Prefixed layers are never exposed as instance properties.

### 0.2 The three shared helper layers

Built once, copied into every interactive primitive. The lead builds these **first**, as local components on the Primitives page, before Batch 1.

**`_focus` — focus-visible ring**
A rectangle, absolutely positioned (`Ignore auto-layout` on), constraints `Left+Right / Top+Bottom`, inset **−4px on all four sides** (so the ring sits 4px outside the component bounds), fill `None`, stroke **2px** `color/border/focus`, stroke align `Center`, corner radius = the host component's radius + 4 (use `radius/full` when the host is `radius/full`). Visible = `false` by default. The host frame must have `Clip content = false`.
Exposed on every interactive primitive as the boolean property **`focusVisible`** (default `false`). This exists so the lead can place a focused state in a mockup and so the a11y reviewer can see the ring without prototyping. In code it is `:focus-visible`, never `:focus`.

**`_scrim` — hover / pressed darkening**
A rectangle filling the component (`Ignore auto-layout` on, inset 0), fill `color/text/primary`, corner radius matching the host, layer opacity **0%**. Hover sets it to **8%**, pressed to **12%**. One mechanism for all variants, light and dark, which is why no `*-hover` colour tokens exist in this system and none should be invented.

**`_aria-label` — screen-reader name**
A text layer, 1×1px, fill `None`, opacity 0%, `Clip content` irrelevant. Present on every icon-only control. Exposed as TEXT property `ariaLabel`. In code this is the `aria-label` attribute, not a hidden span.

### 0.3 State model

Only **Default** (and, where listed, discrete content states such as `Filled`, `Error`, `Open`) are Figma variant axes. **Hover, pressed, focus-visible and disabled are interaction-only** unless a component's section says otherwise. They are delivered as:

| State | Mechanism in Figma | Mechanism in code |
|---|---|---|
| Hover | `_scrim` → 8% (or the fill swap stated per component) | `:hover` on pointer devices only (`@media (hover: hover)`) |
| Pressed | `_scrim` → 12% + `scale(0.98)` | `:active` |
| Focus-visible | `focusVisible = true` | `:focus-visible` |
| Disabled | instance-level token override per the component's Disabled row | `:disabled` / `[aria-disabled]` |
| Loading | boolean property `loading` | `aria-busy="true"` |

This decision is what keeps every variant matrix in this document under 30 combinations. See §1.1 for the Button rationale, which is the binding precedent.

### 0.4 Disabled appearance — one rule, all components

Every disabled control, regardless of variant, renders:

- fill `color/bg/subtle`
- text `color/text/tertiary`
- border (if the variant has one) 1px `color/border/default`
- icon `color/text/tertiary`
- no `_scrim`, no shadow, no focus ring (disabled controls are removed from the tab order; `aria-disabled` controls stay focusable and keep the ring — see QuantityStepper §10)

This is deliberate: a disabled Danger button must not look dangerous, and a disabled Primary button must not look clickable. Disabled controls are exempt from WCAG 2.2 contrast minimums; the adjacent explanatory text is not, and is always `color/text/secondary` or stronger.

### 0.5 Motion

`fast = 120ms`, `base = 200ms`, `slow = 320ms`. Easing `standard cubic-bezier(.2,0,0,1)`, `decelerate cubic-bezier(0,0,.2,1)`. `spring cubic-bezier(.34,1.56,.64,1)` is **forbidden in every component in this document** — it is reserved for the add-to-cart confirmation in Tier 2. Every animation is wrapped in `prefers-reduced-motion: no-preference`; the reduced-motion fallback is stated per component where it is not simply "no transition".

### 0.6 Spacing tokens — the available set

`spacing/1=4 · 2=8 · 3=12 · 4=16 · 5=20 · 6=24 · 8=32 · 10=40 · 12=48 · 16=64 · 20=80 · 24=96`.
There is **no** `spacing/7`, `/9`, `/11`, `/13`–`/15`, `/17`–`/19`, `/21`–`/23`. No padding below is a non-token value. Where a component needs a height that is not expressible as padding + line-box (36 / 44 / 52), the frame height is set **FIXED** and vertical padding is `0` with `Align = Center`. This is stated per component.

### 0.7 Bangla — the 40% rule

Every component carrying a text label states its behaviour for a string 40% longer than its English equivalent. The three permitted strategies:

- **GROW** — frame hugs; the parent absorbs the width. Used where the component sits in a flexible row.
- **WRAP** — text wraps to a stated maximum line count; frame height changes from FIXED to HUG with a stated minimum.
- **SCROLL** — the container scrolls horizontally; nothing wraps or truncates. Used only for Tabs and Breadcrumb, where wrapping reads as breakage.

**Truncation is forbidden on every component in this document.** Product titles clamp at two lines (Tier 2, `ProductCard`); no primitive truncates a label, because a truncated Bangla label frequently loses the verb.

Typography: Latin layers use the `en/*` text styles (Plus Jakarta Sans). The Bangla build uses the parallel `bn/*` text styles (Anek Bangla) at the same size with line-height × 1.15. The lead creates both style sets in Foundations; every text layer in every primitive below binds to an `en/*` style, and the `.lang-bn` class swaps the family and line-height at runtime. Never one global line-height.

Height headroom check (worst case, `bn/body` at 15px × 1.6 × 1.15 = 27.6px line-box):

| Frame height | Line-box (en) | Line-box (bn) | Headroom (bn) | Verdict |
|---|---|---|---|---|
| 36 | 21.0 | 24.2 | 11.8 | passes |
| 44 | 24.0 | 27.6 | 16.4 | passes |
| 52 | 21.6 | 24.8 | 27.2 | passes |

No fixed-height control in this document fails on Bangla. Only multi-line components (Textarea, Alert, EmptyState, Accordion headers) change height, and each states how.

### 0.8 Touch targets

Minimum 44×44px CSS pixels for every interactive element. Where the visual box is smaller than 44, the control carries a pseudo-element hit area:

```css
.hit44::before {
  content: ""; position: absolute; inset: 50% auto auto 50%;
  width: max(100%, 44px); height: max(100%, 44px);
  transform: translate(-50%, -50%);
}
```

In Figma this is represented as a rectangle named `_hit44`, 44×44, fill `None`, `Ignore auto-layout` on, centred. It is **not** exposed as a property. Components using it: Button `Size=Small`, IconButton `Size=Small`, Chip remove control, Badge (n/a — non-interactive), Breadcrumb `…` control, Toast dismiss.

Adjacent targets are separated by at least `spacing/2` (8px) so that a 44px hit area never overlaps its neighbour's.

### 0.9 Component property defaults

Every BOOLEAN property defaults to `false` except where stated. Every TEXT property ships with English placeholder content that is a realistic length, and the lead adds a second instance on the documentation board with the Bangla string so reviewers can see both. INSTANCE_SWAP properties default to the Lucide `circle` glyph at the component's icon size, never to a blank frame (a blank default hides layout bugs).

---

## 1. `Button`

### 1.1 Variant matrix decision — the binding precedent

| Axis | Values | Count |
|---|---|---|
| `Variant` | `Primary`, `Secondary`, `Outline`, `Ghost`, `Danger` | 5 |
| `Size` | `Small`, `Medium`, `Large` | 3 |
| **Total variant combinations** | | **15** |

**`State` is not a variant axis.** Adding `State=Default|Disabled` alone would produce 30 combinations, which is at the stated ceiling rather than under it; adding hover/pressed/focus would produce 75 and make the set unmaintainable — every future variant or size would multiply by five.

Explicitly:

- **Variant axes:** `Variant`, `Size`. Nothing else.
- **Interaction-only (not variants):** hover, pressed, focus-visible, disabled.
- **Delivered as component properties (not variants):** `loading`, `iconLeft`, `iconRight`, `focusVisible`.
- **Disabled in mockups:** the lead applies the §0.4 token overrides to the instance's `_bg`, `Label` and `_border` layers. Figma allows fill and stroke overrides on instance children, so this needs no variant. The lead also builds one documentation row on the board — `Button / States` — showing `Variant=Primary, Size=Medium` six times (default, hover, pressed, focus-visible, disabled, loading) as static instances with the overrides applied, labelled. That row is documentation, not a component.

This precedent applies to every component below: discrete *content* states (a filled input, an error input, an open select) are variant axes because a designer must place them in a mockup and they are not reachable by hover; *interaction* states are not.

### 1.2 Component properties

| Property | Type | Default | Bound to | Notes |
|---|---|---|---|---|
| `label` | TEXT | `Add to cart` | `Label` text layer | Bangla documentation instance: `কার্টে যোগ করুন` |
| `iconLeft` | BOOLEAN | `false` | `IconLeft` frame visibility | |
| `iconLeftSwap` | INSTANCE_SWAP | `icon/shopping-cart` | `IconLeft › icon` | Preferred Lucide set exposed |
| `iconRight` | BOOLEAN | `false` | `IconRight` frame visibility | |
| `iconRightSwap` | INSTANCE_SWAP | `icon/arrow-right` | `IconRight › icon` | |
| `loading` | BOOLEAN | `false` | `_spinner` visibility (true) + `IconLeft`/`IconRight` visibility (false) | See §1.6 |
| `focusVisible` | BOOLEAN | `false` | `_focus` visibility | §0.2 |

`fullWidth` is **not** a property. It is achieved by setting the instance's horizontal resizing to `Fill container`. Exposing it as a boolean would duplicate a native Figma capability and drift from it.

### 1.3 Geometry

| Size | Height (FIXED) | Min-width | Padding X | Padding Y | Gap | Icon | Label text style | Radius |
|---|---|---|---|---|---|---|---|---|
| `Small` | 36 | 80 | `spacing/4` (16) | 0 | `spacing/2` (8) | 16 | `en/body-sm` 14 / 1.5 / **600** | `radius/full` |
| `Medium` | 44 | 96 | `spacing/5` (20) | 0 | `spacing/2` (8) | 20 | `en/body` 15 / 1.6 / **600** | `radius/full` |
| `Large` | 52 | 112 | `spacing/6` (24) | 0 | `spacing/3` (12) | 24 | `en/h4` 16 / 1.35 / **600** | `radius/full` |

Border width: `Outline` = **1.5px**, every other variant = 0. Stroke align `Inside` so the 1.5px does not change the outer dimensions.

**Admin radius.** The component binds `radius/full` (the storefront's single strongest playful signal, at zero usability cost). Admin instances override corner radius to `radius/md` (10) at the instance level — a documented, permitted override. A second variant axis for shape would push the matrix to 30. The lead creates one documentation instance showing the admin override, named `Button / Admin override`.

### 1.4 Token bindings per variant and state

`_bg` is the component frame's own fill. `_scrim` per §0.2.

| Variant | State | Fill | Text / Icon | Border |
|---|---|---|---|---|
| **Primary** | Default | `color/bg/brand` | `color/text/on-brand` | — |
| | Hover | `color/bg/brand` + `_scrim` 8% | `color/text/on-brand` | — |
| | Pressed | `color/bg/brand` + `_scrim` 12% | `color/text/on-brand` | — |
| | Focus-visible | `color/bg/brand` | `color/text/on-brand` | `_focus` 2px `color/border/focus` |
| | Disabled | `color/bg/subtle` | `color/text/tertiary` | — |
| | Loading | `color/bg/brand` | `color/text/on-brand` | — |
| **Secondary** | Default | `color/bg/brand-subtle` | `color/text/primary` | — |
| | Hover | `color/bg/brand-subtle` + `_scrim` 8% | `color/text/primary` | — |
| | Pressed | `color/bg/brand-subtle` + `_scrim` 12% | `color/text/primary` | — |
| | Focus-visible | `color/bg/brand-subtle` | `color/text/primary` | `_focus` |
| | Disabled | `color/bg/subtle` | `color/text/tertiary` | — |
| **Outline** | Default | `None` | `color/text/primary` | 1.5px `color/border/strong` |
| | Hover | `color/bg/subtle` | `color/text/primary` | 1.5px `color/border/strong` |
| | Pressed | `color/bg/subtle` + `_scrim` 8% | `color/text/primary` | 1.5px `color/border/strong` |
| | Focus-visible | `None` | `color/text/primary` | 1.5px `color/border/strong` + `_focus` |
| | Disabled | `color/bg/subtle` | `color/text/tertiary` | 1.5px `color/border/default` |
| **Ghost** | Default | `None` | `color/text/primary` | — |
| | Hover | `color/bg/subtle` | `color/text/primary` | — |
| | Pressed | `color/bg/subtle` + `_scrim` 8% | `color/text/primary` | — |
| | Focus-visible | `None` | `color/text/primary` | `_focus` |
| | Disabled | `None` | `color/text/tertiary` | — |
| **Danger** | Default | `color/feedback/danger` | `color/text/on-brand` | — |
| | Hover | `color/feedback/danger` + `_scrim` 8% | `color/text/on-brand` | — |
| | Pressed | `color/feedback/danger` + `_scrim` 12% | `color/text/on-brand` | — |
| | Focus-visible | `color/feedback/danger` | `color/text/on-brand` | `_focus` |
| | Disabled | `color/bg/subtle` | `color/text/tertiary` | — |

Ghost `Disabled` keeps fill `None` rather than `color/bg/subtle` — a ghost button has no box, so filling it on disable makes it appear *more* prominent than enabled. This is the single exception to §0.4 and it is intentional.

`color/bg/accent` is **never** a Button fill. There is no accent button variant and none is to be added: accent.500 cannot carry white text and `color/text/on-accent` (neutral.900) on a saturated yellow pill reads as a warning, not an action.

### 1.5 Auto-layout

| Property | Value |
|---|---|
| Direction | Horizontal |
| Primary axis sizing | `HUG` (instance may be set to `FILL` for full-width) |
| Counter axis sizing | `FIXED` at the Size row's height |
| Primary axis alignment | `Center` |
| Counter axis alignment | `Center` |
| Padding | X per Size row, Y = 0 |
| Gap | per Size row |
| `Clip content` | `false` (so `_focus` can sit outside) |
| Layer order | `_scrim` (absolute) → `IconLeft` → `_spinner` → `Label` → `IconRight` → `_focus` (absolute) |

`Label` text layer: horizontal `HUG`, vertical `HUG`, `Align = Center`, `Truncate = off`, `Auto-width`.
`IconLeft` / `IconRight`: `FIXED` square at the Size row's icon size, `Clip content = false`.

### 1.6 Loading

`loading = true`:

- `_spinner` (an instance of `Spinner`, §14) becomes visible at the Size row's icon size, `Tone=OnBrand` for Primary/Danger, `Tone=Neutral` for Secondary/Outline/Ghost.
- `IconLeft` and `IconRight` are forced hidden.
- `Label` **stays visible at full opacity**. A loading button that loses its label is unreadable at 4G latency, which is exactly when it is shown.
- Pointer events off, `aria-busy="true"`, `aria-disabled="true"`. The visual fill does **not** change to the disabled fill — the action is still pending, not unavailable.

**Reflow guard.** Hidden layers consume no width in Figma auto-layout, so a `loading` toggle on a HUG button widens it by `icon + gap`. Two rules prevent this reaching the user:

1. Every button that can enter a loading state on the storefront — `Add to cart`, `Place order`, `Verify OTP`, `Apply coupon` — is set to `FILL` width. A FILL-width button cannot reflow.
2. Where a loading button must stay HUG (admin `Save`), the dev sets `min-inline-size` to the Size row's min-width and the label does not change text on load (no "Saving…" swap). The lead marks these instances `HUG+locked` on the documentation board.

### 1.7 Bangla — long-string behaviour

**Strategy: GROW, falling back to WRAP at FILL width.**

- HUG instances: the frame grows horizontally; `Label` never truncates. `কার্টে যোগ করুন` at `Size=Medium` measures 148px versus 96px for `Add to cart` — the min-width of 96 is a floor, not a cap.
- FILL instances: when the label exceeds the available width, `Label` wraps to a **maximum of 2 lines**. The frame's counter-axis sizing changes from `FIXED 44` to `HUG` with `Min height = 44` and vertical padding `spacing/3` (12). The lead builds this as a documentation instance named `Button / bn wrap`; it is not a variant.
- Three lines is a content defect, not a layout case: button labels are capped at 24 Bangla characters in the content guidelines.
- `Place order · ৳2,450` keeps the amount on the same line as the label at all widths — the amount is never allowed to fall to line 2, because a price orphaned from its button reads as a separate figure. Implemented with `white-space: nowrap` on the amount span only.

### 1.8 Touch target

| Size | Visual | Meets 44×44 | How |
|---|---|---|---|
| `Small` 36 | 36 high | No | `_hit44` rectangle (§0.8) expands the hit area to 44×44. **`Size=Small` is forbidden on storefront mobile viewports (<768px).** It is permitted in admin toolbars, admin table rows and desktop secondary actions, where adjacent targets are separated by `spacing/2` minimum. |
| `Medium` 44 | 44 high, 96 min-wide | Yes | Natively |
| `Large` 52 | 52 high, 112 min-wide | Yes | Natively |

### 1.9 Accessibility

- Renders as `<button type=...>` or `<a>`; never a `<div>` with a click handler.
- `focus-visible` ring is 2px `color/border/focus` (teal) at 4px offset — deliberately not brand, so that a focused Danger button and an errored field stay distinguishable (§3 of the source spec).
- Focus ring contrast against `color/bg/canvas` and `color/bg/brand` both exceed 3:1; the 4px offset guarantees the ring is never adjacent to a same-hue fill.
- Icon-only usage is **not** supported — use `IconButton` (§2). A Button with `label` blanked is a defect.
- `Danger` buttons are never the only affordance for a destructive action: they pair with `ConfirmDialog` (Tier 3).

---

## 2. `IconButton`

### 2.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Size` | `Small`, `Medium`, `Large` | 3 |
| `Variant` | `Filled`, `Outline`, `Ghost` | 3 |
| **Total** | | **9** |

Interaction states per §0.3. Disabled per §0.4.

### 2.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `icon` | INSTANCE_SWAP | `icon/x` | `icon` layer |
| `ariaLabel` | TEXT | `Close` | `_aria-label` (§0.2) |
| `focusVisible` | BOOLEAN | `false` | `_focus` |
| `loading` | BOOLEAN | `false` | `_spinner` visible, `icon` hidden |

### 2.3 Geometry

| Size | Frame (FIXED) | Icon | Padding | Radius | Border (Outline) |
|---|---|---|---|---|---|
| `Small` | 36 × 36 | 16 | 0 | `radius/full` | 1.5px |
| `Medium` | 44 × 44 | 20 | 0 | `radius/full` | 1.5px |
| `Large` | 52 × 52 | 24 | 0 | `radius/full` | 1.5px |

Icon stroke 1.75px (Lucide default for this system). Padding is 0 because the frame is FIXED square and the icon is centred — expressing it as padding would require `spacing` values of 10/12/14, two of which do not exist as tokens.

### 2.4 Token bindings

| Variant | State | Fill | Icon | Border |
|---|---|---|---|---|
| **Filled** | Default | `color/bg/brand` | `color/text/on-brand` | — |
| | Hover | `color/bg/brand` + `_scrim` 8% | `color/text/on-brand` | — |
| | Pressed | `color/bg/brand` + `_scrim` 12% | `color/text/on-brand` | — |
| | Focus-visible | `color/bg/brand` | `color/text/on-brand` | `_focus` |
| | Disabled | `color/bg/subtle` | `color/text/tertiary` | — |
| **Outline** | Default | `color/bg/surface` | `color/text/primary` | 1.5px `color/border/strong` |
| | Hover | `color/bg/subtle` | `color/text/primary` | 1.5px `color/border/strong` |
| | Pressed | `color/bg/subtle` + `_scrim` 8% | `color/text/primary` | 1.5px `color/border/strong` |
| | Focus-visible | `color/bg/surface` | `color/text/primary` | 1.5px `color/border/strong` + `_focus` |
| | Disabled | `color/bg/subtle` | `color/text/tertiary` | 1.5px `color/border/default` |
| **Ghost** | Default | `None` | `color/text/secondary` | — |
| | Hover | `color/bg/subtle` | `color/text/primary` | — |
| | Pressed | `color/bg/subtle` + `_scrim` 8% | `color/text/primary` | — |
| | Focus-visible | `None` | `color/text/secondary` | `_focus` |
| | Disabled | `None` | `color/text/tertiary` | — |

`Outline` uses `color/bg/surface` rather than `None` so that a wishlist button placed over a product photograph stays legible. This is its primary use.

### 2.5 Auto-layout

Horizontal, `FIXED` × `FIXED` at the Size row, primary and counter alignment `Center`, padding 0, gap 0, `Clip content = false`.
Layer order: `_scrim` → `icon` → `_spinner` → `_aria-label` → `_focus`.

### 2.6 Bangla

No visible text label, so no reflow. **But `ariaLabel` must be translated** — an untranslated `aria-label` is the most common i18n miss in icon-only controls. The content table lists both strings for all 14 icon-button usages (close, remove, wishlist, share, zoom, previous, next, menu, search, filter, sort, edit, delete, copy). Bangla `aria-label` strings run 40–60% longer; this has no layout consequence but the string table must have a row for every instance, and a missing Bangla row fails the i18n lint.

### 2.7 Touch target

| Size | Meets 44×44 | How |
|---|---|---|
| `Small` 36 | No | `_hit44` (§0.8). **Forbidden on storefront mobile.** Permitted in admin table rows and toolbars with `spacing/2` separation. |
| `Medium` 44 | Yes | Natively |
| `Large` 52 | Yes | Natively |

The wishlist button on `ProductCard` uses `Size=Medium`, `Variant=Outline`, never `Small` — it sits at the card's top-right corner where a 36px target is the single most-missed tap on a toy grid.

### 2.8 Accessibility

- `<button>` with `aria-label`; `aria-label` is required and the component fails CI without it.
- Toggle icon buttons (wishlist) use `aria-pressed`, not a changed `aria-label`, and announce via the page's `aria-live` region: "Added to wishlist".
- `loading` sets `aria-busy="true"`; the icon is replaced by `Spinner`, never removed leaving an empty box.

---

## 3. `Input`

### 3.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `State` | `Default`, `Hover`, `Focus`, `Filled`, `Error`, `Disabled` | 6 |
| `Size` | `Medium`, `Large` | 2 |
| **Total** | | **12** |

Input is the exception to §0.3: `State` **is** a variant axis here. Reason — a form mockup has to show a filled field next to an errored field next to a focused field in one static frame, and `Filled` and `Error` are content states a designer cannot reach by hovering. 12 combinations is well under the ceiling. `Hover` and `Focus` are included on the axis (rather than being interaction-only) because the lead must hand developers an exact border spec for each, and because the error-plus-focus combination is the one pair that must stay visually distinguishable — having both on the axis makes that reviewable side by side. `Error` + focus is rendered as `State=Error` with `focusVisible = true`.

### 3.2 Component properties

| Property | Type | Default | Bound to | Notes |
|---|---|---|---|---|
| `label` | TEXT | `Mobile number` | `Label` | |
| `showLabel` | BOOLEAN | `true` | `Label` visibility | When `false` the label still exists in code as a visually-hidden `<label>`; it is never dropped |
| `required` | BOOLEAN | `true` | `Label › _required` (asterisk) visibility | |
| `placeholder` | TEXT | `01XXXXXXXXX` | `Value › Placeholder` | |
| `value` | TEXT | `01712345678` | `Value › Text` | Visible only on `State=Filled|Error|Disabled` |
| `helperText` | TEXT | `We will SMS your order updates here` | `Helper › Text` | |
| `showHelper` | BOOLEAN | `true` | `Helper` visibility | |
| `errorText` | TEXT | `Enter an 11-digit number starting 01` | `Error › Text` | |
| `prefix` | BOOLEAN | `false` | `Prefix` visibility | |
| `prefixSwap` | INSTANCE_SWAP | `icon/phone` | `Prefix › content` | Accepts an icon **or** a text chip (e.g. `+880`) |
| `suffix` | BOOLEAN | `false` | `Suffix` visibility | |
| `suffixSwap` | INSTANCE_SWAP | `icon/eye` | `Suffix › content` | Password reveal, clear, unit |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

`Error` visibility is driven by the variant, not a boolean, so a designer cannot create the invalid combination "State=Default with an error message showing".

### 3.3 Anatomy and geometry

The component is a vertical stack of four rows. Only `Field` has a fixed height.

```
Input (column, HUG height, FILL width)
├── Label        body-sm 14/600          (gap below: spacing/2 = 8)
├── Field        FIXED height            (gap below: spacing/1 = 4)
├── Helper       caption 12              (shown when no error)
└── Error        caption 12 + icon 14    (shown on State=Error; replaces Helper)
```

| Size | Field height (FIXED) | Field padding X | Field padding Y | Field gap | Radius | Border | Value text style |
|---|---|---|---|---|---|---|---|
| `Medium` | 44 | `spacing/3` (12) | 0 | `spacing/2` (8) | `radius/md` (10) | 1px | `en/body` 15 / 1.6 / 400 |
| `Large` | 52 | `spacing/4` (16) | 0 | `spacing/2` (8) | `radius/md` (10) | 1px | `en/body` 16 / 1.6 / 400 |

Row-level spacing (identical at both sizes):

| Element | Value |
|---|---|
| Label → Field gap | `spacing/2` (8) |
| Field → Helper / Error gap | `spacing/1` (4) |
| Error icon size | 14 |
| Error icon → text gap | `spacing/1` (4) |
| Prefix / Suffix icon size | 20 |
| Prefix / Suffix frame | 20 × 20 FIXED (icon) or HUG × 20 (text chip, e.g. `+880`) |
| Label asterisk | ` *` in `color/feedback/danger`, `spacing/1` before it |

When `prefix = true`, Field padding-left stays `spacing/3`/`spacing/4` and the prefix sits inside it with the standard `spacing/2` gap to the value. Padding is not reduced; the field grows. When `suffix = true`, padding-right likewise unchanged.

**16px minimum font size.** `Size=Large` uses 16px because Android Chrome and iOS Safari both zoom the viewport on focus for any input under 16px. `Size=Medium` at 15px is permitted **only in the admin**, where the viewport is not zoom-sensitive; **every storefront form field — checkout, OTP, address, coupon, search — uses `Size=Large`.** This is a hard rule, not a preference: a viewport zoom on the checkout phone field is a measurable abandonment event.

### 3.4 Token bindings per state

| State | Field fill | Border | Value text | Placeholder | Label | Helper | Error text |
|---|---|---|---|---|---|---|---|
| `Default` | `color/bg/surface` | 1px `color/border/default` | — | `color/text/tertiary` | `color/text/primary` | `color/text/secondary` | hidden |
| `Hover` | `color/bg/surface` | 1px `color/border/strong` | — | `color/text/tertiary` | `color/text/primary` | `color/text/secondary` | hidden |
| `Focus` | `color/bg/surface` | **2px** `color/border/focus` + `_focus` ring | `color/text/primary` | `color/text/tertiary` | `color/text/primary` | `color/text/secondary` | hidden |
| `Filled` | `color/bg/surface` | 1px `color/border/default` | `color/text/primary` | hidden | `color/text/primary` | `color/text/secondary` | hidden |
| `Error` | `color/bg/surface` | **1.5px** `color/feedback/danger` | `color/text/primary` | `color/text/tertiary` | `color/text/primary` | hidden | `color/feedback/danger` |
| `Disabled` | `color/bg/subtle` | 1px `color/border/default` | `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` | hidden |

Stroke align `Inside` on all states, so the 1px → 2px → 1.5px changes **do not shift the field's outer box or its neighbours.** This is the reason the border is inside rather than centred; an outside or centred stroke makes the whole form jump 0.5px when a field is focused.

Error + focus: border stays `1.5px color/feedback/danger` (the field's own identity) and the `_focus` ring is added at 4px offset in `color/border/focus` (teal). Red border, teal ring — two different hues, so "errored" and "where am I" are never confused. This is the reason `color/border/focus` is teal and not brand.

### 3.5 Auto-layout

**`Input` (root)** — Direction `Vertical`; primary axis `HUG`; counter axis `FILL`; gap `0` (per-row gaps set with `Spacing between items` overridden by absolute row spacing — build as: root gap = `spacing/2`, then `Field` carries a top gap of 0 and `Helper`/`Error` use a nested wrapper with gap `spacing/1`). Counter alignment `Left` (`Right` in the RTL build — not required for Bangla, which is LTR). Padding 0.

Concretely, to avoid two different gaps in one stack:

```
Input (column, gap = spacing/2, HUG × FILL)
├── Label
└── FieldGroup (column, gap = spacing/1, HUG × FILL)
    ├── Field
    └── Message (Helper or Error)
```

**`Field`** — Direction `Horizontal`; primary axis `FILL`; counter axis `FIXED` at the Size height; primary alignment `Left`; counter alignment `Center`; padding X per Size row, Y = 0; gap `spacing/2`; `Clip content = false`.
Layer order: `Prefix` → `Value` → `Suffix` → `_focus`.
**`Value`** — `FILL` × `HUG`, `Align = Center`, single line, `Truncate = off`. `Placeholder` and `Text` are two text layers in the same slot, mutually exclusive by state.
**`Message`** — Direction `Horizontal`; `FILL` × `HUG`; gap `spacing/1`; counter alignment `Top` (so the 14px icon aligns with the first line of a wrapped two-line error, not with the vertical centre of the block).

### 3.6 How the error message attaches programmatically

This is the part that is most often got wrong, so it is specified as markup, not prose.

```html
<div class="field">
  <label for="phone" id="phone-label">Mobile number <span aria-hidden="true">*</span></label>

  <input
    id="phone"
    name="phone"
    type="tel"
    inputmode="numeric"
    autocomplete="tel-national"
    maxlength="11"
    required
    aria-required="true"
    aria-invalid="true"
    aria-describedby="phone-error phone-helper"
  />

  <!-- Helper is ALWAYS in the DOM, even when visually hidden by the error state -->
  <p id="phone-helper" class="helper">We will SMS your order updates here</p>

  <!-- Error node is ALWAYS in the DOM, empty when valid. Never conditionally mounted. -->
  <p id="phone-error" class="error" role="alert" aria-live="polite">
    <svg aria-hidden="true" focusable="false" width="14" height="14">…</svg>
    Enter an 11-digit number starting 01
  </p>
</div>
```

Rules, all of them load-bearing:

1. **`aria-describedby` lists the error id first**, then the helper id. Screen readers read describedby in attribute order, and the fix matters more than the hint.
2. **The error element is always present in the DOM**, empty when the field is valid. A node mounted at validation time is frequently missed by `aria-live` because the live region did not exist when the mutation happened. Toggling text content inside a persistent region announces reliably on TalkBack and NVDA.
3. **`aria-live="polite"` plus `role="alert"`.** `role="alert"` is implicitly assertive; the explicit `aria-live="polite"` downgrades it so that typing in a field does not interrupt the user mid-keystroke. On submit-time validation the error container is instead given focus-adjacent treatment: focus moves to the first invalid input, which causes its description to be read.
4. **`aria-invalid="true"` is set only when an error is displayed**, and removed (not set to `"false"` and left) when the field becomes valid.
5. **Colour is never the only signal** — the 14px alert-triangle icon and the text itself carry the meaning. The red border is reinforcement. This satisfies WCAG 1.4.1.
6. **The label is a real `<label for>`**, not `aria-label`, so that tapping the label focuses the field. On a 390px phone the label is a significant part of the field's effective target.
7. **Error text names the fixable thing**: `Enter an 11-digit number starting 01`, never `Invalid input`. It never says "please".
8. **Summary on submit.** Checkout renders an `Alert Type=Danger` above the form listing each invalid field as an in-page link to its input, plus per-field inline errors. The summary gets focus on failed submit; the inline errors are what the user reads while fixing.
9. **Nothing playful.** The error state uses `radius/md`, no illustration, no animation, no colour celebration on recovery. A form error is where a parent decides whether this shop is competent.

### 3.7 Bangla

**Strategy: Label WRAP (2 lines max), Helper/Error WRAP (3 lines max), Value single line.**

- `Label` is `FILL` × `HUG` and wraps to a maximum of 2 lines. `Mobile number` (13 chars) → `মোবাইল নম্বর` is shorter, but `Delivery address with landmark` → `ল্যান্ডমার্কসহ ডেলিভারি ঠিকানা` is 31 chars and wraps on a 390px viewport at `spacing/4` gutters. The 8px Label→Field gap is measured from the last line.
- `Helper` and `Error` are `FILL` × `HUG`, wrap to 3 lines max, `bn/caption` line-height 12 × 1.4 × 1.15 = 19.3px. A three-line Bangla error is 58px tall; the form's total height is HUG, so the stack absorbs it. **No field is positioned absolutely and no form uses a fixed-height row**, precisely so that this growth is free.
- The `Message` row's counter alignment is `Top` so a wrapped error keeps its icon on line 1.
- `Value` never wraps: the input is single-line by definition. Long Bangla values (addresses) use `Textarea` (§4), not `Input`.
- The asterisk stays after the label text in both scripts.

### 3.8 Touch target

`Field` is 44 (`Medium`) or 52 (`Large`) tall at `FILL` width — always ≥ 44 in both axes. The `<label>` adds its own 20px-tall target above it, which is additive rather than part of the 44. `Prefix`/`Suffix` icons are decorative at 20px; when a suffix is **interactive** (password reveal, clear) it is an `IconButton Size=Medium` (44×44) and the Field's padding-right drops to `spacing/1` (4) so the 44px button fits inside a 44px field without the field growing — this is the only case where Field padding changes, and it is a separate documentation instance named `Input / interactive suffix`.

---

## 4. `Textarea`

### 4.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `State` | `Default`, `Hover`, `Focus`, `Filled`, `Error`, `Disabled` | 6 |
| `Size` | `Medium`, `Large` | 2 |
| **Total** | | **12** |

Identical axis structure to `Input`, deliberately — the two components are reviewed side by side and a different axis shape between them creates handoff mistakes.

### 4.2 Component properties

Same set as `Input` (§3.2) minus `prefix`/`prefixSwap`/`suffix`/`suffixSwap`, plus:

| Property | Type | Default | Bound to |
|---|---|---|---|
| `showCounter` | BOOLEAN | `false` | `Footer › Counter` visibility |
| `counterText` | TEXT | `0 / 500` | `Footer › Counter` |

### 4.3 Geometry

| Size | Field min-height | Field max-height | Padding X | Padding Y | Radius | Border |
|---|---|---|---|---|---|---|
| `Medium` | 96 (3 lines) | 240 | `spacing/3` (12) | `spacing/3` (12) | `radius/md` (10) | 1px |
| `Large` | 120 (4 lines) | 320 | `spacing/4` (16) | `spacing/4` (16) | `radius/md` (10) | 1px |

- Counter axis sizing `HUG` with `Min height` set per the table — the field auto-grows with content up to the max-height, then scrolls. It never shrinks below the min.
- `resize: vertical` in CSS; `resize: horizontal` and `resize: both` are disabled (horizontal resize breaks the form grid).
- Counter row: `caption` 12, `color/text/secondary`, right-aligned, gap above `spacing/1` (4). Turns `color/feedback/warning` at 90% of the limit and `color/feedback/danger` at 100%; the counter is `aria-live="polite"` and announces only at those two thresholds, not per keystroke.
- Scrollbar appears only past max-height; `overscroll-behavior: contain` so a scrolling textarea inside the mobile checkout bottom sheet does not drag the sheet.

### 4.4 Token bindings

Identical to `Input` §3.4, row for row. The only difference: `Value` text layer is `FILL` × `HUG`, multi-line, `Align = Top-left`, and `counter axis alignment` on the Field is `Top` rather than `Center`.

### 4.5 Auto-layout

```
Textarea (column, gap = spacing/2, HUG × FILL)
├── Label
└── FieldGroup (column, gap = spacing/1, HUG × FILL)
    ├── Field   (vertical, FILL × HUG min per Size, align Top-left, padding per Size)
    │   └── Value  (FILL × HUG, multi-line)
    ├── Footer  (horizontal, FILL × HUG, Space between)
    │   ├── Message (Helper or Error)  — FILL
    │   └── Counter                    — HUG, right
    └── (Footer is a single row so the counter and the error share a baseline)
```

### 4.6 Bangla

**Strategy: WRAP, unbounded line count up to max-height, then SCROLL.**
Bangla at `bn/body` 15 × 1.6 × 1.15 = 27.6px per line versus 24px Latin. A `Medium` textarea holding 3 Latin lines holds 3 Bangla lines in 96px only if padding is 12 top and bottom: 96 − 24 = 72px of content box, which holds 2.6 Bangla lines. **Therefore `Size=Medium` min-height is raised to 104 in the `.lang-bn` build** (3 × 27.6 + 24 = 106.8 → round to 108). Final numbers: `.lang-bn` min-heights are **108** (`Medium`) and **136** (`Large`). The lead builds two documentation instances showing the en and bn min-heights, and the CSS sets `min-height` from a `--textarea-min-h` custom property that `.lang-bn` overrides. Max-heights are unchanged.

### 4.7 Touch target

96 / 120 tall at FILL width — exceeds 44 in both axes with margin. The resize grabber is a native 16px control in the bottom-right and is **hidden on viewports under 768px** (`resize: none` on mobile), because a 16px grabber inside a 44px gesture zone competes with the page scroll. Auto-grow covers the mobile case.

### 4.8 Error attachment

Identical to §3.6. The address field's error is the highest-traffic one on the site (`Add a landmark — beside which shop, which floor`) and is specified as a `Helper`, not an `Error`: it is guidance, not a failure, and it is present before the user types.

---

## 5. `Select` (custom)

### 5.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `State` | `Default`, `Hover`, `Focus`, `Filled`, `Open`, `Error`, `Disabled` | 7 |
| `Size` | `Medium`, `Large` | 2 |
| **Total** | | **14** |

`Open` is on the axis because the lead must hand over the menu's exact geometry and because `Open` is the state in which every keyboard and screen-reader behaviour is specified.

### 5.2 Scope — when the custom Select is allowed

`Select` is permitted **only** when at least one of these is true:

- the viewport is ≥1024px (desktop), **or**
- options need a thumbnail, a price, a swatch or two lines of text, **or**
- the control is in the admin.

In every other case — and specifically for the checkout district → upazila → area cascade, sort order, and any quantity over 10 — use `NativeSelect` (§6). A custom listbox on a mid-range Android is a worse control in six measurable ways; §6.2 lists them.

### 5.3 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `District` | `Label` |
| `placeholder` | TEXT | `Choose a district` | `Trigger › Placeholder` |
| `value` | TEXT | `Dhaka` | `Trigger › Value` |
| `helperText` | TEXT | `Delivery charge depends on this` | `Helper › Text` |
| `errorText` | TEXT | `Choose a district to continue` | `Error › Text` |
| `showHelper` | BOOLEAN | `true` | `Helper` visibility |
| `focusVisible` | BOOLEAN | `false` | `_focus` |
| `menuSlot` | INSTANCE_SWAP | `Select/Menu` | `Menu` (visible on `State=Open`) |

`Select/Option` is a separate component set (built before `Select`): axis `State=Default|Hover|Selected|Disabled` (4), properties `label` TEXT, `description` TEXT + BOOLEAN `showDescription`, `leadingSlot` INSTANCE_SWAP + BOOLEAN `showLeading`.

### 5.4 Geometry — Trigger

Identical to `Input › Field` (§3.3) in height, padding, radius and border, with one addition:

| Element | Value |
|---|---|
| Chevron icon | 20 (`icon/chevron-down`), `color/text/secondary` |
| Chevron gap from value | `spacing/2` (8) |
| Chevron rotation on `Open` | 180°, `base` 200ms `standard` easing. Reduced motion: no rotation, swap to `icon/chevron-up` instantly. |

### 5.5 Geometry — Menu

| Element | Value |
|---|---|
| Fill | `color/bg/surface-raised` |
| Border | 1px `color/border/default` |
| Radius | `radius/lg` (14) |
| Elevation | `e3` |
| Padding | `spacing/2` (8) all sides |
| Gap between options | 0 |
| Min-width | matches the trigger width (set `Menu` to `FILL` inside an absolute wrapper anchored to the trigger) |
| Max-width | 420 |
| Max-height | 320, then vertical scroll with `overscroll-behavior: contain` |
| Offset from trigger | `spacing/1` (4) below, flipping above on collision with a 16px viewport inset |
| Option height | 44 FIXED (56 when `showDescription = true`) |
| Option padding X | `spacing/3` (12) |
| Option padding Y | 0 (`Center`), or `spacing/2` with `HUG` when `showDescription = true` |
| Option gap | `spacing/3` (12) between leading slot, text block and check icon |
| Option radius | `radius/sm` (6) |
| Leading slot | 24 × 24 (icon, swatch or thumbnail) |
| Check icon | 20 (`icon/check`), right-aligned, visible on `State=Selected` only |
| Search field inside menu | `Input Size=Medium` pinned at the top, shown when option count > 12 |

Option token bindings:

| Option state | Fill | Label | Description | Check |
|---|---|---|---|---|
| `Default` | `None` | `color/text/primary` | `color/text/secondary` | hidden |
| `Hover` | `color/bg/subtle` | `color/text/primary` | `color/text/secondary` | hidden |
| `Selected` | `color/bg/brand-subtle` | `color/text/primary` (weight 600) | `color/text/secondary` | `color/bg/brand` |
| `Disabled` | `None` | `color/text/tertiary` | `color/text/tertiary` | hidden |

A disabled option is **never hidden**; it shows the reason in `description` (`Out of stock in this size`). Hiding unavailable options is how a shop looks like it has nothing.

### 5.6 Trigger token bindings

Identical to `Input` §3.4 for `Default`, `Hover`, `Focus`, `Filled`, `Error`, `Disabled`. Plus:

| State | Fill | Border | Value | Chevron |
|---|---|---|---|---|
| `Open` | `color/bg/surface` | 2px `color/border/focus` | `color/text/primary` | `color/text/primary`, rotated 180° |

### 5.7 Auto-layout

`Select` root: identical structure to `Input` (§3.5), with `Menu` as a fourth child that has `Ignore auto-layout` on and is positioned under `Field`, so the open menu does not push the rest of the form down.
`Menu`: Vertical, `HUG` × `FILL`, alignment `Top-left`, padding `spacing/2`, gap 0.
`Select/Option`: Horizontal, `FILL` × `FIXED 44` (or `HUG` with description), counter alignment `Center`, padding X `spacing/3`, gap `spacing/3`; layer order `leadingSlot` → `TextBlock` (`FILL`) → `Check`.

### 5.8 Keyboard and accessibility

- Trigger: `<button role="combobox" aria-expanded aria-controls aria-haspopup="listbox" aria-labelledby="label-id">`.
- Menu: `<ul role="listbox">`, options `<li role="option" aria-selected aria-disabled>`.
- `Space` / `Enter` / `ArrowDown` / `ArrowUp` open. `ArrowDown`/`ArrowUp` move active option, `Home`/`End` jump, `Enter` commits, `Esc` closes and returns focus to the trigger, `Tab` closes and commits.
- Type-ahead: printable characters jump to the first option starting with the typed buffer; buffer clears after 800ms. Bangla type-ahead matches on the Bangla label; Banglish matching is a search-field concern, not a Select concern.
- Active option is tracked with `aria-activedescendant`; DOM focus stays on the trigger (or on the in-menu search field when present).
- Focus is **not** trapped — this is a listbox, not a dialog. Clicking outside commits nothing and closes.
- The menu is rendered in a portal at the document root with `z-index` above the sticky `AddToCartBar`, and inside a `Drawer` it is portalled into the drawer so the scroll lock does not clip it.

### 5.9 Bangla

**Strategy: Trigger GROW/WRAP, Option WRAP (2 lines).**
- `Trigger › Value` is `FILL` single-line with `Truncate = off`; if a district name plus its Bangla qualifier exceeds the trigger width, the trigger height changes to `HUG` with `Min height` 44/52 and the value wraps to 2 lines. Documentation instance: `Select / bn wrap`.
- `Select/Option` label wraps to 2 lines; option height changes from `FIXED 44` to `HUG` with `Min height 44` and padding Y `spacing/2`. With `showDescription = true` the option holds 2 label lines + 1 description line = 76px in Bangla.
- Menu max-height stays 320; more Bangla lines simply means fewer options visible, which is correct.
- The in-menu search field uses `Input Size=Large` (16px) even inside a `Medium` Select, for the iOS/Android zoom reason in §3.3.

### 5.10 Touch target

Trigger 44/52 × FILL — passes. Options 44 tall × full menu width — passes. The check icon and leading slot are inside the option's single tap target, not separate targets. The in-menu clear control, where present, is `IconButton Size=Medium`.

---

## 6. `NativeSelect`

### 6.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `State` | `Default`, `Hover`, `Focus`, `Filled`, `Error`, `Disabled` | 6 |
| `Size` | `Medium`, `Large` | 2 |
| **Total** | | **12** |

No `Open` state: the open state is the operating system's, and it is not ours to design. That is the point of the component.

### 6.2 Why native is preferred on Android — the six reasons

1. **It is the OS picker.** Android renders a full-width dialog or a wheel sized by the platform, with the platform's own momentum scrolling, fling physics and 48dp rows. A JS listbox on a mid-range Android (the stated target device) scrolls at a measurably lower frame rate because every row is a composited DOM node; the native picker is drawn by the system.
2. **Zero JavaScript.** A custom select costs roughly 4–6KB gzipped for positioning, collision detection, focus management and type-ahead, plus the option list in the HTML payload. Against a 120KB JS budget on checkout (§8 of the source spec) and a 4G connection, a district selector with 64 options and three cascading levels is the difference between meeting and missing the budget.
3. **TalkBack works without any ARIA.** `<select>` announces role, value, position-in-set and set size natively. Every custom listbox re-implements that with `aria-activedescendant`, and the re-implementation is where the bugs live. Bangla screen-reader support on Android is already thin; relying on the platform control removes a whole class of failure.
4. **It does not fight the virtual keyboard or the visual viewport.** On Android, focusing an input resizes the visual viewport. An absolutely-positioned JS menu anchored to a trigger inside a scrolled bottom sheet gets its coordinates from a viewport that just changed height; the result is a menu that opens off-screen or detached from its trigger. The native picker is outside the web viewport entirely and cannot be mispositioned.
5. **Autofill and the Android keyboard's suggestion strip.** `<select autocomplete="address-level1">` participates in Android autofill; a `<div role="listbox">` does not. For a returning customer re-entering a district, that is a tap saved on the highest-abandonment screen.
6. **No scroll-lock, z-index or portal bugs.** The checkout filter and address flows both put selects inside a bottom-sheet `Drawer`. Every custom-menu-inside-a-scroll-locked-sheet combination has to solve clipping, portalling and `overscroll-behavior`. A native select has nothing to clip.

**Therefore:** `NativeSelect` is the default select in this system. `Select` (§5) is the exception, allowed only under the three conditions in §5.2. Checkout, in particular, uses `NativeSelect` exclusively.

### 6.3 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `District` | `Label` |
| `value` | TEXT | `Dhaka` | `Field › Value` |
| `placeholder` | TEXT | `Choose a district` | `Field › Placeholder` |
| `helperText` | TEXT | `Delivery charge depends on this` | `Helper › Text` |
| `errorText` | TEXT | `Choose a district to continue` | `Error › Text` |
| `showHelper` | BOOLEAN | `true` | `Helper` visibility |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

### 6.4 Geometry and token bindings

Identical to `Select › Trigger` (§5.4, §5.6) for every state the axis shares — same heights (44/52), same padding (`spacing/3`/`spacing/4`), same `radius/md`, same borders, same chevron at 20px `color/text/secondary` with `spacing/2` gap. **This is deliberate: the two selects must be visually indistinguishable**, so that swapping one for the other is a code change with no visual review.

Styling notes for the implementer:

```css
.native-select {
  appearance: none;            /* removes the OS chevron so ours is used */
  background-image: url("data:image/svg+xml,…chevron…");  /* inlined, not a web request */
  background-position: right var(--space-4) center;
  background-repeat: no-repeat;
  padding-inline-end: calc(var(--space-4) + 20px + var(--space-2));
  font-size: 16px;             /* always 16, both sizes — see below */
  line-height: 1.6;
  color: var(--color-text-primary);
}
.native-select:invalid { color: var(--color-text-tertiary); }  /* placeholder styling */
```

- **Font size is 16px at both sizes**, not 15px at `Medium`. A `<select>` under 16px triggers the same focus-zoom as an `<input>`, and unlike the custom Select there is no way to intercept it.
- The placeholder is `<option value="" disabled selected hidden>`; with `required` on the select, `:invalid` colours it `color/text/tertiary` and it cannot be re-chosen.
- `color/bg/surface` must be set explicitly on both the `<select>` and its `<option>`s: Android's default option background is a system grey that ignores the parent.
- The chevron SVG is inlined as a data URI rather than a background image file — one fewer request on 4G, and it survives a cache miss.

### 6.5 Auto-layout

Identical to `Input` (§3.5). The `Field` child is a single `<select>`-shaped frame: Horizontal, `FILL` × `FIXED` 44/52, counter alignment `Center`, padding X `spacing/3`/`spacing/4` with padding-right extended to accommodate the chevron (`spacing/4` + 20 + `spacing/2` = 44 at `Large`), gap `spacing/2`; layer order `Value` (`FILL`) → `Chevron`.

### 6.6 Error attachment

Identical to §3.6 — `<select>` takes `aria-describedby`, `aria-invalid` and `required` the same way an `<input>` does. The cascading case has one addition: when `district` changes, `upazila` and `area` are reset, their errors cleared, and the reset announced once in a single `aria-live="polite"` message (`Upazila and area reset — choose again`), not as three separate announcements.

### 6.7 Bangla

**Strategy: GROW, then WRAP at 2 lines.**
The `<select>` element's own text cannot wrap — a native select truncates with an ellipsis, which is the one truncation this document cannot forbid because it is the user agent's. Mitigation, all three required:

1. District, upazila and area names are short in Bangla (`ঢাকা`, `সাভার`) and fit at 390px with room to spare; the measured worst case in the BD administrative list is `চট্টগ্রাম মহানগর` at 16 characters, which fits a 390px-wide select at 16px Anek Bangla with 44px of right padding.
2. The **label** carries the long string and wraps to 2 lines (`Label` is `FILL` × `HUG`), so the explanatory burden is never on the select's own text.
3. For any option list where a Bangla label could exceed the control width, the selected value is echoed below the field as a `Helper` line (`নির্বাচিত: চট্টগ্রাম মহানগর`), so a truncated value is always readable in full somewhere. This applies to the area-level select only.

Option text inside the native picker wraps automatically on Android and is not our concern.

### 6.8 Touch target

44/52 × FILL — passes. The native picker's own rows are 48dp, set by the platform. The chevron is decorative and inside the field's single target.

---

## 7. `Checkbox`

### 7.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Checked` | `False`, `True`, `Indeterminate` | 3 |
| `Disabled` | `False`, `True` | 2 |
| **Total** | | **6** |

`Checked` is a content state (a designer must place a pre-checked "Save this address" box) so it is an axis. `Disabled` is an axis here — unlike Button — because disabled checkboxes are common in permission matrices and bulk-select headers, and 6 combinations is cheap. Hover, pressed and focus-visible remain interaction-only.

### 7.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `Save this address for next time` | `Label` |
| `showLabel` | BOOLEAN | `true` | `Label` visibility |
| `description` | TEXT | `We will not share your details` | `Description` |
| `showDescription` | BOOLEAN | `false` | `Description` visibility |
| `error` | BOOLEAN | `false` | `Box` border → `color/feedback/danger`, `Message` visible |
| `errorText` | TEXT | `Accept the terms to continue` | `Message › Text` |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

### 7.3 Geometry

| Element | Value |
|---|---|
| Row height | `HUG`, `Min height` **44** |
| Row padding | Y `spacing/2` (8), X 0 |
| Box | 20 × 20 FIXED |
| Box radius | `radius/sm` (6) |
| Box border | 1.5px |
| Check icon | 14 (`icon/check`), stroke 2.25px (heavier than the 1.75px default — a 14px check at 1.75px reads as grey at 2× DPR) |
| Indeterminate mark | 10 × 2 rounded bar, `radius/full` |
| Box → Label gap | `spacing/3` (12) |
| Label text | `en/body` 15 / 1.6 / 400, `color/text/primary` |
| Label → Description gap | `spacing/1` (4) |
| Description text | `en/body-sm` 14 / 1.5 / 400, `color/text/secondary` |
| Message (error) gap | `spacing/1` (4) below the text block |
| Message text | `en/caption` 12, `color/feedback/danger`, with a 14px icon and `spacing/1` gap |
| Box top alignment | `Top`, with a 2px downward nudge so the 20px box optically centres on the 24px first line of the label |

The 2px nudge is implemented as `padding-top: 2px` on the `Box` wrapper, not as a magic offset, so it survives a line-height change in the `.lang-bn` build (where the first line is 27.6px and the nudge becomes 4px — the wrapper uses `padding-top: calc((1lh - 20px) / 2)`).

### 7.4 Token bindings

| Checked | Disabled | Box fill | Box border | Mark | Label | Description |
|---|---|---|---|---|---|---|
| `False` | `False` | `color/bg/surface` | 1.5px `color/border/strong` | — | `color/text/primary` | `color/text/secondary` |
| `True` | `False` | `color/bg/brand` | 1.5px `color/bg/brand` | `color/text/on-brand` | `color/text/primary` | `color/text/secondary` |
| `Indeterminate` | `False` | `color/bg/brand` | 1.5px `color/bg/brand` | `color/text/on-brand` | `color/text/primary` | `color/text/secondary` |
| `False` | `True` | `color/bg/subtle` | 1.5px `color/border/default` | — | `color/text/tertiary` | `color/text/tertiary` |
| `True` | `True` | `color/bg/subtle` | 1.5px `color/border/default` | `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` |
| `Indeterminate` | `True` | `color/bg/subtle` | 1.5px `color/border/default` | `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` |

Interaction states (all at `Disabled=False`):

| State | Change |
|---|---|
| Hover | `Checked=False`: box border → `color/border/focus`… **no** — box border stays `color/border/strong`, and the **row** gains fill `color/bg/subtle` with `radius/md`. `Checked=True`: `_scrim` 8% on the box. |
| Pressed | Box `scale(0.92)`, `fast` 120ms. `_scrim` 12% when checked. |
| Focus-visible | `_focus` ring around the **Box only** (inset −4px on the 20px box, radius `radius/md`), not around the whole row — a ring around a three-line consent paragraph is unreadable. |
| `error = true` | Box border 1.5px `color/feedback/danger` at `Checked=False`; `Message` visible. Checked state clears the error. |

Hover deliberately does not use `color/border/focus` — that token is reserved for focus, so that "hovered" and "focused" never look identical.

The check mark animates: `stroke-dashoffset` from full to 0 over `fast` 120ms `decelerate`. Reduced motion: appears instantly. The box fill crossfades over `fast` 120ms in both cases (a colour change is not vestibular motion).

### 7.5 Auto-layout

```
Checkbox (horizontal, FILL × HUG min 44, counter align Top, padding Y spacing/2, gap spacing/3)
├── BoxWrapper (FIXED 20 × HUG, padding-top calc, Clip content false)
│   ├── Box (20 × 20)
│   │   └── Mark (check or indeterminate bar, centred)
│   └── _focus
└── TextBlock (column, FILL × HUG, gap spacing/1)
    ├── Label        (FILL × HUG, wrap)
    ├── Description  (FILL × HUG, wrap)
    └── Message      (horizontal, FILL × HUG, gap spacing/1, align Top)
```

### 7.6 Bangla

**Strategy: WRAP, unbounded.**
`Label` and `Description` are `FILL` × `HUG` and wrap freely — consent and marketing-opt-in text is the longest copy any primitive in this document carries, and the Bangla version of `I agree to the Terms of Service and Privacy Policy` runs to three lines at 390px. The row's `Min height 44` is a floor only; the row grows. The box stays optically aligned to line 1 via the `calc((1lh - 20px) / 2)` padding, which produces 2px at `en/body` and 4px at `bn/body` automatically. Nothing is hard-coded per locale.

### 7.7 Touch target

The entire row is the `<label>`, so the target is `row width × max(44, row height)` — comfortably over 44×44 even when the visual box is 20×20. Where a checkbox appears with **no label** (a DataTable row-select), the component is wrapped in a 44×44 frame with the box centred and `ariaLabel` set; that wrapper is a separate component, `Checkbox/Bare`, with axes identical to `Checkbox` and a 44×44 FIXED frame. In a 44px-tall table row this means the select target fills the row height exactly.

### 7.8 Accessibility

- `<input type="checkbox">` inside a `<label>`; the native input is visually hidden with the clip-rect technique (never `display: none`, which removes it from the a11y tree and the tab order).
- Indeterminate is set via the `indeterminate` DOM property, which is not an attribute and not serialisable — the implementer sets it in an effect, and `aria-checked="mixed"` is **not** added manually (the native property already reports mixed).
- Error attachment per §3.6: `aria-describedby` → message id, `aria-invalid` on the input.
- A required checkbox (terms acceptance) is `required` + `aria-required="true"`; its error appears on submit, inline, below the row, and the form summary links to it.

---

## 8. `Radio`

### 8.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Selected` | `False`, `True` | 2 |
| `Disabled` | `False`, `True` | 2 |
| **Total** | | **4** |

No indeterminate state exists for a radio. The group wrapper (`RadioGroup`) is a layout frame, not a variant set.

### 8.2 Component properties

Same as `Checkbox` (§7.2), plus:

| Property | Type | Default | Bound to |
|---|---|---|---|
| `trailingSlot` | INSTANCE_SWAP | `Price` | `TrailingSlot` |
| `showTrailing` | BOOLEAN | `false` | `TrailingSlot` visibility |
| `variantStyle` | — | — | *not a property* — see §8.5 `Radio/Card` |

`trailingSlot` exists because the two highest-value radio groups on the site are payment method (`Cash on delivery` + a logo row) and delivery method (`Standard` + `Price` + ETA). Both need a right-aligned slot.

### 8.3 Geometry

| Element | Value |
|---|---|
| Row height | `HUG`, `Min height` **44** |
| Row padding | Y `spacing/2` (8), X 0 |
| Dot outer | 20 × 20 FIXED, `radius/full` |
| Dot border | 1.5px (unselected) / **6px** (selected, drawn as an inner fill, see below) |
| Inner dot | 10 × 10, `radius/full`, centred |
| Dot → Label gap | `spacing/3` (12) |
| Label | `en/body` 15 / 1.6 / 400 |
| Description | `en/body-sm` 14 / 1.5 / 400 |
| Trailing slot gap | `spacing/3` (12) from the text block |
| Dot top alignment | `Top` + `padding-top: calc((1lh - 20px) / 2)` (as §7.3) |

The selected dot is built as: 20px circle, fill `color/bg/surface`, border 1.5px `color/bg/brand`, containing a 10px circle filled `color/bg/brand`. It is **not** built as a 6px border, because a thick border animates badly and renders unevenly at 1.5× DPR.

### 8.4 Token bindings

| Selected | Disabled | Outer fill | Outer border | Inner dot | Label | Description |
|---|---|---|---|---|---|---|
| `False` | `False` | `color/bg/surface` | 1.5px `color/border/strong` | hidden | `color/text/primary` | `color/text/secondary` |
| `True` | `False` | `color/bg/surface` | 1.5px `color/bg/brand` | `color/bg/brand` | `color/text/primary` (600) | `color/text/secondary` |
| `False` | `True` | `color/bg/subtle` | 1.5px `color/border/default` | hidden | `color/text/tertiary` | `color/text/tertiary` |
| `True` | `True` | `color/bg/subtle` | 1.5px `color/border/default` | `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` |

Interaction: hover → row fill `color/bg/subtle` at `radius/md`; pressed → inner dot `scale(0.85)` `fast`; focus-visible → `_focus` ring on the dot only, `radius/full`. Inner dot enters with `scale(0 → 1)` over `fast` 120ms `decelerate`; reduced motion → instant.

Selected label goes to weight 600. This is the one weight change in the component set and it exists because a payment-method group is read at a glance.

### 8.5 `Radio/Card` — a separate component, not a variant

Payment and delivery selection use a card-shaped radio. Building it as a variant of `Radio` would add a `Style=Row|Card` axis and change every geometry number per value, which is a different component wearing the same name. It is therefore its own set.

`Radio/Card` — axes `Selected=False|True` × `Disabled=False|True` (4).

| Element | Value |
|---|---|
| Frame | `FILL` × `HUG`, `Min height` 64 |
| Padding | `spacing/4` (16) all sides |
| Gap | `spacing/3` (12) |
| Radius | `radius/lg` (14) |
| Border | 1.5px |
| Elevation | none (unselected), `e1` (selected) |

| Selected | Fill | Border | Label |
|---|---|---|---|
| `False` | `color/bg/surface` | 1.5px `color/border/default` | `color/text/primary` |
| `True` | `color/bg/brand-subtle` | 1.5px `color/bg/brand` | `color/text/primary` (600) |
| `False` + Disabled | `color/bg/subtle` | 1.5px `color/border/default` | `color/text/tertiary` |
| `True` + Disabled | `color/bg/subtle` | 1.5px `color/border/default` | `color/text/tertiary` |

Focus-visible on `Radio/Card` rings the **whole card** (`_focus` at −4px, `radius/xl` = 14 + 4 = 18, use `radius/lg` + 4 as a raw 18px value), because the card *is* the control.

### 8.6 Auto-layout

```
Radio (horizontal, FILL × HUG min 44, counter align Top, padding Y spacing/2, gap spacing/3)
├── DotWrapper (FIXED 20 × HUG, padding-top calc, Clip content false)
│   ├── Dot
│   └── _focus
├── TextBlock (column, FILL × HUG, gap spacing/1)
│   ├── Label
│   ├── Description
│   └── Message
└── TrailingSlot (HUG × HUG, counter align Center)
```

### 8.7 Bangla

**Strategy: WRAP, unbounded**, identical mechanics to `Checkbox` §7.6. `Cash on delivery — pay when it arrives` → `ক্যাশ অন ডেলিভারি — পৌঁছানোর পর পেমেন্ট করুন` is 44 characters and wraps to 2 lines at 390px with a `trailingSlot` present. The `TrailingSlot` is `HUG` and keeps its width; the `TextBlock` is `FILL` and absorbs the loss. On viewports under 480px with both a description and a trailing slot, `Radio/Card` switches its direction to `Vertical` with the trailing slot below the text at gap `spacing/2` — a documented responsive instance, `Radio/Card / narrow`, not a variant.

### 8.8 Touch target

Whole row / whole card is the `<label>`: ≥ 44 in both axes. `Radio/Card` min-height 64.

### 8.9 Accessibility

- `<input type="radio">` with a shared `name`, inside a `<fieldset>` with a `<legend>` (the group label). The legend is a real legend, not a `<p>` — screen readers announce it with every option.
- Arrow keys move **and select** within the group (native radio behaviour; do not intercept). `Tab` enters the group at the selected option and leaves the group entirely — one tab stop per group.
- The group's error is attached to the `<fieldset>` via `aria-describedby`, not to an individual input.
- COD is pre-selected and listed first (§6.1 of the source spec); pre-selection is reflected in `checked`, so a submit with no interaction is valid.

---

## 9. `Switch`

### 9.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `On` | `False`, `True` | 2 |
| `Disabled` | `False`, `True` | 2 |
| **Total** | | **4** |

### 9.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `Send order updates on WhatsApp` | `Label` |
| `showLabel` | BOOLEAN | `true` | `Label` visibility |
| `description` | TEXT | `You can turn this off any time` | `Description` |
| `showDescription` | BOOLEAN | `false` | `Description` visibility |
| `ariaLabel` | TEXT | `Send order updates on WhatsApp` | `_aria-label` (used when `showLabel = false`) |
| `focusVisible` | BOOLEAN | `false` | `_focus` |
| `loading` | BOOLEAN | `false` | `Knob › _spinner` visible, knob mark hidden |

`loading` exists because every switch in the admin writes to the server on toggle (notification settings, product visibility) and an optimistic flip that silently fails is a data-integrity problem. On `loading` the switch is `aria-busy="true"`, pointer events are off, and a 16px `Spinner Tone=Neutral` renders inside the knob.

### 9.3 Geometry

| Element | Value |
|---|---|
| Row height | `HUG`, `Min height` **44** |
| Row padding | Y 0, X 0 |
| Track | 48 × 28 FIXED |
| Track radius | `radius/full` |
| Track border | 1px (off state only) |
| Knob | 24 × 24, `radius/full`, `e1` |
| Knob inset | 2px from every track edge |
| Knob travel | **20px** (48 − 2 − 2 − 24) |
| Track → Label gap | `spacing/3` (12) |
| Label | `en/body` 15 / 1.6 / 400 |
| Description | `en/body-sm` 14 / 1.5 / 400 |
| Knob spinner | 16 |
| Label position | **Left of the track** in settings lists (label `FILL`, track `HUG`, `Space between`); **right of the track** in inline opt-ins. Two documentation instances: `Switch / settings row` and `Switch / inline`. Not a variant — it is the parent's alignment, set per instance. |

### 9.4 Token bindings

| On | Disabled | Track fill | Track border | Knob fill | Knob shadow | Label |
|---|---|---|---|---|---|---|
| `False` | `False` | `color/bg/subtle` | 1px `color/border/strong` | `color/bg/surface` | `e1` | `color/text/primary` |
| `True` | `False` | `color/bg/brand` | none | `color/bg/surface` | `e1` | `color/text/primary` |
| `False` | `True` | `color/bg/subtle` | 1px `color/border/default` | `color/bg/subtle` | none | `color/text/tertiary` |
| `True` | `True` | `color/bg/subtle` | 1px `color/border/default` | `color/bg/surface` | none | `color/text/tertiary` |

Interaction: hover → `_scrim` 8% on the track; pressed → knob widens to 28 × 24 in the direction of travel (`fast` 120ms) then snaps back; focus-visible → `_focus` ring around the track, `radius/full`.

Transition: knob `translateX` 0 → 20px and track fill crossfade, both `base` 200ms `standard`. Reduced motion: no translate animation, no knob squash — the knob and fill change instantly.

**The switch state is never conveyed by position and colour alone.** Where a switch's meaning is not obvious from its label, a state word is rendered after the label in `caption` `color/text/secondary` (`On` / `Off`, `চালু` / `বন্ধ`). This is required in the admin product-visibility and coupon-active rows; it is optional elsewhere. Property: `showStateWord` BOOLEAN + `stateWord` TEXT.

### 9.5 Auto-layout

```
Switch (horizontal, FILL × HUG min 44, counter align Center, gap spacing/3, Space between)
├── TextBlock (column, FILL × HUG, gap spacing/1)
│   ├── Label (+ _stateWord inline)
│   └── Description
└── TrackWrapper (HUG × HUG, counter align Center, Clip content false)
    ├── Track (48 × 28, horizontal, padding 2, primary align Left | Right by On)
    │   └── Knob (24 × 24)
    │       └── _spinner (16)
    └── _focus
```

`Track`'s primary-axis alignment is what moves the knob (`Left` at `On=False`, `Right` at `On=True`) — not an absolute X position. This makes the Figma smart-animate transition correct for free and means the 20px travel is derived, never typed.

Counter alignment on the root is `Center`, not `Top` — unlike Checkbox and Radio. A 28px track next to a single-line label centres correctly; next to a two-line label it also centres, which reads better than top-aligning a pill-shaped control. With `showDescription = true` the root switches to `Top` with `padding-top: calc((1lh - 28px) / 2)` on `TrackWrapper`, clamped to 0.

### 9.6 Bangla

**Strategy: WRAP, unbounded.**
`TextBlock` is `FILL` and wraps; `TrackWrapper` is `HUG` at a fixed 48 × 28 and never changes. `Send order updates on WhatsApp` → `হোয়াটসঅ্যাপে অর্ডার আপডেট পাঠান` fits on one line at 390px with the 48px track and 12px gap; the longer `Notify me when this product is back in stock` → `এই পণ্যটি আবার স্টকে এলে আমাকে জানান` wraps to 2 lines. The row's min-height 44 is exceeded and the row grows. The state word, when shown, sits inline after the label and wraps with it.

### 9.7 Touch target

Track is 48 × 28 — **fails 44px vertically on its own.** Met by the row: the entire row is the `<label>` and is at least 44 tall, so the effective target is `row width × ≥44`. For a bare switch with no label (a density toggle in a toolbar), the `TrackWrapper` carries `_hit44` (§0.8) producing a 48 × 44 target. Documentation instance: `Switch / bare`.

### 9.8 Accessibility

- `<button role="switch" aria-checked>` — **not** a checkbox. A checkbox announces "checked"; a switch announces "on/off", which is what the control means. `Space` and `Enter` both toggle.
- When `showLabel = false`, `aria-label` is required and CI fails without it.
- Toggling announces through the page's `aria-live="polite"` region when the toggle has a side effect the user cannot see (`WhatsApp updates on`), not for purely visual toggles (dark mode).
- `loading` sets `aria-busy="true"` and leaves `aria-checked` at the **pre-toggle** value until the server confirms. Flipping `aria-checked` optimistically and reverting on failure announces the state twice and is wrong.

---

## 10. `QuantityStepper`

### 10.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Size` | `Medium`, `Large` | 2 |
| `State` | `Default`, `AtMin`, `AtCeiling`, `Disabled` | 4 |
| **Total** | | **8** |

`AtMin` (minus disabled, quantity = 1) and `AtCeiling` (plus disabled, quantity = available stock) are axes because they are the two states the whole component exists to communicate, and a designer must be able to place "at the stock ceiling with the reason showing" in a PDP mockup. `Disabled` is the whole-control disabled state (out of stock, order already shipped).

### 10.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `value` | TEXT | `1` | `Value › Text` |
| `ceilingReason` | TEXT | `Only 3 left in stock` | `Reason › Text` |
| `showReason` | BOOLEAN | `false` (`true` on `State=AtCeiling`) | `Reason` visibility |
| `ariaLabelMinus` | TEXT | `Decrease quantity` | `Minus › _aria-label` |
| `ariaLabelPlus` | TEXT | `Increase quantity` | `Plus › _aria-label` |
| `focusVisible` | BOOLEAN | `false` | `_focus` (on the control frame) |

### 10.3 Geometry

| Size | Control height | Minus / Plus | Value box | Total width | Radius | Border |
|---|---|---|---|---|---|---|
| `Medium` | 44 | 44 × 44 | 48 × 44 FIXED | **136** | `radius/full` | 1px |
| `Large` | 52 | 52 × 52 | 56 × 52 FIXED | **160** | `radius/full` | 1px |

| Element | Value |
|---|---|
| Icon size | 20 (`Medium`) / 24 (`Large`) — `icon/minus`, `icon/plus` |
| Icon stroke | 2px (heavier than 1.75px default; a minus glyph at 1.75px is the thinnest mark in the system) |
| Gap between the three cells | 0 (the cells abut; the shared border is the container's) |
| Internal dividers | 1px `color/border/default`, vertical, full height, between each cell |
| Value text | `en/body` 16 / 1.3 / **700**, `font-variant-numeric: tabular-nums`, centred |
| Reason row gap | `spacing/2` (8) below the control |
| Reason text | `en/caption` 12 / 1.4 / 500 |
| Reason icon | 14 (`icon/alert-circle`), gap `spacing/1` (4) |

**The value box is FIXED width, not HUG.** 48px holds `99` at 16px/700 tabular with 8px of side air, and `999` at 14px — above 99 the control hands off to a `NativeSelect` (the stated rule in §5.2). A HUG value box makes the `+` button move under the user's finger when the quantity crosses from 9 to 10, which is the single most annoying defect this component can have.

`tabular-nums` on the value is mandatory for the same reason: `1` and `9` must occupy the same advance width, or a FIXED box still shifts its centred glyph.

### 10.4 Token bindings

Container: fill `color/bg/surface`, border 1px `color/border/default`, radius `radius/full`, `Clip content = true` (so the cell hover fills follow the pill's corners).

| State | Minus cell | Value | Plus cell | Reason |
|---|---|---|---|---|
| `Default` | icon `color/text/primary` | `color/text/primary` | icon `color/text/primary` | hidden |
| `AtMin` | fill `color/bg/subtle`, icon `color/text/tertiary` | `color/text/primary` | icon `color/text/primary` | hidden |
| `AtCeiling` | icon `color/text/primary` | `color/text/primary` | fill `color/bg/subtle`, icon `color/text/tertiary` | visible, text + icon `color/status/low-stock` |
| `Disabled` | fill `color/bg/subtle`, icon `color/text/tertiary` | `color/text/tertiary` | fill `color/bg/subtle`, icon `color/text/tertiary` | visible, text + icon `color/status/out-of-stock` |

Container in `Disabled`: fill `color/bg/subtle`, border 1px `color/border/default`.

Interaction, per cell (enabled cells only): hover → cell fill `color/bg/subtle`; pressed → `_scrim` 12% on the cell + `scale(0.96)` on the icon; focus-visible → `_focus` ring around **the cell**, radius `radius/full` on the outer cells and `radius/sm` on the value cell (the value cell is focusable only in the editable variant, below).

### 10.5 The ceiling reason — exact behaviour

This is the component's reason for existing, so it is specified fully.

```html
<div class="stepper" role="group" aria-labelledby="qty-label">
  <span id="qty-label" class="sr-only">Quantity</span>

  <button type="button" aria-label="Decrease quantity"
          aria-disabled="true" aria-describedby="qty-min-reason">−</button>

  <output aria-live="polite" aria-atomic="true" id="qty-value">3</output>

  <button type="button" aria-label="Increase quantity"
          aria-disabled="true" aria-describedby="qty-ceiling">+</button>
</div>

<p id="qty-ceiling" class="stepper-reason" aria-live="polite">
  <svg aria-hidden="true" width="14" height="14">…</svg>
  Only 3 left in stock
</p>
```

Rules:

1. **`aria-disabled`, not `disabled`.** A `disabled` button is removed from the tab order, so a keyboard user tabbing through the PDP never reaches it and never hears why they cannot add more. `aria-disabled="true"` keeps it focusable and its `aria-describedby` reachable. The click handler returns early.
2. **The reason text is always in the DOM**, empty when not at a ceiling, inside a persistent `aria-live="polite"` region — same reasoning as §3.6 rule 2.
3. **The reason is specific and quantified.** `Only 3 left in stock`, never `Maximum reached`. Three distinct reasons exist and each has its own string:
   - stock ceiling — `Only 3 left in stock` / `স্টকে মাত্র ৩টি আছে`
   - per-order cap (promotional) — `Limit 2 per order on this offer` / `এই অফারে প্রতি অর্ডারে সর্বোচ্চ ২টি`
   - minimum — `Minimum 1 item` / `সর্বনিম্ন ১টি` (shown on `AtMin` only when the user actually presses `−`, not permanently; a permanent "minimum 1" is noise)
4. **The value uses `<output>` with `aria-live="polite"` and `aria-atomic="true"`** so each change announces the whole new value (`3`), not a diff.
5. **Reaching the ceiling is not an error.** The reason uses `color/status/low-stock` (warning amber), not `color/feedback/danger`, and no error border appears. The customer has done nothing wrong; the shop has run low. This distinction is why `color/status/low-stock` and `color/feedback/warning` are separate tokens even where their values coincide.
6. **Hold-to-repeat is disabled.** No long-press acceleration: at 4G latency with a server-validated stock ceiling, repeat-fire produces a quantity the server then rejects. One tap, one increment.
7. **Debounce.** Taps coalesce for 400ms before the cart mutation fires; the displayed value updates immediately (optimistic), and if the server returns a lower ceiling the value corrects and the reason announces once.

### 10.6 Editable variant

In the cart, the value cell is an editable `<input type="text" inputmode="numeric" pattern="[0-9]*">` so a customer can type `12` instead of tapping eleven times. This is **not a variant axis** — it is a separate component, `QuantityStepper/Editable`, with the same axes and geometry, where the value cell gets `State` bindings from `Input` (§3.4) and focus-visible rings the value cell at `radius/sm`. Typing above the ceiling clamps to the ceiling on blur and announces the clamp. On the PDP the non-editable version is used, because a numeric keyboard opening on a PDP shifts the viewport under the sticky add-to-cart bar.

### 10.7 Auto-layout

```
QuantityStepper (column, HUG × HUG, gap spacing/2, align Left)
├── Control (horizontal, HUG × FIXED per Size, gap 0, Clip content true, radius full, border 1px)
│   ├── Minus (FIXED 44|52 square, center/center)
│   ├── _div1 (1 × FILL)
│   ├── Value (FIXED 48|56 × FILL, center/center)
│   ├── _div2 (1 × FILL)
│   └── Plus  (FIXED 44|52 square, center/center)
└── Reason (horizontal, HUG × HUG, gap spacing/1, counter align Top, max-width 220)
```

The root is `HUG` on both axes so the component never stretches; in the cart line item it sits in a `HUG` column. `Reason` has `max-width: 220` so a two-line Bangla reason does not stretch the parent row.

### 10.8 Bangla

**Strategy: control FIXED (no text), reason WRAP (2 lines max).**
The control itself carries no words — only glyphs and digits — so it is locale-invariant at 136/160px. That is a deliberate design choice: the quantity control on the most price-sensitive page in the funnel is the same size in both languages, so the PDP layout does not need two measurements.

The reason wraps: `Only 3 left in stock` (20 chars) → `স্টকে মাত্র ৩টি বাকি আছে` (24 chars) fits one line at 220px; `Limit 2 per order on this offer` → `এই অফারে প্রতি অর্ডারে সর্বোচ্চ ২টি` wraps to 2 lines at `bn/caption` (12 × 1.4 × 1.15 = 19.3px/line = 39px block). The root is `HUG` height, so the PDP absorbs it. Two lines is the cap; longer reasons are a content defect.

**Numerals in the reason are Bengali (`৩`) while the stepper's own value stays Western (`3`).** The reason is prose and reads naturally with Bengali numerals; the value is a form field whose digits must match the keypad the user types on and the price they compare. This split is stated again in §19.6 and is the one place in the system where two numeral systems appear in one component — intentionally, with the boundary at "prose versus data".

### 10.9 Touch target

| Element | Size | Verdict |
|---|---|---|
| Minus | 44 × 44 (`Medium`), 52 × 52 (`Large`) | Passes natively |
| Plus | 44 × 44 / 52 × 52 | Passes natively |
| Value (non-editable) | not interactive | n/a |
| Value (editable) | 48 × 44 | Passes |

The two buttons are 48px apart centre-to-centre at `Medium` with the value cell between them, so their 44px targets never overlap. This is why the value box is 48 and not 32.

---

## 11. `Badge`

### 11.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Type` | `Sale`, `New`, `Bestseller`, `LowStock`, `OutOfStock`, `FreeDelivery`, `Verified` | 7 |
| **Total** | | **7** |

One axis only. No `Size` axis: a second size would double the matrix to serve a case that does not exist — every badge in this system sits either on a product card or in a product title row, both at the same scale. If a smaller badge is ever needed, it is an instance-level text-style override, documented.

### 11.2 Component properties

| Property | Type | Default (per Type) | Bound to |
|---|---|---|---|
| `label` | TEXT | see §11.4 | `Label` |
| `icon` | BOOLEAN | `true` on `Verified` and `FreeDelivery`, `false` otherwise | `Icon` visibility |
| `iconSwap` | INSTANCE_SWAP | see §11.4 | `Icon › icon` |

No `focusVisible`: **Badge is not interactive.** It is never a link, never a button, never a filter trigger. A badge that filters is a `Chip` (§12). This is enforced in review.

### 11.3 Geometry

| Element | Value |
|---|---|
| Height | **24** FIXED |
| Min-width | 40 |
| Padding X | `spacing/2` (8) |
| Padding Y | 0 (`Center`) |
| Gap (icon → label) | `spacing/1` (4) |
| Icon size | **14** |
| Icon stroke | 2px |
| Radius | `radius/full` |
| Border | 1px where the Type row specifies one, else 0; stroke align `Inside` |
| Label text | `en/caption` 12 / 1.4 / **600** (weight overridden from the token's 500 — a 12px badge at 500 reads as body text), tracking `0.01em` |
| Label case | Sentence case. **No uppercase badges.** Uppercase Latin reads as shouting next to Bangla, which has no case, so an uppercase/lowercase mix across the two builds is impossible to keep consistent. |

### 11.4 Token bindings per Type

| Type | Default label (en / bn) | Fill | Text | Border | Icon |
|---|---|---|---|---|---|
| `Sale` | `−25%` / `−২৫%` | `color/bg/brand` | `color/text/on-brand` | none | none |
| `New` | `New` / `নতুন` | `color/bg/accent` | **`color/text/on-accent`** | none | none |
| `Bestseller` | `Bestseller` / `বেস্টসেলার` | `color/bg/accent-subtle` | `color/text/primary` | none | `icon/flame`, `color/text/on-accent` |
| `LowStock` | `Only 3 left` / `মাত্র ৩টি বাকি` | `color/bg/surface` | `color/text/primary` | 1px `color/status/low-stock` | `icon/alert-circle`, `color/status/low-stock` |
| `OutOfStock` | `Out of stock` / `স্টক শেষ` | `color/bg/subtle` | `color/status/out-of-stock` | 1px `color/border/default` | none |
| `FreeDelivery` | `Free delivery` / `ফ্রি ডেলিভারি` | `color/bg/surface` | `color/text/primary` | 1px `color/status/in-stock` | `icon/truck`, `color/status/in-stock` |
| `Verified` | `Authentic` / `অরিজিনাল` | `color/bg/brand-subtle` | `color/text/primary` | none | `icon/shield-check`, `color/feedback/info` |

### 11.5 The accent rule — stated as an invariant

> **`color/bg/accent` is a surface colour only. It must never carry white text.**

`accent.500` (#F7B500) against white is **1.9:1** — it fails WCAG AA for any text size, and it fails WCAG AAA non-text contrast too. Text on `color/bg/accent` is **always** `color/text/on-accent` (neutral.900), which measures **11.2:1** and passes AAA.

Consequences enforced in this document:

- `Type=New` uses `color/bg/accent` with `color/text/on-accent`. Not `color/text/on-brand`. Not `#FFFFFF`.
- There is no accent Button (§1.4), no accent Toast, no accent Alert fill, and no accent Chip. Wherever a yellow emphasis is wanted with light text, the answer is `color/bg/brand`.
- `color/bg/accent` is permitted as a **non-text** graphic fill: the filled star in `Rating` (§18), the indicator in a campaign `ProgressBar`, a category-tile shape. Non-text graphics need 3:1 against their adjacent colour; accent.500 against `color/bg/surface` is 1.9:1, so **even as a graphic it requires a 1px `color/text/on-accent` outline or an adjacent dark element** — the Rating star therefore carries a `color/border/strong` stroke (§18.4). This is the only subtle part of the rule and it is the part most often missed.
- A Figma lint rule is added by the lead: any text layer whose parent fill is `color/bg/accent` and whose own fill is not `color/text/on-accent` is flagged.

### 11.6 Auto-layout

| Property | Value |
|---|---|
| Direction | Horizontal |
| Primary axis | `HUG` |
| Counter axis | `FIXED 24` |
| Primary alignment | `Center` |
| Counter alignment | `Center` |
| Padding | X `spacing/2`, Y 0 |
| Gap | `spacing/1` |
| `Clip content` | `true` |
| Layer order | `Icon` → `Label` |

`Label`: `HUG` × `HUG`, single line, `Truncate = off`.

### 11.7 Bangla

**Strategy: GROW on one line, never wrap, never truncate.**
A badge is a pill; a wrapped pill looks broken and a truncated one lies. The frame hugs and the parent must accommodate it. Measured worst cases at `bn/caption` 12/600:

| Type | en width | bn width |
|---|---|---|
| `Sale` `−25%` | 56 | 56 (digits are Bengali but metrically similar) |
| `New` | 48 | 52 |
| `Bestseller` | 104 | 112 |
| `LowStock` `Only 3 left` | 108 | 124 |
| `OutOfStock` | 112 | 84 |
| `FreeDelivery` | 116 | 128 |
| `Verified` | 96 | 92 |

The binding constraint is `FreeDelivery` at **128px** in Bangla. On a 2-up mobile product grid at 390px the card inner width is 171px, so a single badge always fits. **Two badges never sit on the same row of a product card**; the card shows at most one top-left badge (`Sale` ⊳ `LowStock` ⊳ `New` ⊳ `Bestseller`, first match wins) plus `FreeDelivery` on its own row below the price. That priority order is the card's rule, recorded here because it is what makes the 128px worst case safe.

Badge labels are capped at **16 Bangla characters** in the content guidelines.

### 11.8 Touch target

Not applicable — `Badge` is non-interactive and renders as a `<span>`. It has no hit area, no focus ring and no tab stop. Where a badge-looking thing must be tappable, use `Chip`.

### 11.9 Accessibility

- Renders as `<span class="badge">`, inside the element it describes, in DOM order **after** the product title so the title is announced first.
- Status badges carry no `role` — they are static text read as part of the card. They are **not** `role="status"`, which would make every card in a 24-item grid announce on load.
- `Sale` `−25%` uses U+2212 MINUS SIGN, not a hyphen-minus, and is accompanied in the accessible name by the word: the card's `aria-label` composes `25% off`. A screen reader reading `−25%` as "minus twenty five percent" is wrong, which is why the visible glyph and the accessible name differ here.
- `OutOfStock` is additionally reflected in the card's `aria-disabled` and in a `<p>` in the card body; colour and a grey pill alone do not convey it (WCAG 1.4.1).
- **Small-text contrast on the status colours.** `color/status/low-stock` (#E07C02) on white is 3.1:1 and `color/status/in-stock` (#12A150) on white is 4.0:1 — both short of the 4.5:1 required at 12px. The palette's status colours were specified for 14px+ use. Resolution, already applied in the §11.4 table: on `LowStock` and `FreeDelivery` the **label binds to `color/text/primary`** (16.1:1) and the status colour carries only the 1px border and the 14px icon, which are non-text elements needing 3:1 and both pass. No badge in this set relies on a status colour for its text.

---

## 12. `Chip`

### 12.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Removable` | `True`, `False` | 2 |
| `Selected` | `True`, `False` | 2 |
| **Total** | | **4** |

`Selected=True, Removable=False` is the filter-option chip inside the filter sheet (tap to toggle). `Selected=True, Removable=True` is the applied-filter token above the product grid. `Selected=False, Removable=False` is an unselected filter option. `Selected=False, Removable=True` is invalid and the lead marks that combination with a `_invalid` label on the board.

### 12.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `3–5 years` | `Label` |
| `count` | TEXT | `(24)` | `Count` |
| `showCount` | BOOLEAN | `false` | `Count` visibility |
| `leadingSwap` | INSTANCE_SWAP | `icon/circle` | `Leading › icon` (colour swatch, brand logo) |
| `showLeading` | BOOLEAN | `false` | `Leading` visibility |
| `removeAriaLabel` | TEXT | `Remove filter: 3–5 years` | `Remove › _aria-label` |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

### 12.3 Geometry

| Element | Value |
|---|---|
| Height | **36** FIXED |
| Min-width | 56 |
| Padding left | `spacing/3` (12) |
| Padding right — `Removable=False` | `spacing/3` (12) |
| Padding right — `Removable=True` | `spacing/1` (4) |
| Padding Y | 0 (`Center`) |
| Gap | `spacing/2` (8) |
| Radius | `radius/full` |
| Border | 1px, stroke align `Inside` |
| Label text | `en/body-sm` 14 / 1.5 / **600** |
| Count text | `en/body-sm` 14 / 1.5 / 400, `color/text/secondary` |
| Leading slot | 20 × 20 (icon) or 16 × 16 circle (colour swatch, with a 1px `color/border/default` ring) |
| Remove control | **28 × 28**, `radius/full`, icon `icon/x` at **16**, stroke 2px |
| Gap label → remove | `spacing/1` (4) |
| Total width (en, `3–5 years`, removable) | 128 |

Padding-right drops to `spacing/1` when removable so that the 28px remove control sits 4px from the pill's edge, which keeps the pill at 36px tall without the control touching the border. 12px + label + 8px + 28px + 4px.

### 12.4 Token bindings

| Selected | State | Fill | Text | Border | Remove icon |
|---|---|---|---|---|---|
| `False` | Default | `color/bg/surface` | `color/text/primary` | 1px `color/border/default` | n/a |
| `False` | Hover | `color/bg/subtle` | `color/text/primary` | 1px `color/border/strong` | n/a |
| `False` | Pressed | `color/bg/subtle` + `_scrim` 8% | `color/text/primary` | 1px `color/border/strong` | n/a |
| `False` | Focus-visible | `color/bg/surface` | `color/text/primary` | 1px `color/border/default` + `_focus` | n/a |
| `False` | Disabled | `color/bg/subtle` | `color/text/tertiary` | 1px `color/border/default` | n/a |
| `True` | Default | `color/bg/brand-subtle` | `color/text/primary` | 1px `color/bg/brand` | `color/text/secondary` |
| `True` | Hover | `color/bg/brand-subtle` + `_scrim` 8% | `color/text/primary` | 1px `color/bg/brand` | `color/text/primary` |
| `True` | Pressed | `color/bg/brand-subtle` + `_scrim` 12% | `color/text/primary` | 1px `color/bg/brand` | `color/text/primary` |
| `True` | Focus-visible | `color/bg/brand-subtle` | `color/text/primary` | 1px `color/bg/brand` + `_focus` | `color/text/secondary` |

A selected chip is `color/bg/brand-subtle` with a `color/bg/brand` border — tint plus outline, so that selection survives both a greyscale print and a red-green colour deficiency. Selection is **not** conveyed by fill alone.

Remove-control states (nested, independent of the chip's own hover):

| State | Fill | Icon |
|---|---|---|
| Default | `None` | `color/text/secondary` |
| Hover | `color/bg/brand-subtle` darkened via `_scrim` 12% | `color/text/primary` |
| Pressed | `_scrim` 16% | `color/text/primary` |
| Focus-visible | `None` + `_focus` at `radius/full` | `color/text/primary` |

### 12.5 The remove affordance — specified in full

The removable chip contains **two independent controls**, which is the detail that makes or breaks it:

```html
<span class="chip chip--selected">
  <span class="chip__label" id="chip-age-35">3–5 years</span>
  <button type="button"
          class="chip__remove hit44"
          aria-label="Remove filter: 3–5 years">
    <svg aria-hidden="true" width="16" height="16">…</svg>
  </button>
</span>
```

Rules:

1. **Desktop and tablet (≥768px): the label is not clickable; only the X removes.** A chip whose entire body removes the filter is a destructive action with a 128×36px target — it is hit by accident while scrolling a filter row.
2. **Mobile (<768px): the whole chip is the remove button.** Inverted deliberately. At 390px the applied-filter row is horizontally scrolled with a thumb, and a 28px X inside a scrolling row is a target the thumb misses; the miss then scrolls instead of removing, which reads as the control being broken. So on mobile the markup collapses to a single `<button class="chip">` with `aria-label="Remove filter: 3–5 years"`, the X becomes a decorative `<svg aria-hidden="true">`, and the whole 128×36 pill is the target. The two markups are produced by one component with a media query, not by two components.
3. **The X's hit area is 44×44 on every viewport** where it is a separate control. The visual control is 28×28; `_hit44` (§0.8) centres a 44×44 hit area on it. Because padding-right is only 4px, that hit area overhangs the pill's right edge by 6px and overlaps the label by 8px — acceptable, because on desktop the label is not a control, so there is nothing to steal a tap from. The next chip in the row is `spacing/2` (8) away, and the 44px areas of two adjacent chips' X controls are 136px apart centre-to-centre, so they never overlap.
4. **Removal is announced.** The filter result region (`aria-live="polite"`, owned by `FilterPanel`) announces `Filter removed. 128 products.` — one message carrying both the action and its consequence. The chip itself does not announce; a removed element cannot.
5. **Focus after removal** moves to the next chip in the row; if the removed chip was last, to the previous one; if it was the only one, to the `Clear all` control, or to the filter trigger button if that is also gone. Focus is never dropped to `<body>` — that sends a keyboard user back to the top of the page on every filter removal.
6. **No animation on removal** beyond a 120ms opacity fade. No shrink, no fly-out, no spring. The row re-flows immediately; a 320ms collapse makes the grid below jump twice.
7. **`Clear all`** sits at the end of the chip row as a `Button Variant=Ghost Size=Small` with `label = Clear all`. It is announced as `Clear all 4 filters`.

### 12.6 Auto-layout

```
Chip (horizontal, HUG × FIXED 36, center/center, padding L spacing/3 / R spacing/1, gap spacing/2, Clip content false)
├── _scrim
├── Leading  (20 × 20 FIXED)
├── Label    (HUG × HUG, single line, Truncate off)
├── Count    (HUG × HUG)
├── Remove   (28 × 28 FIXED, center/center, Clip content false)
│   ├── _scrim
│   ├── icon (16)
│   ├── _hit44 (44 × 44, Ignore auto-layout, centred)
│   └── _focus
└── _focus
```

`Count` sits after `Label` with the standard `spacing/2` gap — it is a separate layer rather than part of the label string so that it can be hidden, restyled and excluded from the accessible name independently.

### 12.7 Bangla

**Strategy: GROW on one line; never wrap, never truncate; row SCROLLS.**
A wrapped chip is indistinguishable from two chips. So:

- The chip hugs its label at 36px tall regardless of length. `3–5 years` (128px) → `৩–৫ বছর` (116px); `Free delivery` (156px) → `ফ্রি ডেলিভারি` (168px); worst case in the filter vocabulary is `Educational & STEM` (196px) → `শিক্ষামূলক ও স্টেম` (184px).
- The **applied-filter row** above the product grid is a horizontal scroll container: `overflow-x: auto`, `scroll-snap-type: x proximity`, 16px gutter padding on both ends, a 24px right-edge fade mask, `-webkit-overflow-scrolling: touch`, and `scrollbar-width: none`. It never wraps to a second line on mobile (a two-line filter row costs 44px of the product grid's above-the-fold space). On desktop (≥1024px) it wraps freely with row gap `spacing/2`.
- The **filter-option list** inside `FilterPanel` wraps freely at every viewport — it is a wrap container with gap `spacing/2`, not a scroll row.
- `Label` has `white-space: nowrap`. This is the one component where nowrap is correct.

### 12.8 Touch target

| Element | Visual | Hit area | Verdict |
|---|---|---|---|
| Chip (mobile, whole-chip remove) | ≥56 × 36 | 56 × 44 via `_hit44` on the chip frame | Passes |
| Chip (desktop, toggle) | ≥56 × 36 | 56 × 44 | Passes (pointer device; 44 is still met) |
| Remove X (desktop) | 28 × 28 | 44 × 44 via `_hit44` | Passes |

The chip's own 36px height is extended to 44 by `_hit44` on the chip frame, which does not change layout because the chip row has `spacing/2` (8) of gap — 36 + 8 = 44 exactly, so the hit areas tile without overlapping.

---

## 13. `Avatar`

### 13.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Size` | `XS`, `Small`, `Medium`, `Large`, `XL` | 5 |
| `Type` | `Image`, `Initials`, `Icon` | 3 |
| **Total** | | **15** |

### 13.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `imageSwap` | INSTANCE_SWAP | `placeholder/avatar` | `Image` fill (an image-fill frame) |
| `initials` | TEXT | `MR` | `Initials` |
| `iconSwap` | INSTANCE_SWAP | `icon/user` | `Icon › icon` |
| `showStatus` | BOOLEAN | `false` | `Status` visibility |
| `altText` | TEXT | `Mominul Rahman` | `_aria-label` |

### 13.3 Geometry

| Size | Frame | Initials text | Icon | Status dot | Status ring |
|---|---|---|---|---|---|
| `XS` | 24 × 24 | 10 / 700 | 12 | 8 | 1.5px |
| `Small` | 32 × 32 | 12 / 700 | 16 | 10 | 2px |
| `Medium` | 40 × 40 | 14 / 700 | 20 | 12 | 2px |
| `Large` | 56 × 56 | 20 / 700 | 28 | 14 | 2px |
| `XL` | 80 × 80 | 28 / 700 | 40 | 16 | 2.5px |

| Element | Value |
|---|---|
| Radius | `radius/full` (all sizes, all types) |
| Border | 1px `color/border/default`, stroke align `Inside`, on `Type=Image` only |
| Padding | 0 |
| Status dot position | bottom-right, offset so the dot's centre sits on the circle at 45°; implemented as `Ignore auto-layout` with right/bottom = `-2px` at `XS`/`Small`, `0` at `Medium`+ |
| Status ring | a `color/bg/surface` ring around the status dot at the stated width, so the dot reads against a photograph |

The 1px border on `Type=Image` exists because customer-supplied avatars are frequently white-background screenshots, and a white circle on `color/bg/surface` is invisible. Initials and Icon types have a tinted fill and need no border.

### 13.4 Token bindings

| Type | Fill | Content colour | Border |
|---|---|---|---|
| `Image` | the image | — | 1px `color/border/default` |
| `Initials` | `color/bg/brand-subtle` | `color/text/primary` | none |
| `Icon` | `color/bg/subtle` | `color/text/secondary` | none |

Status dot: `color/status/in-stock` (online), `color/text/tertiary` (offline), `color/feedback/warning` (away). The dot is never the only signal — it is paired with text in the one place it is used (admin user list), and on the storefront `showStatus` is always `false`.

`Avatar` has no hover, pressed, focus or disabled states of its own. When an avatar is a button (the account menu trigger), it is wrapped in an `IconButton Variant=Ghost Size=Medium` whose `icon` slot holds the `Avatar Size=Small` instance, and that wrapper owns all the interaction states. **The avatar itself is never interactive**, which keeps 15 combinations from becoming 60.

### 13.5 Auto-layout

Horizontal, `FIXED` × `FIXED` per Size, both alignments `Center`, padding 0, gap 0, `Clip content = true` (so an image fill is masked to the circle).
Layer order: `Image` | `Initials` | `Icon` (mutually exclusive by Type) → `Status` (absolute) → `_aria-label`.

### 13.6 Bangla

**Strategy: n/a for the frame; initials require a rule.**
Bangla has no case, so `Initials` cannot be produced by uppercasing. The rule:

- If the name is in Latin script, take the first letter of the first and last words, uppercased: `Mominul Rahman` → `MR`.
- If the name is in Bangla script, take the **first grapheme cluster of the first word only** — one character, not two. `মমিনুল রহমান` → `ম`. Two Bangla graphemes side by side at 14px are illegible in a 40px circle, and conjuncts (যুক্তাক্ষর) must not be split: the implementation uses `Intl.Segmenter` with `granularity: 'grapheme'`, never `string[0]` (which would split a surrogate pair or a conjunct and render a broken glyph).
- The Bangla initial is rendered at **+2px** over the table's size (16 instead of 14 at `Medium`) because a single Anek Bangla character is optically smaller than two Jakarta capitals. The `.lang-bn` build applies this; the Figma documentation board shows both.
- If the name is empty or unparseable, fall back to `Type=Icon`, never to a blank circle.

### 13.7 Touch target

Not applicable — `Avatar` is non-interactive. When wrapped in `IconButton Size=Medium` (44 × 44), that wrapper meets the target; `Avatar Size=XS` and `Small` are therefore the only sizes used inside a button, and `Size=XS` is restricted to admin table rows.

### 13.8 Accessibility

- `Type=Image` renders `<img alt="{altText}">`. The alt is the person's name, not `"avatar"` or `"profile picture"`.
- `Type=Initials` and `Type=Icon` render `<span role="img" aria-label="{altText}">`; the initials themselves are `aria-hidden` so a screen reader says `Mominul Rahman`, not `M R`.
- When the avatar is purely decorative beside the person's name in text (the review card), it is `alt=""` / `aria-hidden="true"` and the name is read from the adjacent text — not twice.
- A broken image URL must not leave an empty circle: `onError` switches to `Type=Initials`. This is a code requirement, recorded here because it is the component's most common production failure.

---

## 14. `Spinner`

### 14.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Size` | `Small`, `Medium`, `Large`, `XL` | 4 |
| `Tone` | `OnBrand`, `Brand`, `Neutral` | 3 |
| **Total** | | **12** |

`Tone` is an axis rather than inherited `currentColor` because Figma has no `currentColor`: a Spinner instance nested in a Primary Button must be explicitly white, and the lead needs to pick that from the instance panel. In code it **is** `currentColor` and the `Tone` axis maps to nothing — Code Connect maps all three Figma tones to the single React component, with a comment. This is a documented design/code asymmetry, the only one in this document.

### 14.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `ariaLabel` | TEXT | `Loading` | `_aria-label` |

### 14.3 Geometry

| Size | Frame | Stroke width | Arc sweep | Used in |
|---|---|---|---|---|
| `Small` | 16 × 16 | 2 | 270° | Button `Size=Small`, Switch knob, inline text |
| `Medium` | 20 × 20 | 2 | 270° | Button `Size=Medium`, IconButton `Medium` |
| `Large` | 24 × 24 | 2.5 | 270° | Button `Size=Large`, inline section loader |
| `XL` | 32 × 32 | 3 | 270° | Full-panel loader, route transition |

| Element | Value |
|---|---|
| Radius | `radius/full` |
| Track | a full 360° ring at the stroke width, same colour as the indicator at **20% opacity** |
| Indicator | a 270° arc, `stroke-linecap: round`, starting at 12 o'clock going clockwise |
| Padding | 0 |
| Construction in Figma | two concentric ellipses with centre-aligned strokes; the indicator is an arc (ellipse with `Sweep = 75%`, `Start = 0%`) — not a masked rectangle, which does not rotate cleanly |

The 270° sweep (rather than 90° or 320°) is chosen so that the spinner reads as a ring that is moving rather than as a dot chasing its tail, at the 16px size where most of them appear.

### 14.4 Token bindings

| Tone | Indicator | Track | Used on |
|---|---|---|---|
| `OnBrand` | `color/text/on-brand` | `color/text/on-brand` @ 20% | `color/bg/brand`, `color/feedback/danger` fills |
| `Brand` | `color/bg/brand` | `color/bg/brand` @ 20% | `color/bg/surface`, `color/bg/canvas` |
| `Neutral` | `color/text/secondary` | `color/text/secondary` @ 20% | Secondary/Outline/Ghost buttons, Switch knob, admin surfaces |

No `Tone=Accent`. `color/bg/accent` at 1.9:1 against white cannot be seen as a 2px stroke (§11.5).

### 14.5 Animation

```css
.spinner { animation: spin 800ms linear infinite; transform-origin: 50% 50%; }
@keyframes spin { to { transform: rotate(360deg); } }
```

- **800ms, linear, infinite.** Linear and not eased: an eased rotation reads as stuttering, and a spinner that appears to hesitate on a 4G connection makes the page feel slower than it is.
- The arc never changes length. No dash-offset pulsing, no grow-shrink — one moving part.
- `will-change: transform` is **not** set. On mid-range Android a promoted layer per spinner costs more than it saves, and there can be 24 skeleton spinners on a category page.

**Reduced motion:**

```css
@media (prefers-reduced-motion: reduce) {
  .spinner { animation-duration: 1600ms; }
}
```

The rotation is **slowed to 1600ms, not removed.** An indeterminate loading indicator is one of the explicit exceptions in the reduced-motion guidance: removing all motion leaves a static ring that conveys nothing, and the alternative (a text-only "Loading…") is not available inside a 20px button slot. 1600ms is below the threshold at which rotation triggers vestibular discomfort for a 16–32px element, and no scale, opacity or position animation is applied.

### 14.6 Auto-layout

`FIXED` × `FIXED` per Size, both alignments `Center`, padding 0, gap 0, `Clip content = false`.
Layer order: `_track` → `_indicator` → `_aria-label`.

### 14.7 Bangla

No visible text. `ariaLabel` is translated (`লোড হচ্ছে`). A spinner is never accompanied by a visible "Loading…" label in this system — the skeleton (§15) is the component for that case, because a text label next to a spinner has to be translated, re-measured and re-laid-out, and conveys nothing the skeleton does not.

### 14.8 Touch target

Not applicable — non-interactive, no tab stop. A spinner is never a cancel affordance; a cancellable operation pairs the spinner with a separate `Button Variant=Ghost` labelled `Cancel`.

### 14.9 Accessibility

- Renders as `<span role="status" aria-label="Loading"><svg aria-hidden="true">…</svg></span>`.
- Inside a Button, the Button carries `aria-busy="true"` and the spinner is `aria-hidden="true"` — otherwise the button announces its label and "Loading" as two separate things on every re-render.
- A spinner that replaces page content is accompanied by a visually-hidden `role="status"` message naming what is loading (`Loading product reviews`), announced once.
- Operations expected to exceed 10 seconds (bulk courier booking, CSV export) do not use `Spinner` — they use `ProgressBar Variant=Indeterminate` (§31) with a status line, because an indefinite spinner past ~10s reads as a hang.

---

## 15. `Skeleton`

### 15.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Shape` | `Line`, `Block`, `Circle` | 3 |
| **Total** | | **3** |

Size is **not** an axis. A skeleton's dimensions are always set by its instance (`FILL` width, and a height set by the consuming composite), because a skeleton's only job is to be the exact shape of the thing that will replace it. Baking sizes into variants guarantees they drift from the real content.

### 15.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `animate` | BOOLEAN | `true` | `_shimmer` visibility |

No text, no icons, no aria. `animate = false` is used for the ≥7th row in a long skeleton list (see §15.6) and for the reduced-motion documentation instance.

### 15.3 Geometry

| Shape | Default size | Resizing | Radius |
|---|---|---|---|
| `Line` | 120 × **16** | `FILL` × `FIXED` | `radius/sm` (6) |
| `Block` | 160 × 160 | `FILL` × `FILL` | `radius/lg` (14) |
| `Circle` | 40 × 40 | `FIXED` × `FIXED` | `radius/full` |

`Line` heights used by the composites, stated here so they are consistent: **12** for `caption`, **16** for `body-sm`/`body`, **20** for `h3`/`price`, **28** for `price-lg`/`h1`. These are line-box heights rounded down, not the font sizes — a skeleton line matching the font size is visibly too thin.

`Block` radius is `radius/lg` to match `ProductCard`'s image box exactly (the source spec sets product cards and images to `lg`). A skeleton whose radius differs from its replacement produces a visible snap at the swap.

### 15.4 Token bindings

| Layer | Fill |
|---|---|
| Base | `color/bg/subtle` |
| `_shimmer` | a 3-stop linear gradient: `color/bg/subtle` 0% → `color/bg/surface` 50% → `color/bg/subtle` 100%, at **100°** |

In Figma, `_shimmer` is a child rectangle with `Ignore auto-layout` on, width **200%** of the parent, height 100%, the gradient above, layer opacity **100%**, and the parent's `Clip content = true`. Its X position in the static (documentation) state is `-50%`, which is the mid-sweep frame — that is the frame a reviewer should see on the board.

**The middle stop binds to `color/bg/surface-raised`, not `color/bg/surface`.** In light mode `surface-raised` is #FFFFFF, identical to `surface`, so the light-mode sweep is unaffected. In dark mode `color/bg/subtle` and `color/bg/surface` are both neutral.800 — a gradient between them is invisible — while `surface-raised` is neutral.700 and gives a visible lift. One binding, correct in both modes, with no mode-specific override to maintain.

### 15.5 Shimmer animation — exact spec

```css
.skeleton {
  position: relative;
  overflow: hidden;
  background: var(--color-bg-subtle);
}
.skeleton::after {
  content: "";
  position: absolute;
  inset: 0;
  transform: translateX(-100%);
  background: linear-gradient(
    100deg,
    var(--color-bg-subtle) 0%,
    var(--color-bg-surface-raised) 50%,
    var(--color-bg-subtle) 100%
  );
  animation: shimmer 1400ms cubic-bezier(.4, 0, .2, 1) infinite;
}
@keyframes shimmer { 100% { transform: translateX(100%); } }
```

| Parameter | Value | Why this value |
|---|---|---|
| Duration | **1400ms** | Slower than a spinner (800ms) because a skeleton covers a large area; a fast sweep across a full product grid reads as flicker. |
| Easing | `cubic-bezier(.4, 0, .2, 1)` | Slight ease-in-out so the sweep decelerates at the edges rather than hard-cutting at the clip boundary. |
| Gradient angle | **100°** | Not 90°. A 10° tilt makes the sweep read as a light source crossing the surface rather than a bar sliding sideways, and it is what distinguishes this from a progress bar. |
| Travel | `-100%` → `100%` | The gradient element is the full size of the skeleton and travels two widths, so there is a brief gap between sweeps. That gap is intentional: a continuous sweep has no rhythm. |
| Delay | **0ms, and no stagger** | Explicitly: skeleton rows do **not** have staggered animation delays. A staggered grid of 24 skeletons produces 24 independent animation timelines and a visible diagonal wave, which on a mid-range Android costs measurable frames and looks like a loading bug. All skeletons on a page sweep in unison. |
| Composited properties only | `transform` | No `background-position` animation (it is not compositable and repaints on every frame). |

**Reduced-motion fallback:**

```css
@media (prefers-reduced-motion: reduce) {
  .skeleton::after { display: none; }
  .skeleton { background: var(--color-bg-subtle); }
}
```

The shimmer is **removed entirely** — not slowed. This is the opposite of the Spinner decision (§14.5) and the difference is deliberate:

- A spinner's motion is its entire information content; removing it leaves nothing.
- A skeleton's information content is its **shape** — the grey blocks already say "content of this size is coming". The shimmer only says "still working", which the `role="status"` live region says in words. So the motion is redundant and can go.
- A large-area horizontal sweep is also the exact motion pattern most likely to trigger vestibular symptoms; at 1400ms across a full-width product grid it is far more provocative than a 20px rotation.

The static fallback is a flat `color/bg/subtle` fill. It must not be mistaken for a disabled control: skeletons only appear where content is absent, and the live region names what is loading.

### 15.6 Composition rules

These belong to the primitive because they are what makes skeletons not look cheap:

1. **A skeleton matches its replacement's geometry exactly** — same width, same height, same radius, same position. The `ProductCardSkeleton` (Tier 2) is built by duplicating `ProductCard` and swapping each content layer for a `Skeleton`, not by eyeballing grey boxes.
2. **Multi-line text skeletons:** the last `Line` in a block is **60% width**, every other line is 100%. A block of equal-length lines reads as a table.
3. **Line gap** inside a text block is `spacing/2` (8), not the text's line-height — skeleton lines are 16px against a 24px line-box, so the 8px gap reproduces the 24px rhythm.
4. **Never more than 6 animated skeleton rows** are rendered at once. Rows 7+ use `animate = false` (flat fill). On a 24-item category grid this means the 6 above-the-fold cards shimmer and the rest are flat — indistinguishable to the user, and it cuts the compositor work by 75% on the target device.
5. **Minimum display time 300ms.** A skeleton that appears and vanishes inside 200ms is a flash of grey that reads as a rendering fault. If data arrives sooner, the skeleton is not shown at all (delay its mount by 150ms); if it is already shown, it stays 300ms.
6. **No skeleton for a price.** `Price` (§19) is server-rendered and never has a loading state — see §19.7. A shimmering price is the defect that component exists to prevent.

### 15.7 Auto-layout

`Line`: Horizontal, `FILL` × `FIXED` (height per §15.3), padding 0, gap 0, `Clip content = true`.
`Block`: Horizontal, `FILL` × `FILL`, `Clip content = true`.
`Circle`: Horizontal, `FIXED` × `FIXED`, `Clip content = true`.
Layer order in all three: base frame → `_shimmer` (absolute).

### 15.8 Bangla

No text, so no string behaviour. **But line counts differ**: a two-line Latin product title becomes a two- or three-line Bangla title, and `bn` line-boxes are 1.15× taller. The `ProductCardSkeleton` therefore reserves the **Bangla** title height in both builds — `2 × 16 + 8 = 40` in Latin, and the same 40 in Bangla because the skeleton line height is fixed at 16 regardless of script. The real title in Bangla occupies `2 × 27.6 = 55px`. **That 15px difference is a CLS source**, so the rule is: `ProductCard`'s title box has a fixed min-height of **56px** in both builds and both scripts, and the skeleton fills it with 2 × 16px lines plus 8px gap, vertically top-aligned, leaving 16px of reserved space. This is recorded here because the skeleton is where the measurement is taken.

### 15.9 Touch target

Not applicable — non-interactive, `aria-hidden`, no tab stop, `pointer-events: none` so a tap passes through to whatever is beneath (nothing).

### 15.10 Accessibility

```html
<div class="skeleton-group" aria-hidden="true">…</div>
<p role="status" class="sr-only">Loading products</p>
```

- The skeleton shapes are `aria-hidden="true"` — they are decorative placeholders and announcing 40 grey boxes is noise.
- A single sibling `role="status"` visually-hidden element names what is loading, announced once on mount.
- When content arrives, the status element's text is cleared and a second message announces the result (`24 products loaded`) — this is the same live region the filter count uses, so a filter + load sequence produces one announcement, not two.

---

## 16. `Divider`

### 16.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Orientation` | `Horizontal`, `Vertical` | 2 |
| `Emphasis` | `Default`, `Strong` | 2 |
| **Total** | | **4** |

### 16.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `or` | `Label` |
| `showLabel` | BOOLEAN | `false` | `Label` + the second `_rule` visibility |

`showLabel` is only valid on `Orientation=Horizontal`; the lead marks the `Vertical + label` cell `_invalid` on the board.

### 16.3 Geometry

| Orientation | Thickness | Length | Radius |
|---|---|---|---|
| `Horizontal` | **1** (height, FIXED) | `FILL` width | 0 |
| `Vertical` | **1** (width, FIXED) | `FILL` height, `Min height` 16 | 0 |

With a label:

| Element | Value |
|---|---|
| Row height | `HUG`, `Min height` 20 |
| Gap (rule → label → rule) | `spacing/3` (12) |
| Label text | `en/caption` 12 / 1.4 / 500 |
| Rule segments | two, each `FILL` width, 1px tall, vertically centred |

Radius is 0, not `radius/full`. A 1px line with a 9999px radius renders identically but costs a corner computation per divider, and there can be 60 of them in a DataTable.

**Spacing is not the divider's responsibility.** A `Divider` has no margin. The parent stack's gap positions it — a divider with built-in margin cannot be used in a `gap`-based auto-layout without double spacing. Standard surrounding gaps for reference: `spacing/4` (16) in a card, `spacing/3` (12) in a list, `spacing/6` (24) between page sections, `0` in a DataTable (the row's own padding provides the air).

### 16.4 Token bindings

| Emphasis | Fill |
|---|---|
| `Default` | `color/border/default` |
| `Strong` | `color/border/strong` |

Label colour: `color/text/tertiary`. Rules either side of a label use the `Emphasis` fill.

No states. `Divider` is non-interactive and has no hover, focus or disabled appearance. In dark mode the tokens remap and nothing else changes; note that `e1`/`e2` elevations substitute a 1px `color/border/default` border in dark mode (source spec §3.3), so dark-mode cards gain an outline that reads as a divider — the lead checks that a card with both a dark-mode border and an internal divider does not produce two adjacent 1px lines at the card's edge. Rule: a divider is never the first or last child of a card.

### 16.5 Auto-layout

`Horizontal`, no label: a frame, `FILL` × `FIXED 1`, fill = the Emphasis token. No auto-layout needed; it is a rectangle.
`Vertical`, no label: `FIXED 1` × `FILL`, `Min height` 16.
`Horizontal`, with label: Horizontal auto-layout, `FILL` × `HUG` (`Min height` 20), counter alignment `Center`, gap `spacing/3`, padding 0. Children: `_rule` (`FILL` × 1) → `Label` (`HUG`) → `_rule` (`FILL` × 1).

### 16.6 Bangla

**Strategy: GROW; the rules absorb the change.**
The label hugs and the two `FILL` rule segments shrink symmetrically. `or` (14px wide) → `অথবা` (34px) costs 20px total, split 10px from each rule. At the narrowest place a labelled divider appears — the 358px-wide login card at 390px — each rule is still 155px. The label never wraps (`white-space: nowrap`); if a labelled divider's text were ever long enough to wrap, that is a section heading, not a divider.

### 16.7 Touch target

Not applicable — non-interactive. A `Divider` is never a drag handle; the admin `DataTable` column resizer is its own control with a 12px visual and a 44px hit area, and it is not this component.

### 16.8 Accessibility

- Decorative dividers render as `<div role="presentation">` or a CSS `border-top` on the following element — **not** `<hr>`. An `<hr>` is announced as a thematic break, and 60 of them in a table is 60 announcements.
- A divider that **does** mark a thematic break (between the product description and the reviews section) renders as `<hr>` with no `role` override. There are four such places on the storefront; everywhere else is decorative.
- A labelled divider (`or`, between the OTP login and the email login) renders as `<div role="separator" aria-orientation="horizontal">` with the label as its accessible name, so a screen reader announces `separator, or`. The label is **not** `aria-hidden`: it carries meaning (these are alternatives, not a sequence).
- Vertical dividers between inline metadata (`4.3 ★ · 128 reviews · In stock`) are CSS pseudo-elements, never DOM nodes, so the metadata reads as one phrase.

---

## 17. `Tooltip`

### 17.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Side` | `Top`, `Bottom`, `Left`, `Right` | 4 |
| **Total** | | **4** |

No `Align` axis (unlike `Popover`, §28): a tooltip is narrow, centred on its trigger, and collision-resolved by flipping `Side`. Adding `Align` would be 12 combinations for a control that never needs edge alignment.

### 17.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `content` | TEXT | `Delivery charge is calculated by district` | `Content` |
| `showArrow` | BOOLEAN | `true` | `Arrow` visibility |

### 17.3 Geometry

| Element | Value |
|---|---|
| Height | `HUG`, `Min height` 32 |
| Min-width | 80 |
| **Max-width** | **240** |
| Padding X | `spacing/3` (12) |
| Padding Y | `spacing/2` (8) |
| Radius | `radius/md` (10) |
| Border | 1px |
| Elevation | `e3` |
| Content text | `en/body-sm` 14 / 1.5 / 400 |
| Arrow | **12 wide × 6 deep** triangle, same fill as the body, with the body's 1px border on its two outer edges |
| Offset from trigger | `spacing/2` (8), measured trigger edge → tooltip edge (the arrow sits inside that 8px) |
| Viewport inset on collision | 16 |

`radius/md` (10), not `radius/lg` or `radius/2xl`. A tooltip is an explanatory utility; the playful radii belong to cards and buttons.

### 17.4 Token bindings

| Element | Binding |
|---|---|
| Fill | `color/bg/surface-raised` |
| Text | `color/text/primary` |
| Border | 1px `color/border/default` |
| Shadow | `e3` |
| Arrow fill | `color/bg/surface-raised` |
| Arrow border | 1px `color/border/default` on the two outer edges |

**A light tooltip, not a dark one.** There is no inverse-surface token in this system, and inverting by binding `fill` to `color/text/primary` and `text` to `color/bg/surface` would create a component whose dark-mode appearance is a near-white box with dark text — exactly backwards. A light tooltip with a border and `e3` is unambiguous in both modes with one binding.

No state axis: the tooltip has no hover, pressed, focus or disabled appearance. It is either shown or not. The **trigger** owns the states.

### 17.5 Behaviour

| Parameter | Value |
|---|---|
| Open delay (pointer) | **300ms** |
| Close delay (pointer) | **100ms** |
| Open delay (keyboard focus) | **0ms** |
| Enter animation | opacity 0→1 + 4px translate toward the trigger, `fast` 120ms `decelerate` |
| Exit animation | opacity 1→0, `fast` 120ms |
| Reduced motion | opacity only, 120ms; no translate |
| Dismiss | `Esc` (always, per WCAG 1.4.13), pointer leaving the trigger **or** the tooltip, trigger blur, scroll |
| Hoverable content | yes — the pointer may move from trigger into the tooltip without closing (the 100ms close delay covers the 8px gap). Required by WCAG 1.4.13 "Content on Hover or Focus". |
| Collision | flip `Side` to its opposite; if both fail, pick the side with the most room and clamp to a 16px viewport inset |
| Portal | rendered at the document root, above the sticky `AddToCartBar` and `MobileNav`; inside a `Drawer` or `Modal`, portalled into that container |
| Singleton | only one tooltip is open at a time, globally |

### 17.6 Touch — the critical rule

> **A tooltip never appears on a touch device.** `@media (hover: none)`: the Tooltip component does not mount.

There is no hover on a phone, and the common workarounds are all worse: long-press competes with text selection and the context menu; tap-to-show steals the trigger's own action; tap-anywhere-to-dismiss is undiscoverable. Since the target audience is predominantly mid-range Android (source spec §2), a touch-only tooltip would mean the information is invisible to most of the audience.

So every piece of information that would be in a tooltip has a touch answer, decided per instance at design time:

| Case | Touch treatment |
|---|---|
| Information a buyer needs to decide (delivery charge basis, what "authentic" means, return window) | **Moved inline** as a `Helper` line or a `body-sm` `color/text/secondary` line. Not a tooltip at all, on any viewport. |
| Secondary explanation tied to a control (why a variant is unavailable, what a risk score means) | The trigger becomes a `Popover` (§28) on `(hover: none)` — same content, tap to open, tap-outside or `Esc` to close, focus managed. One content string, two presentations. |
| Admin-only abbreviation or column help | Tooltip on desktop; no touch equivalent needed (admin is desktop/tablet). |

`Tooltip` is therefore an **admin-and-desktop component**. On the storefront it appears in exactly two places (the PDP delivery-estimator info icon and the risk-free-returns icon), and both have the Popover fallback.

### 17.7 Auto-layout

Horizontal, `HUG` × `HUG` (`Min height` 32, `Max width` 240), both alignments `Center`, padding X `spacing/3` / Y `spacing/2`, gap 0, `Clip content = false`.
`Content`: `FILL` × `HUG`, wraps, `Align = Left` (not `Center` — centred multi-line text is harder to read, and a 240px tooltip is frequently three lines in Bangla).
`Arrow`: `Ignore auto-layout`, anchored to the side opposite `Side`, centred on the long axis.

### 17.8 Bangla

**Strategy: WRAP, up to 4 lines at 240px.**
`Delivery charge is calculated by district` (41 chars, 2 lines at 240px in Latin) → `ডেলিভারি চার্জ জেলা অনুযায়ী নির্ধারিত হয়` (40 chars) wraps to **3 lines** at `bn/body-sm` (14 × 1.5 × 1.15 = 24.2px/line), giving a 73px content block and a 89px tooltip. Max-width stays 240 in both scripts — widening it for Bangla would make the Latin build inconsistent and a 300px tooltip overhangs a 390px viewport's safe area.

4 lines (97px of text) is the cap. Beyond that the content is not tooltip content: it moves inline or into a `Popover`. Tooltip content is capped at **90 Bangla characters** in the content guidelines.

### 17.9 Touch target

The tooltip itself is not a target (it is not interactive and does not exist on touch). The **trigger** must meet 44×44: an info-icon trigger is `IconButton Size=Medium Variant=Ghost` with a 16px `icon/info` — a 44×44 button with a small glyph, not a 16px clickable icon. On desktop the trigger is still a real `<button>` so it is reachable by keyboard, which is how the tooltip opens for keyboard users at 0ms delay.

### 17.10 Accessibility

```html
<button type="button" aria-describedby="tt-delivery" class="icon-button">
  <svg aria-hidden="true" width="16" height="16">…</svg>
  <span class="sr-only">About delivery charges</span>
</button>
<div id="tt-delivery" role="tooltip">Delivery charge is calculated by district</div>
```

- `aria-describedby`, not `aria-labelledby` — the tooltip supplements the trigger's name, it does not replace it. A trigger whose only name is its tooltip is unusable when the tooltip is closed.
- `role="tooltip"` on the bubble. It stays in the DOM and is toggled with `hidden`, so the `aria-describedby` reference is never dangling.
- `Esc` dismisses without moving focus (WCAG 1.4.13 Dismissible).
- The tooltip contains **no interactive content** — no links, no buttons, no form fields. Content requiring interaction is a `Popover`. This is what keeps the tooltip out of the tab order entirely.
- The tooltip is never the only place information appears. Everything in a tooltip is also available in the page's static content, in the FAQ, or on the relevant form field as a `Helper`. A tooltip is an accelerator, not a container.

---

## 18. `Rating`

### 18.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Mode` | `Display`, `Interactive` | 2 |
| `Size` | `Small`, `Medium`, `Large` | 3 |
| **Total** | | **6** |

`Value` is **not** an axis — it is driven by a component property and a mask width (§18.5). Putting 11 half-step values on an axis would produce 66 combinations for data that is dynamic by nature.

### 18.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `value` | TEXT | `4.3` | `Numeric` |
| `showNumeric` | BOOLEAN | `true` (`Display`), `false` (`Interactive`) | `Numeric` visibility |
| `count` | TEXT | `(128)` | `Count` |
| `showCount` | BOOLEAN | `true` (`Display`), `false` (`Interactive`) | `Count` visibility |
| `emptyText` | TEXT | `No reviews yet` | `Empty` |
| `showEmpty` | BOOLEAN | `false` | `Empty` visibility, `Numeric`+`Count` hidden |
| `showStars` | BOOLEAN | `true` | `Stars` visibility (set `false` with `showEmpty=true` on a narrow card — see §18.9) |
| `ariaLabel` | TEXT | `Rated 4.3 out of 5 from 128 reviews` | `_aria-label` |
| `focusVisible` | BOOLEAN | `false` | `_focus` (`Interactive` only) |

### 18.3 Geometry

| Size | Star glyph | Star gap | Numeric text | Count text | Row height | Interactive hit cell |
|---|---|---|---|---|---|---|
| `Small` | **14** | `spacing/1` (4) | `en/caption` 12 / 500 | `en/caption` 12 / 400 | 20 | n/a (`Display` only) |
| `Medium` | **18** | `spacing/1` (4) | `en/body-sm` 14 / 600 | `en/body-sm` 14 / 400 | 24 | 44 × 44 |
| `Large` | **24** | `spacing/2` (8) | `en/body` 16 / 700 | `en/body-sm` 14 / 400 | 32 | 44 × 44 |

| Element | Value |
|---|---|
| Stars → Numeric gap | `spacing/2` (8) |
| Numeric → Count gap | `spacing/1` (4) |
| Star stroke | 1.5px |
| Star count | always **5**, never a single star with a number |
| Interactive star spacing | hit cells abut at 44px; the visual star is centred in its cell |
| Interactive total width | 5 × 44 = **220** (`Medium` and `Large`) |
| Display total width (`Medium`, `4.3 (128)`) | 18×5 + 4×4 + 8 + 26 + 4 + 34 = **178** |

`Size=Small` is `Display`-only: a 14px star in a 44px hit cell wastes 30px of row, and no interactive rating in this system is rendered small. The lead marks `Mode=Interactive, Size=Small` as `_invalid` on the board.

### 18.4 Token bindings

| Element | State | Fill | Stroke |
|---|---|---|---|
| Star — filled | — | **`color/bg/accent`** | 1px `color/border/strong` |
| Star — empty | — | `color/bg/subtle` | 1px `color/border/default` |
| Star — hover (`Interactive`) | preview | `color/bg/accent` + `_scrim` 8% | 1px `color/border/strong` |
| Star — disabled | — | `color/bg/subtle` | 1px `color/border/default` |
| Numeric | — | `color/text/primary` | — |
| Count | default | `color/text/secondary` | — |
| Count | hover (when linked) | `color/text/link`, underlined | — |
| Empty text | — | `color/text/tertiary` | — |

**The filled star carries a 1px `color/border/strong` stroke, and this is mandatory.** `color/bg/accent` (#F7B500) against `color/bg/surface` (#FFFFFF) is **1.9:1** — below the 3:1 that WCAG 1.4.11 requires for a non-text graphic whose shape conveys meaning. The 1px neutral.300 outline against white is 2.0:1, which still does not clear 3:1 on its own; the combination that does is the **outline plus the adjacent empty-star shapes** (a filled star is distinguished from an empty star by fill, outline and the `_scrim` difference together) **plus the always-present numeric value**. The numeric value is therefore not optional on `Display` where the rating is load-bearing: `showNumeric` is `true` on the PDP and on `ProductCard`, and the accessible name always states the number. This is the §11.5 accent rule applied to a graphic rather than to text, and it is the reason `showNumeric` defaults to `true`.

### 18.5 The half-star rule

Stated as arithmetic, because this is where rating components usually lie to the user.

```js
// Stars are quantised to halves. The printed number is not.
const starsShown = Math.round(rawValue * 2) / 2;   // 4.3 → 4.5 ; 4.24 → 4.0 ; 4.75 → 5.0
const printed    = rawValue.toFixed(1);            // "4.3"
```

| Rule | Detail |
|---|---|
| Quantisation | **Nearest half**: `Math.round(value * 2) / 2`. Not floor, not ceiling. `4.3 → 4.5` stars, `4.24 → 4.0` stars. |
| Printed number | The **unrounded** value to **one decimal place**, always with the decimal shown: `4.0`, not `4`. A rating printed as `4` next to four-and-a-half stars reads as a bug. |
| Why they differ | The stars are a glanceable approximation at ±0.25; the number is the truth. Rounding the number to match the stars (`4.3 → 4.5`) inflates the rating, which is a trust failure on a site whose whole premise is that it is not a Facebook reseller. Flooring the stars to match the number (`4.3 → 4.0`) under-reports it. Showing both, with the number authoritative, is the only honest option, and it is what Amazon and Daraz both do. |
| Half-star rendering | The half star is **not** a separate glyph. Each of the 5 star positions contains an empty star with a filled star layered over it, and the filled layer is clipped by a mask whose width is `clamp(0, starsShown − index, 1) × 100%`. At 0.5 the mask is exactly half the glyph's **advance width**, not half its bounding box — a star glyph is not symmetric about its advance centre, so masking the bounding box produces a visibly off-centre half. In Figma: a `_fill-mask` frame with `Clip content = true` and width 50%, containing the filled star at full size, left-aligned. |
| Partial values other than halves | Never rendered. The mask takes only 0%, 50% or 100% per star. A 0.3-filled star is visually indistinguishable from a 0.5 and makes the component look imprecise. |
| Zero reviews | `showEmpty = true`: five empty stars plus `No reviews yet` in `color/text/tertiary`. **Not** "0.0" and not a hidden component. An absent rating is information (this toy is new), and hiding the row causes the card height to change between items in the same grid — a CLS and alignment defect. The empty row occupies the same height as a rated row. |
| One review | `5.0 (1)` with the count singular in the accessible name: `Rated 5.0 out of 5 from 1 review`. The visible `(1)` is unchanged. |

### 18.6 The `(N)` review-count treatment

| Rule | Detail |
|---|---|
| Format | **Parenthesised, no space before the opening bracket**: `4.3 (128)`. Not `4.3 · 128 reviews` on a card (too wide at 171px), not `128 reviews` without the parentheses (reads as a separate fact). |
| Thousands | Grouped with a comma at 1,000+: `(1,284)`. **Not** abbreviated to `(1.3k)` — a parent comparing two toys needs the real number, and `k` abbreviation is not meaningful in Bangla. |
| Colour | `color/text/secondary`, one weight lighter than the numeric value (400 vs 600/700). The count is context; the score is the headline. |
| Interactivity — PDP | The whole `4.3 (128)` group is an in-page anchor to `#reviews`. On hover the count goes `color/text/link` and underlines; the numeric value does not change colour (only the count affords the click, so only the count signals it). The anchor's hit area is the full group at `Size=Large`, 32px tall, extended to 44 by `_hit44`. Smooth scroll is suppressed under `prefers-reduced-motion`. |
| Interactivity — ProductCard | **Not a separate link.** The entire card is one link; a nested anchor inside it is invalid HTML and produces two tab stops per card (48 on a 24-item grid). The count is plain text there. This is the single most important rule in this section. |
| Accessible name | The count is never read as `(128)` — parentheses are announced inconsistently across screen readers. The group's accessible name is composed in full: `Rated 4.3 out of 5 from 128 reviews`, and the visible glyphs are `aria-hidden`. |
| Zero | the count is hidden entirely (`showCount = false`) and `emptyText` carries the message. `(0)` is never shown. |
| Position | always **after** the numeric value, which is after the stars. Stars → number → count, left to right, in both scripts (Bangla is LTR). |

### 18.7 Interactive mode

| Rule | Detail |
|---|---|
| Granularity | **Whole stars only.** 1, 2, 3, 4, 5. Half-stars are not selectable. Asking a parent to hit a 22px half-glyph on a phone produces mis-rated reviews, and the aggregate gains nothing from half-step input. Display quantises to halves because the *average* lands there; input does not. |
| Control | A radio group: `<fieldset>` + `<legend>Your rating</legend>` + five `<input type="radio">`, visually hidden, each with a `<label>` carrying the star. **Not** five buttons — radios give arrow-key selection, a single tab stop and native `required` validation for free. |
| Hover preview | Pointer over star _n_ fills 1…_n_ with `_scrim` 8% applied; moving away restores the committed value. Touch devices have no preview (there is no hover): the tap commits directly. |
| Keyboard | `Tab` enters at the selected value (or star 1 if unset); `ArrowLeft`/`ArrowRight` move and select; `Home`/`End` jump to 1 and 5. Each change announces the new value. |
| Focus ring | `_focus` around the **focused star's 44px hit cell** at `radius/md`, not around the whole group — the user needs to know which star is active. |
| Clearing | A `Clear` control is **not** provided. Once a rating is set it can be changed but not unset; an unset rating is the initial state only. This avoids a three-state control (unset / set / cleared) in a form that already validates on submit. |
| Required | The review form's rating is required; its error is attached to the `<fieldset>` per §8.9, text `Choose a rating from 1 to 5`. |
| Animation | The star fill crossfades over `fast` 120ms. No bounce, no scale-up, no burst. Reduced motion: instant. The review form is a trust surface, not a celebration. |

### 18.8 Auto-layout

```
Rating (horizontal, HUG × FIXED per Size, counter align Center, gap spacing/2, Clip content false)
├── Stars (horizontal, HUG × HUG, gap per Size, counter align Center)
│   └── Star ×5  (Display: FIXED glyph size | Interactive: FIXED 44 × 44, center/center)
│       ├── _empty   (glyph)
│       ├── _fill-mask (Clip content true, width 0% | 50% | 100%)
│       │   └── _filled (glyph, left-aligned)
│       └── _focus   (Interactive only)
├── Numeric (HUG × HUG)
├── Count   (HUG × HUG)
└── Empty   (HUG × HUG, replaces Numeric + Count)
```

Row counter axis is `FIXED` at the Size row's height so that a rated row and an empty-state row are the same height — the alignment guarantee that makes a product grid's cards line up.

### 18.9 Bangla

**Strategy: GROW; numerals Western; no wrap.**

| Element | Bangla treatment |
|---|---|
| Stars | Locale-invariant. Five glyphs, same sizes, same gaps. |
| Numeric value | **Western Arabic digits with a full stop decimal separator: `4.3`.** Not `৪.৩`. Reason in §19.6 — the rating sits directly beside the price in every card and on the PDP, and the price uses Western digits for comparison reasons; two numeral systems 8px apart is the worst possible outcome. |
| Count | **Western digits with a comma group: `(1,284)`.** Same reason. |
| `emptyText` | `No reviews yet` → `এখনও কোনো রিভিউ নেই` (19 chars vs 14). At `bn/caption` this is 128px vs 92px. The row hugs and grows; it never wraps. On a 171px product card the empty row fits (5 × 14 + 4 × 4 + 8 + 128 = 222 — **it does not fit**). Resolution: on `ProductCard` the empty state hides the stars and shows only `এখনও রিভিউ নেই` (shortened Bangla string, 112px) in `color/text/tertiary`, left-aligned at the same 20px row height. Set `showEmpty = true` with `showStars = false` (§18.2). The PDP, at full width, shows the stars plus the long string. |
| Accessible name | Fully translated: `১২৮টি রিভিউ থেকে ৫-এ ৪.৩ রেটিং`. The **accessible name uses Bengali numerals** even though the visible glyphs use Western ones, because a Bangla screen-reader voice reads Bengali numerals naturally and Western digits in a Bangla sentence are read in English. This split is deliberate and is the inverse of the visible rule. |

### 18.10 Touch target

| Mode | Element | Size | Verdict |
|---|---|---|---|
| `Display` | the whole component (PDP, linked to `#reviews`) | 178 × 32, extended to 178 × 44 by `_hit44` | Passes |
| `Display` | on `ProductCard` | not interactive | n/a |
| `Interactive` | each star's hit cell | **44 × 44** | Passes natively |
| `Interactive` | total group | 220 × 44 | Passes |

The interactive hit cells abut with no gap, which is permitted because they are mutually exclusive options in one radio group — a mis-tap selects an adjacent rating rather than triggering a different action, and the committed value is visible and changeable before submit.

---

## 19. `Price`

> **This component is load-bearing. A price that reflows after load is a defect, and a price whose symbol is on the wrong side is a trust defect.** Everything below is a requirement, not a default.

### 19.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Size` | `Compact`, `Card`, `PDP` | 3 |
| `Layout` | `Inline`, `Stacked` | 2 |
| `HasDiscount` | `False`, `True` | 2 |
| **Total** | | **12** |

`HasDiscount` is an axis rather than a boolean because it changes the layout contract, not just a layer's visibility: `HasDiscount=True` is the variant that reserves the second row's height in `Stacked` (§19.7) and the only one in which `was` and `discount` exist. A boolean would let a designer produce a struck price with no discount pill, which is a state the business rules forbid (§19.5 rule 5).

`Size=Compact` is for the cart line item, order summary, admin tables and the `AddToCartBar`. `Size=Card` is for `ProductCard`. `Size=PDP` is for the product page and the checkout total.

### 19.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `current` | TEXT | `2,450` | `Current › Number` |
| `was` | TEXT | `3,200` | `Was › Number` |
| `discount` | TEXT | `−25%` | `Discount › Text` |
| `showDiscount` | BOOLEAN | `true` | `Discount` visibility (valid only on `HasDiscount=True`) |
| `ariaLabel` | TEXT | `Price 2,450 taka, was 3,200 taka, 25 percent off` | `_aria-label` |
| `prefixText` | TEXT | `From` | `Prefix` |
| `showPrefix` | BOOLEAN | `false` | `Prefix` visibility |

`showPrefix` carries `From` (variable pricing across variants) and `Total` (checkout). The `৳` symbol is **not** a property — it is a fixed layer, because it is never absent and never translated.

### 19.3 The `৳` placement decision

> **The taka sign goes BEFORE the number, with no space: `৳2,450`.**

**Decision and rationale.**

1. **It is the Bangladeshi convention.** Bangladesh Bank, every scheduled commercial bank's statements and apps, the NBR, and the government's own English- and Bangla-language publications all render the taka sign as a leading symbol: `৳2,450`, `Tk 2,450`. The symbol behaves like `$`, `£` and `₹` — a currency prefix — not like a unit suffix such as `kg` or `৺`.
2. **Two audited competitors get this wrong.** The competitor audit found `2,450৳` and `2450 ৳` in use. Suffixing reads as a measurement unit rather than a currency, and in a price-comparison context (the audience routinely has Daraz open in another tab, and Daraz BD prefixes) it makes our prices look typographically foreign. We match the convention and the market leader; we do not copy the two sites that do not.
3. **It is required for column alignment.** This is the decisive technical reason. With a prefix, every price in a column starts at the same x-position and the *digits* vary to the right. With a suffix, the symbol's x-position is a function of the number's width, so `৳` lands in a different place on every row of a cart, an order summary or an admin table. No amount of `tabular-nums` fixes a suffix, because the variable element is the digit count, not the digit widths. A right-aligned suffix column would align the symbols but then the digits would be ragged on the left, which is worse for scanning magnitudes.
4. **It is required for the no-reflow guarantee.** The symbol is the leftmost glyph and is a fixed width. Whatever happens to the number — a currency-format recalculation, a variant change from `৳1,990` to `৳12,500` — the leftmost pixel of the price does not move. With a suffix, every digit-count change moves the symbol.

**Typographic details, all final:**

| Detail | Value |
|---|---|
| Glyph | **U+09F3 BENGALI RUPEE SIGN `৳`**. Not `Tk`, not `BDT`, not `TK.`, not `৲` (U+09F2 BENGALI RUPEE MARK, a historical form). |
| Position | Immediately before the first digit. |
| Space | **None.** `৳2,450`, not `৳ 2,450`. |
| Optical gap | `margin-inline-end: 1px` on the symbol span. One pixel, not a space — Hind Siliguri's `৳` has tight right sidebearing and sits visually against the `2` without it. |
| Font | The symbol is rendered from **Hind Siliguri in both the `en` and `bn` builds**, because Plus Jakarta Sans does not contain U+09F3 and would fall back unpredictably per platform. The symbol span carries `font-family: var(--font-bangla)` always. This is the only place in the English UI that uses the Bangla face, and it is why the symbol's weight is specified independently below. |
| Size | **Same font-size as the number.** Not 0.85em. A scaled symbol needs a baseline correction, and a baseline correction is a per-platform fudge that moves when the font loads — exactly the reflow this component exists to prevent. |
| Weight | **One step lighter than the number**: 600 where the number is 800, 600 where the number is 700. Hind Siliguri's `৳` is optically heavier than Jakarta's digits at the same weight; stepping down matches them. |
| Colour | Identical to the number it precedes (`color/text/price` on `current`, `color/text/price-was` on `was`). The symbol is never tinted differently. |
| Numerals | **Western Arabic digits (0–9) in both builds.** See §19.6. |
| Grouping | **Three-digit groups with commas**: `৳1,250`, `৳12,500`, `৳125,000`, `৳1,250,000`. **Not** the South Asian two-two-three lakh grouping (`১,২৫,০০০` / `1,25,000`). Reason: Daraz BD, Bangladesh Bank's English publications and every BD payment gateway's receipts use three-digit grouping; `1,25,000` is the Indian convention and misreads as `125,000` to a reader expecting commas every three digits. Implemented as `Intl.NumberFormat('en-US')`, **not** `'en-IN'` and **not** `'bn-BD'` (which yields both lakh grouping and Bengali numerals). |
| Decimals | **None.** Prices are whole taka. `৳2,450`, never `৳2,450.00`. Paisa does not exist in BD retail pricing; any fractional value from a discount calculation is rounded **to the nearest whole taka** at the point of display, and the order total is computed from rounded line values so the arithmetic the customer can check adds up. A trailing `.00` on a toy price reads as a foreign checkout. |
| Negative / credit | Refunds and credits render as `−৳450` with U+2212 before the symbol, so the symbol stays adjacent to the digits. |

### 19.4 Geometry

| Size | `current` text style | `was` text style | `discount` text | Row height (FIXED) | Reserved 2nd row (Stacked) |
|---|---|---|---|---|---|
| `Compact` | `en/price` 14 / 1.3 / 700 | `en/price-was` 12 / 1.3 / 400 | `en/caption` 11 / 700 | **20** | 16 |
| `Card` | `en/price` 17 / 1.3 / 700 | `en/price-was` 14 / 1.3 / 400 | `en/caption` 12 / 700 | **24** | 20 |
| `PDP` | `en/price-lg` 24 / 1.2 / 800 | `en/price-was` 14 / 1.3 / 400 | `en/caption` 12 / 700 | **34** | 20 |

Desktop (≥1024px) raises `Card` current to 18 and `PDP` current to 28 per the type scale; the row heights become 26 and 38. Both are FIXED values, set by a media query, never computed from content.

| Element | Value |
|---|---|
| `Inline` gap (current → was → discount) | `spacing/2` (8) |
| `Stacked` gap (row 1 → row 2) | `spacing/1` (4) |
| `Stacked` row-2 internal gap (was → discount) | `spacing/2` (8) |
| `Prefix` → current gap | `spacing/1` (4) |
| Discount pill height | **20** (`Card`, `PDP`) / **16** (`Compact`) FIXED |
| Discount pill padding X | `spacing/2` (8) / `spacing/1` (4) at `Compact` |
| Discount pill radius | `radius/sm` (6) |
| Strikethrough | 1px, at **56% of the cap height** of the `was` text, full width of the number **including** the `৳` symbol |
| Baseline alignment (`Inline`) | counter axis alignment `Baseline` — not `Center`. A 14px `was` centred against a 24px `current` sits visibly high. |

The strikethrough crosses the symbol as well as the digits: `৳3,200` struck through entirely. A struck number with an unstruck currency symbol reads as two separate pieces of information.

### 19.5 Token bindings and business rules

| Element | Fill / colour |
|---|---|
| `current` number + symbol | `color/text/price` |
| `was` number + symbol + strikethrough | `color/text/price-was` |
| `discount` text | `color/text/discount` |
| `discount` pill fill | `color/bg/brand-subtle` |
| `Prefix` (`From` / `Total`) | `color/text/secondary` |

No hover, pressed, focus or disabled states. `Price` is **non-interactive** text in every usage, including inside a `ProductCard` (the card is the link; the price is content). There is no `Price` variant for an out-of-stock product — the price is shown unchanged and the stock state is carried by `Badge Type=OutOfStock` and `StockIndicator`. Greying out a price makes it look like a different price.

Business rules, all enforced in the component's props rather than left to the caller:

1. **`discount` is computed, never authored**: `Math.round((was − current) / was × 100)`, rendered as `−{n}%` with **U+2212 MINUS SIGN**, not a hyphen.
2. **The pill is hidden below 5%** (`showDiscount = false`). A `−3%` pill devalues every real discount on the page. The struck `was` price still shows.
3. **Above 70% the pill still shows**, with no cap and no special styling. If the business runs a 75%-off clearance the number is the point.
4. **`was` must be greater than `current`.** If `was ≤ current` the component renders `HasDiscount=False` and logs a data error. It never renders a struck price equal to or below the current price.
5. **`HasDiscount=True` requires both `was` and a ≥5% `discount`**, or `HasDiscount=False` is used. A struck price with no percentage, or a percentage with no struck price, is not a renderable state.
6. **The `was` price is the real former price**, held in the database with the date it changed, so that a price-comparison claim is defensible. The component does not know this, but the field it binds to does, and the admin's pricing form enforces it.

### 19.6 Numerals — Western, in both builds

> **The visible digits of a price are always Western Arabic (`2,450`), in the English build and the Bangla build alike.**

Four reasons, in order of weight:

1. **Price comparison.** The audience price-compares against Facebook pages and Daraz in other tabs, and both use Western digits. A Bangla-locale shopper reading `৳২,৪৫০` here and `৳2,450` there cannot compare at a glance, which is the single activity this component exists to support.
2. **Mixed numeral systems in one view.** A phone number field, an OTP, a quantity stepper and an order number are all Western digits (they mirror the keypad the user types on). If prices were Bengali, a single checkout page would carry both systems — a known legibility and trust problem, and the kind of inconsistency that reads as a half-built site.
3. **Tabular figures.** Plus Jakarta Sans ships `tnum` for Western digits. Hind Siliguri's Bengali numerals have no tabular variant, so a Bangla-numeral price column cannot be aligned at all — which would forfeit the component's primary job.
4. **It is what BD commerce does.** Daraz BD, bKash, Nagad and every BD bank app show Western digits in Bangla interfaces.

**The boundary is "data versus prose":**

| Context | Numerals |
|---|---|
| Price, quantity, phone, OTP, order number, review count, rating value, stock count in a stepper | **Western**: `2,450`, `01712345678`, `4.3`, `(128)` |
| Prose and accessible names: stock reasons, delivery promises, screen-reader strings | **Bengali**: `স্টকে মাত্র ৩টি আছে`, `২-৩ দিনে ডেলিভারি`, `৳২,৪৫০ মূল্য` |

The accessible name of a `Price` therefore uses **Bengali** numerals in the `bn` build (`২,৪৫০ টাকা দাম, আগে ছিল ৩,২০০ টাকা, ২৫% ছাড়`) while the visible glyphs are Western — a Bangla TTS voice reads Bengali numerals naturally and reads Western digits in English, so this split produces the correct spoken result from the correct visual one. The currency word in the accessible name is `টাকা`, spelled out, never the `৳` glyph (screen readers announce it inconsistently or not at all).

### 19.7 No-reflow guarantee — the seven rules

A price that moves after load is a defect. These are the mechanisms, and all seven are required:

1. **Server-rendered.** The price is in the initial HTML, rendered on the server from the same data the cart uses. It is **never** fetched client-side and never has a loading state. There is no `Price` skeleton (§15.6 rule 6) because there is no moment at which the price is unknown.
2. **Tabular figures.** `font-variant-numeric: tabular-nums` on `current`, `was` and the discount digits. Every digit has the same advance width, so `৳1,111` and `৳9,999` are the same pixel width and a variant change cannot shift anything.
3. **Fixed row height.** Each `Size` has a FIXED row height (§19.4), not a content-derived one. The height does not depend on which glyphs are present, so a `From` prefix appearing or a discount pill disappearing cannot change the row's height.
4. **Reserved second row.** In `Layout=Stacked`, `HasDiscount=False` **still reserves the second row's height** (16 / 20 / 20px) with an empty, `aria-hidden` spacer. A product grid mixing discounted and undiscounted toys therefore has cards of identical height, and switching a PDP variant from a discounted to an undiscounted SKU does not move the Add-to-cart button. This is why `HasDiscount` is a variant axis and not a boolean.
5. **Prefix-position invariance.** `৳` is the leftmost glyph and fixed-width, so the price's left edge never moves (§19.3 reason 4). In right-aligned contexts (cart totals, admin tables) the column is a FIXED width sized to the largest expected value — `৳1,234,567` worst case (three-digit grouping, §19.3 — never lakh) → **124px** at `Size=Compact` — so a long value does not widen the column.
6. **Font-load stability.** Both families are self-hosted, subset, `font-display: swap`, with a metric-matched fallback declared via `size-adjust`, `ascent-override` and `descent-override` in an `@font-face` for the fallback stack. The swap from fallback to Jakarta must not change the digits' advance width; the override values are measured once and committed. The `৳` symbol is the risk here — it comes from Hind Siliguri, which is a second font file, and until it loads the fallback renders a `.notdef` box of a different width. **Mitigation: the `৳` glyph is inlined as a subsetted woff2 containing only U+09F3 (under 1KB), loaded with `font-display: block` and preloaded in the document head.** `block` is correct for this one case: a 100ms invisible symbol is better than a visible width change, and the file is small enough that the block period never elapses on a warm cache.
7. **No animation on price change.** When a variant selection changes the price, the new value replaces the old instantly. No crossfade, no count-up, no highlight flash. A price that animates is a price the customer watches instead of reads, and a count-up animation on a 4G connection is indistinguishable from a bug.

**Additional defect classes this prevents, stated so they are testable:** a discount pill that appears after a client-side promotion check (forbidden — promotions are resolved server-side); a currency symbol that renders after the Bangla font loads (prevented by rule 6); a cart total that shifts when the coupon field validates (the total's column is FIXED per rule 5); a PDP price that moves when the delivery estimator returns (the estimator is below the price and the price box is FIXED per rule 3).

### 19.8 Auto-layout

**`Layout=Inline`** (used at `Size=Compact` and `Card`):

```
Price (horizontal, HUG × FIXED per Size, counter align BASELINE, gap spacing/2, Clip content false)
├── Prefix    (HUG, optional)
├── Current   (horizontal, HUG × HUG, gap 0)
│   ├── _symbol  (৳ — Hind Siliguri, weight −1 step, margin-inline-end 1px)
│   └── Number   (tabular-nums)
├── Was       (horizontal, HUG × HUG, gap 0, Clip content false)
│   ├── _symbol
│   ├── Number
│   └── _strike  (Ignore auto-layout, 1px, full width, at 56% cap height)
└── Discount  (HUG × FIXED 20|16, padding X spacing/2|1, radius/sm, center/center)
```

**`Layout=Stacked`** (used at `Size=PDP`, and at `Card` on a 2-up mobile grid where inline would overflow 171px):

```
Price (vertical, HUG × HUG, gap spacing/1, align Left)
├── Row1 (horizontal, HUG × FIXED per Size, baseline, gap spacing/1)
│   ├── Prefix
│   └── Current
└── Row2 (horizontal, HUG × FIXED 20|16, counter align Center, gap spacing/2)
    ├── Was
    ├── Discount
    └── _spacer  (HasDiscount=False: a 0 × 20 frame holding the row's height open)
```

`counter align BASELINE` on the inline row is essential and is the one alignment setting in this document that cannot be substituted with `Center`.

### 19.9 Bangla — long-string behaviour

**Strategy: the numeric core is locale-invariant; only the optional words change, and they GROW or WRAP.**

| Element | en | bn | Behaviour |
|---|---|---|---|
| `_symbol` + `Number` | `৳2,450` | `৳2,450` | **Identical.** Same glyphs, same font for the symbol, same tabular digits, same width. This is the component's most important i18n property: the price block does not change size between languages, so no page needs two price measurements. |
| `discount` | `−25%` | `−২৫%` → **no**: `−25%` | Western digits, per §19.6. Identical width. |
| `Prefix` `From` | 36px | `থেকে` 42px | GROW. +6px, absorbed by the HUG row. |
| `Prefix` `Total` | 36px | `সর্বমোট` 62px | GROW. +26px. In the checkout summary the prefix is in a `FILL` left column with the price right-aligned in a FIXED 124px column, so the growth is absorbed by the left column, not by the price. |
| Accessible name | `Price 2,450 taka, was 3,200 taka, 25 percent off` | `২,৪৫০ টাকা দাম, আগে ছিল ৩,২০০ টাকা, ২৫% ছাড়` | 40% longer; no layout effect (it is an attribute). |
| Free-delivery threshold line (`ProgressBar` label, §31) | `৳450 more for free delivery` | `ফ্রি ডেলিভারির জন্য আরও ৳450` | WRAP to 2 lines. This string is the one price-adjacent label that is genuinely 40% longer and it belongs to `ProgressBar`, not `Price`. |

The 40% rule's answer for this component is therefore: **the price itself is exempt, because it contains no translatable text.** That is a design outcome, not an accident — the `৳` is a symbol rather than the word `Taka`/`টাকা` precisely so that the most layout-critical element on the site is script-independent.

### 19.10 Touch target

Not applicable — `Price` is non-interactive in every usage. Where a price sits inside a tappable region (a `ProductCard`, a cart line item), that region owns the 44×44 guarantee. `Price` sets `pointer-events: none` on its own glyphs so that a tap on the price never lands on a nested element and always reaches the card's link.

### 19.11 Accessibility

```html
<p class="price" aria-label="Price 2,450 taka, was 3,200 taka, 25 percent off">
  <span aria-hidden="true">
    <span class="price__current"><span class="price__sym">৳</span>2,450</span>
    <s class="price__was"><span class="price__sym">৳</span>3,200</s>
    <span class="price__off">&minus;25%</span>
  </span>
</p>
```

- **One composed `aria-label` on the wrapper; every visible glyph is `aria-hidden`.** Read individually, the three parts announce as `taka 2,450` `taka 3,200` `minus 25 percent`, which a listener cannot assemble into "it is discounted". The composed label states the relationship.
- The currency is spoken as the **word** (`taka` / `টাকা`), never the symbol.
- The struck price is wrapped in `<s>`, which carries no semantics in most screen readers — hence the composed label, which says `was`. `<del>` is not used: nothing has been deleted from a document.
- `−25%` uses U+2212; the accessible name says `25 percent off`, not `minus 25 percent`.
- Contrast: `color/text/price` (neutral.900) on `color/bg/surface` is 16.1:1. `color/text/price-was` (neutral.500) on white is **4.6:1** — passes 4.5:1 at the 14px struck size. `color/text/discount` (brand.600, #D42E27) on `color/bg/brand-subtle` (brand.50, #FFF1F0) is **5.9:1** — passes at 12px. All three verified by the token-level CI test, not by eye.
- The strikethrough is 1px at 56% cap height and must remain visible at 200% zoom and in Windows High Contrast Mode; it is drawn as a `border-bottom` on an inline-block, not as `text-decoration: line-through`, because forced-colors mode drops text decorations on some engines while keeping borders.

---

## 20. `Tabs`

Two component sets: **`Tabs/Item`** (the variant set) and **`Tabs`** (a layout frame that holds instances). A single set carrying both the list and the items would make the item states inaccessible from the instance panel.

### 20.1 Variant matrix — `Tabs/Item`

| Axis | Values | Count |
|---|---|---|
| `Variant` | `Underline`, `Pill` | 2 |
| `State` | `Default`, `Active`, `Disabled` | 3 |
| **Total** | | **6** |

Hover, pressed and focus-visible are interaction-only (§0.3). `Active` is an axis because exactly one tab is active at all times and a mockup must show it.

### 20.2 Component properties — `Tabs/Item`

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `Description` | `Label` |
| `count` | TEXT | `12` | `Count › Text` |
| `showCount` | BOOLEAN | `false` | `Count` visibility |
| `iconSwap` | INSTANCE_SWAP | `icon/circle` | `Icon › icon` |
| `showIcon` | BOOLEAN | `false` | `Icon` visibility |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

### 20.3 Geometry

| Element | `Underline` | `Pill` |
|---|---|---|
| Item height (FIXED) | **44** | **44** |
| Item min-width | 72 | 72 |
| Item padding X | `spacing/4` (16) | `spacing/4` (16) |
| Item padding Y | 0 (`Center`) | 0 (`Center`) |
| Item gap (icon → label → count) | `spacing/2` (8) | `spacing/2` (8) |
| Item radius | 0 | `radius/full` |
| Icon size | 20 | 20 |
| Label text | `en/body` 15 / 1.6 / 600 (`Default`), 700 (`Active`) | same |
| Count | `en/caption` 12 / 600, inside a 20-tall pill, padding X `spacing/2`, `radius/full` | same |
| Active indicator | 2px bar, bottom edge, full item width, `radius/full` | none (the fill is the indicator) |
| List gap between items | `spacing/6` (24) | `spacing/2` (8) |
| List bottom border | 1px `color/border/default`, full width, behind the indicator | none |
| List padding | X 0 (page gutter supplies it), Y 0 | `spacing/1` (4) all sides, inside a `color/bg/subtle` track at `radius/full` |
| Panel padding top | `spacing/6` (24) | `spacing/6` (24) |

`Underline` is the default and is used for the PDP's Description / Specifications / Age guidance / Reviews, and for the admin order board's status tabs. `Pill` is used only where tabs sit on a tinted surface and need their own container — the admin dashboard's period switch.

### 20.4 Token bindings

| Variant | State | Fill | Label | Icon | Count pill | Indicator |
|---|---|---|---|---|---|---|
| `Underline` | `Default` | `None` | `color/text/secondary` | `color/text/secondary` | fill `color/bg/subtle`, text `color/text/secondary` | hidden |
| | `Hover` | `color/bg/subtle` | `color/text/primary` | `color/text/primary` | as default | hidden |
| | `Active` | `None` | `color/text/primary` (700) | `color/text/primary` | fill `color/bg/brand-subtle`, text `color/text/primary` | `color/bg/brand` |
| | `Focus-visible` | `None` | as the underlying state | — | — | `_focus` |
| | `Disabled` | `None` | `color/text/tertiary` | `color/text/tertiary` | fill `color/bg/subtle`, text `color/text/tertiary` | hidden |
| `Pill` | `Default` | `None` | `color/text/secondary` | `color/text/secondary` | as above | n/a |
| | `Hover` | `color/bg/surface` | `color/text/primary` | `color/text/primary` | as above | n/a |
| | `Active` | `color/bg/surface` + `e1` | `color/text/primary` (700) | `color/text/primary` | fill `color/bg/brand-subtle` | n/a |
| | `Disabled` | `None` | `color/text/tertiary` | `color/text/tertiary` | as above | n/a |

`Underline` hover fill is `color/bg/subtle` with `radius/md` on the top two corners only, so the hover shape does not cross the bottom rule.

Indicator animation: the active bar slides between tabs over `base` 200ms `standard` (a shared layout transition, not a per-tab fade). Reduced motion: the bar jumps instantly. The panel content crossfades over `fast` 120ms; reduced motion: instant.

### 20.5 Auto-layout

```
Tabs (vertical, FILL × HUG, gap 0)
├── TabList (horizontal, FILL × FIXED 44, gap per Variant, counter align Center,
│            Clip content false, bottom border 1px [Underline])
│   └── Tabs/Item ×n  (HUG × FILL)
└── TabPanel (vertical, FILL × HUG, padding-top spacing/6)
```

`TabList` primary axis alignment is `Left` (`Packed`), **not** `Space between` and **not** `Center`. Tabs are read left to right in reading order; distributing them makes the active indicator's travel distance meaningless and breaks when one label is long.

Items are `HUG` width, never `FILL`. Equal-width tabs force the shortest label to carry the longest label's box, which at Bangla lengths produces a tab list far wider than the viewport. Exception: a two-tab list inside a `Drawer` footer uses `FILL` items — documented instance `Tabs / two-up`, not a variant.

### 20.6 Overflow — mobile

**Horizontal scroll. Never a dropdown, never wrapping, never truncation.**

```css
.tablist {
  display: flex; overflow-x: auto; overflow-y: hidden;
  scroll-snap-type: x proximity;
  scrollbar-width: none;             /* and ::-webkit-scrollbar { display: none } */
  -webkit-overflow-scrolling: touch;
  padding-inline: 16px;              /* page gutter lives inside the scroller */
  scroll-padding-inline: 16px;
}
.tablist > * { scroll-snap-align: start; flex: 0 0 auto; }
.tablist::after {                    /* right-edge fade, 24px */
  content: ""; position: sticky; right: 0; width: 24px; flex: 0 0 24px;
  background: linear-gradient(to right, transparent, var(--color-bg-canvas));
  pointer-events: none;
}
```

| Rule | Detail |
|---|---|
| Gutter inside the scroller | The 16px page gutter is `padding-inline` on the scroll container, so the first tab starts at the gutter and the last tab can scroll fully into view with gutter air after it. A gutter applied to a parent clips the scroll. |
| Fade mask | 24px gradient to `color/bg/canvas` on the right edge only while more content exists; it is the overflow affordance. It is `pointer-events: none` and `aria-hidden`. |
| Active tab scrolled into view | On mount and on change, the active tab is scrolled into view with `scroll-behavior: smooth` (`auto` under reduced motion) and `inline: 'nearest'`. A PDP opened on the Reviews tab must not show a scrolled-off indicator. |
| No wrapping | A second row of tabs reads as two separate tab lists and costs 44px above the fold. |
| No "More" dropdown | An overflow menu hides the thing the user is looking for behind a control they did not know existed, and it needs its own keyboard model. |
| Desktop | No scroller — the list fits. The same component; `overflow-x` is only applied under `max-width: 1023px`. |

### 20.7 Bangla

**Strategy: SCROLL (the list), GROW (each item); no wrap, no truncation.**
Items hug their labels. Measured PDP tab set at `bn/body` 15/600:

| en label | en item width | bn label | bn item width |
|---|---|---|---|
| `Description` | 118 | `বিবরণ` | 92 |
| `Specifications` | 142 | `স্পেসিফিকেশন` | 148 |
| `Age guidance` | 130 | `বয়স নির্দেশিকা` | 152 |
| `Reviews (128)` | 150 | `রিভিউ (128)` | 136 |
| **Total + 3 × 24 gap** | **612** | | **600** |

Both overflow a 390px viewport, which is why the scroller exists in both builds — the Bangla case is not a special case, it is the same case. `Label` has `white-space: nowrap`.

### 20.8 Touch target

Items are 44 tall × ≥72 wide — passes. Adjacent items are 24px apart (`Underline`) or 8px (`Pill`); in both cases the 44px targets are the items themselves and do not overlap. The scroll container's own 24px fade is not a target (`pointer-events: none`), so a tap at the right edge reaches the tab beneath it.

### 20.9 Accessibility

- `role="tablist"` on the list, `role="tab"` on each item, `role="tabpanel"` on the panel, with `aria-controls` / `aria-labelledby` paired both ways.
- `aria-selected` on the active tab; `tabindex="0"` on the active tab and `tabindex="-1"` on the rest — **one tab stop for the whole list** (the manual-activation roving tabindex pattern).
- `ArrowLeft` / `ArrowRight` move focus; `Home` / `End` jump. **Activation is manual**: focus moves without changing the panel, and `Enter` or `Space` activates. Automatic activation would fire a server request per arrow press on the admin order board.
- The panel is focusable (`tabindex="0"`) so that `Tab` from the tab list lands in the panel, not past it.
- `aria-disabled` on a disabled tab, which stays in the arrow-key sequence so its state is discoverable.
- Tab counts are part of the accessible name: `Reviews, 128 items`. The visible `(128)` pill is `aria-hidden`.
- Tabs are **never** the only navigation to content that needs its own URL. The PDP's tabs update the hash (`#reviews`) and are linkable; the admin order board's status tabs are real routes (`/orders?status=pending`), so a shared link opens the right queue.

---

## 21. `Accordion`

Two sets: **`Accordion/Item`** (variant set) and **`Accordion`** (layout frame).

### 21.1 Variant matrix — `Accordion/Item`

| Axis | Values | Count |
|---|---|---|
| `Variant` | `Boxed`, `Flush` | 2 |
| `State` | `Collapsed`, `Expanded`, `Disabled` | 3 |
| **Total** | | **6** |

### 21.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `title` | TEXT | `Delivery & returns` | `Header › Title` |
| `subtitle` | TEXT | `2–3 days inside Dhaka` | `Header › Subtitle` |
| `showSubtitle` | BOOLEAN | `false` | `Header › Subtitle` visibility |
| `leadingSwap` | INSTANCE_SWAP | `icon/truck` | `Header › Leading › icon` |
| `showLeading` | BOOLEAN | `false` | `Header › Leading` visibility |
| `badgeSwap` | INSTANCE_SWAP | `Badge` | `Header › BadgeSlot` |
| `showBadge` | BOOLEAN | `false` | `Header › BadgeSlot` visibility |
| `bodySlot` | INSTANCE_SWAP | `Accordion/BodyPlaceholder` | `Panel › Content` |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

### 21.3 Geometry

| Element | Value |
|---|---|
| Header height | `HUG`, **`Min height` 56** |
| Header padding X | `spacing/4` (16) |
| Header padding Y | `spacing/4` (16) |
| Header gap | `spacing/3` (12) |
| Leading icon | 20 |
| Chevron | 20 (`icon/chevron-down`), right-aligned |
| Title text | `en/h4` 16 / 1.35 / 600 (`Collapsed`), **700** (`Expanded`) |
| Subtitle text | `en/body-sm` 14 / 1.5 / 400 |
| Title → subtitle gap | `spacing/1` (4) |
| Panel padding X | `spacing/4` (16) |
| Panel padding bottom | `spacing/4` (16) |
| Panel padding top | 0 (the header's bottom padding supplies the gap) |
| Panel text | `en/body` 15 / 1.6 / 400, `color/text/primary` |
| Item radius (`Boxed`) | `radius/lg` (14) |
| Item radius (`Flush`) | 0 |
| Item border (`Boxed`) | 1px `color/border/default` |
| Item divider (`Flush`) | 1px `color/border/default` on the **bottom** of every item except the last |
| Gap between items (`Boxed`) | `spacing/3` (12) |
| Gap between items (`Flush`) | 0 |

`Min height` 56 on the header, not a FIXED 56: a two-line Bangla title must grow the header rather than overflow it.

### 21.4 Token bindings

| Variant | State | Item fill | Header fill | Title | Subtitle | Chevron | Border |
|---|---|---|---|---|---|---|---|
| `Boxed` | `Collapsed` | `color/bg/surface` | `None` | `color/text/primary` | `color/text/secondary` | `color/text/secondary` | 1px `color/border/default` |
| | `Collapsed` + hover | `color/bg/surface` | `color/bg/subtle` | `color/text/primary` | `color/text/secondary` | `color/text/primary` | 1px `color/border/strong` |
| | `Expanded` | `color/bg/surface` | `None` | `color/text/primary` (700) | `color/text/secondary` | `color/text/primary` | 1px `color/border/default` |
| | `Disabled` | `color/bg/subtle` | `None` | `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` | 1px `color/border/default` |
| `Flush` | `Collapsed` | `None` | `None` | `color/text/primary` | `color/text/secondary` | `color/text/secondary` | bottom 1px `color/border/default` |
| | `Collapsed` + hover | `None` | `color/bg/subtle` | `color/text/primary` | `color/text/secondary` | `color/text/primary` | bottom 1px `color/border/default` |
| | `Expanded` | `None` | `None` | `color/text/primary` (700) | `color/text/secondary` | `color/text/primary` | bottom 1px `color/border/default` |
| | `Disabled` | `None` | `None` | `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` | bottom 1px `color/border/default` |

Focus-visible: `_focus` around the **header row only** (it is the button), at `radius/md` inside a `Boxed` item and `radius/md` in `Flush`. Never around the whole item including the panel.

`Boxed` uses a 1px border rather than `e1`, because in dark mode `e1` substitutes a border anyway (source spec §3.3) and a border in both modes is one binding instead of two.

### 21.5 Animation and the checkout rule

| Parameter | Value |
|---|---|
| Chevron rotation | 0° → 180°, `base` 200ms `standard` |
| Panel open | `grid-template-rows: 0fr → 1fr` (the CSS-grid technique, which animates to the content's real height without measuring it in JS), `base` 200ms `standard`, plus opacity 0→1 over `fast` 120ms delayed 40ms |
| Panel close | reverse, `base` 200ms |
| Reduced motion | **No height animation and no rotation.** The panel appears and disappears instantly; the chevron swaps to `icon/chevron-up`. |
| Easing | `standard` only. **`spring` is forbidden here**, and specifically on checkout. |

> **Checkout accordion rule.** The checkout's three sections (Contact & delivery → Delivery method & charge → Payment & review) use `Variant=Flush`, `allowMultiple = false`, **no animation at all** (`base` height transition is permitted; nothing else), no icon in the header, no badge, and a plain numbered prefix in the title (`1. Contact & delivery`). No colour celebration on completion — a completed section's header shows a 20px `icon/check` in `color/status/in-stock` and nothing more. Playfulness does not enter the page where a parent is deciding whether to hand over money.

### 21.6 Auto-layout

```
Accordion (vertical, FILL × HUG, gap per Variant)
└── Accordion/Item (vertical, FILL × HUG, gap 0, Clip content true [Boxed], radius per Variant)
    ├── Header (horizontal, FILL × HUG min 56, counter align Center, padding spacing/4, gap spacing/3)
    │   ├── Leading (20 × 20)
    │   ├── TextBlock (vertical, FILL × HUG, gap spacing/1)
    │   │   ├── Title     (FILL × HUG, wrap)
    │   │   └── Subtitle  (FILL × HUG, wrap)
    │   ├── BadgeSlot (HUG)
    │   ├── Chevron   (20 × 20)
    │   └── _focus
    └── Panel (vertical, FILL × HUG, padding X spacing/4 / bottom spacing/4, gap spacing/3)
        └── Content (bodySlot)
```

`Header`'s counter alignment is `Center` when the title is one line and the subtitle is hidden; with a subtitle or a wrapped title it stays `Center` (the chevron centres against the whole text block, which reads correctly) — the one exception is a four-line Bangla title, where the chevron is top-aligned with a `padding-top: calc((1lh - 20px) / 2)`. The lead builds `Accordion/Item / bn long title` as a documentation instance.

### 21.7 Bangla

**Strategy: WRAP — title 2 lines, subtitle 2 lines, panel unbounded.**
`Delivery & returns` (18 chars, 1 line) → `ডেলিভারি ও রিটার্ন` (18 chars) fits one line at 358px. `Age guidance and safety information` (35 chars) → `বয়স নির্দেশিকা ও নিরাপত্তা তথ্য` (31 chars) wraps to 2 lines at `bn/h4` (16 × 1.35 × 1.15 = 24.8px/line), giving a 50px text block and a 82px header — above the 56px minimum, absorbed by `HUG`. The chevron and badge are `HUG` and keep their widths; `TextBlock` is `FILL` and absorbs the loss. Three title lines is a content defect; titles are capped at **40 Bangla characters**.

The panel body wraps freely; Bangla body copy runs ~15% taller per line and 20–40% longer overall, and the panel is `HUG` so it simply grows. Because the panel's height is animated via `0fr → 1fr` rather than a measured pixel value, the Bangla height needs no separate measurement — the technique is script-agnostic, which is the reason it is specified rather than a JS `scrollHeight` animation.

### 21.8 Touch target

Header is ≥56 tall × `FILL` wide — passes with margin. **The entire header row is the button**, not just the title or the chevron. The chevron is decorative (`aria-hidden`) and inside the header's single target; a chevron-only hit area is the classic accordion defect. A badge inside the header is non-interactive, so it steals no taps.

### 21.9 Accessibility

```html
<h3 class="accordion__heading">
  <button type="button" id="acc-delivery-btn"
          aria-expanded="false" aria-controls="acc-delivery-panel">
    Delivery &amp; returns
    <svg aria-hidden="true" class="accordion__chevron">…</svg>
  </button>
</h3>
<div id="acc-delivery-panel" role="region" aria-labelledby="acc-delivery-btn" hidden>…</div>
```

- The header is a `<button>` **inside** a heading element of the correct level for the page's outline (`<h3>` on the PDP, `<h2>` on the checkout). Not a `<div role="button">`, and not a heading with a click handler.
- `aria-expanded` on the button; `aria-controls` → panel id; `role="region"` + `aria-labelledby` on the panel so a screen reader user can jump between sections by region.
- The panel uses the `hidden` attribute when closed, so its content is out of the a11y tree and out of the tab order. A panel hidden with `height: 0; overflow: hidden` leaves focusable children reachable by `Tab`, which is the second-most-common accordion defect.
- `Enter` and `Space` both toggle (native button behaviour, not intercepted). Arrow keys do **not** move between headers — an accordion is not a tablist, and hijacking the arrows breaks scrolling on the PDP.
- `allowMultiple = true` on the PDP (a parent reads delivery and age guidance together); `false` on checkout and on the FAQ. When `false`, collapsing the open section on expanding another is announced only by the `aria-expanded` changes, with no live region — a live announcement per toggle is noise.
- A `Disabled` item still renders its heading and is still focusable with `aria-disabled="true"`, so the user learns the section exists; it is used for checkout steps not yet reachable.

---

## 22. `Breadcrumb`

### 22.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Collapsed` | `False`, `True` | 2 |
| **Total** | | **2** |

`Collapsed=True` is the mobile form with the middle levels behind a `…` control. The item states (link vs current page) belong to a child set, **`Breadcrumb/Item`**, axis `State=Link|Current` (2).

### 22.2 Component properties

`Breadcrumb` is a layout frame holding `Breadcrumb/Item` instances plus an optional `Overflow` control.

**`Breadcrumb/Item`:**

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `Educational toys` | `Label` |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

**`Breadcrumb` (frame):**

| Property | Type | Default | Bound to |
|---|---|---|---|
| `overflowAriaLabel` | TEXT | `Show 2 more levels` | `Overflow › _aria-label` |

### 22.3 Geometry

| Element | Value |
|---|---|
| Row height | **FIXED 24** (the row never grows — see §22.6) |
| Row padding | 0 (the page gutter supplies it, inside the scroller — see §22.6) |
| Gap (item → separator → item) | `spacing/2` (8) |
| Separator | `icon/chevron-right` at **14**, stroke 1.75px |
| Item label text | `en/body-sm` 14 / 1.5 / 400 (`State=Link`), **600** (`State=Current`) |
| Item hit height | 24 visual, **44 via `_hit44`** |
| Overflow `…` control | **28 × 24** visual, `radius/sm`, hit area 44 × 44 via `_hit44` |
| Home item | an `icon/home` at 16 instead of a label, on viewports < 480px |

### 22.4 Token bindings

| Element | State | Colour |
|---|---|---|
| `Breadcrumb/Item` `State=Link` | Default | `color/text/link` |
| | Hover | `color/text/link`, **underlined** (`text-decoration-thickness: 1px`, `text-underline-offset: 2px`) |
| | Pressed | `color/text/link` + `_scrim` 12% |
| | Focus-visible | `color/text/link` + `_focus` at `radius/sm` |
| `Breadcrumb/Item` `State=Current` | — | `color/text/secondary`, weight 600, no underline, not a link |
| Separator | — | `color/text/tertiary` |
| Overflow `…` | Default | `color/text/secondary` |
| | Hover | `color/text/primary`, fill `color/bg/subtle` |
| | Focus-visible | `_focus` at `radius/sm` |

Links are `color/text/link` (info.600, #1D4ED8) and **underline on hover and focus, not at rest**. At rest the link colour alone carries the affordance; within a breadcrumb the pattern is well enough established that permanent underlines read as noise. Contrast: `color/text/link` on `color/bg/canvas` is 7.7:1. The current page is `color/text/secondary` at weight 600 — a deliberate weight-plus-colour difference, so "where I am" is not signalled by colour alone.

### 22.5 Mobile collapse

On viewports under 768px with more than three levels, the breadcrumb collapses to: **first level, `…`, last two levels.**

`Home / Toys / Educational toys / STEM kits / Robot building kit`
→ `Home / … / STEM kits / Robot building kit`

| Rule | Detail |
|---|---|
| Kept | Level 1 (`Home`) and the last two (the parent category and the current page). The parent is what a shopper taps to go back to the grid; the first level is the escape hatch. |
| `…` control | A real `<button>`, 28 × 24 visual with a 44 × 44 hit area, `aria-label="Show 2 more levels"` (the count is interpolated), `aria-expanded`. |
| On activation | Opens a `Popover Side=Bottom Align=Start` (§28) listing the hidden levels as a vertical list of 44px-tall links, `spacing/3` padding X, `color/text/link`. **Not** an expansion in place — expanding in place pushes the page content down and the user loses their scroll position. |
| Desktop | `Collapsed=False`; all levels shown, no `…`. |
| Three levels or fewer | Never collapsed, at any viewport. |

### 22.6 Overflow when a single label is long

**Strategy: SCROLL. The breadcrumb never wraps and never truncates.**

```css
.breadcrumb__list {
  display: flex; align-items: center; gap: 8px;
  overflow-x: auto; overflow-y: hidden;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
  padding-inline: 16px;          /* gutter inside the scroller, as Tabs §20.6 */
  height: 24px;
}
.breadcrumb__list > * { flex: 0 0 auto; white-space: nowrap; }
```

Reasons, stated because the alternatives are all tempting:

- **Wrapping** produces a two-line breadcrumb whose second line starts with a chevron, which reads as a broken list, and it costs 24px above the fold on the page where the product image should be.
- **Truncating** a category name (`Educational to…`) destroys the one piece of information the breadcrumb exists to give, and in Bangla it frequently removes the head noun.
- **Scrolling** keeps the current page's name — the rightmost item — reachable, and the list is scrolled to its **right end** on mount, so the user sees `… / STEM kits / Robot building kit` and scrolls left to go up the tree. That matches the direction of travel.

The row height is FIXED 24 precisely because the row scrolls rather than wraps; a `HUG` height would be a latent two-line layout.

### 22.7 Auto-layout

```
Breadcrumb (horizontal, FILL × FIXED 24, counter align Center, gap spacing/2,
            Clip content true, horizontal scroll)
├── Breadcrumb/Item  (HUG × FILL, State=Link)
├── _sep (14 × 14)
├── Overflow (28 × 24)        [Collapsed=True only]
├── _sep
├── Breadcrumb/Item  (HUG × FILL, State=Link)
├── _sep
└── Breadcrumb/Item  (HUG × FILL, State=Current)
```

Items are `HUG` width with `white-space: nowrap`; the frame's primary axis is `FILL` with `Packed` alignment to the left.

### 22.8 Bangla

**Strategy: SCROLL; items GROW; nothing wraps or truncates.**
Measured worst case on the storefront, the deepest category path at `bn/body-sm`:

| en | width | bn | width |
|---|---|---|---|
| `Home` | 42 | `হোম` | 36 |
| `Toys` | 38 | `খেলনা` | 48 |
| `Educational toys` | 124 | `শিক্ষামূলক খেলনা` | 138 |
| `STEM kits` | 76 | `স্টেম কিট` | 82 |
| `Robot building kit` | 138 | `রোবট বিল্ডিং কিট` | 146 |
| **Total + 4 separators + gaps** | **490** | | **514** |

Both exceed 390px, so both scroll — again, Bangla is not a special case. The collapsed mobile form (`হোম / … / স্টেম কিট / রোবট বিল্ডিং কিট`) measures **330px** and fits a 390px viewport inside the 16px gutters with 26px to spare, which is why the collapse rule keeps exactly two trailing levels and not three.

Bangla category names are capped at **24 characters** in the taxonomy guidelines for this reason.

### 22.9 Touch target

| Element | Visual | Hit area | Verdict |
|---|---|---|---|
| `Breadcrumb/Item` link | ≥36 × 24 | ≥36 × 44 via `_hit44` | Passes |
| Overflow `…` | 28 × 24 | 44 × 44 via `_hit44` | Passes |
| Separator | 14 × 14 | none (`pointer-events: none`) | n/a |

Adjacent items are 8px + 14px (separator) + 8px = 30px apart edge to edge, so two 44px-tall hit areas are horizontally separated and never overlap. The separators are explicitly `pointer-events: none` so a tap in the 30px gap falls through to the scroll container rather than to the wrong link.

### 22.10 Accessibility

```html
<nav aria-label="Breadcrumb">
  <ol class="breadcrumb__list">
    <li><a href="/">Home</a></li>
    <li aria-hidden="true" class="breadcrumb__sep">…chevron…</li>
    <li><a href="/toys/stem-kits">STEM kits</a></li>
    <li aria-hidden="true" class="breadcrumb__sep">…chevron…</li>
    <li><a href="/toys/stem-kits/robot-building-kit" aria-current="page">Robot building kit</a></li>
  </ol>
</nav>
```

- `<nav aria-label="Breadcrumb">` wrapping an **ordered** list — the order is the meaning.
- The current page is a link to itself with `aria-current="page"`. Keeping it a link (rather than a bare `<span>`) means a long path's last item is still a tap target for "reload / scroll to top", and `aria-current` is what conveys the state.
- Separators are `aria-hidden="true"` and are CSS/SVG, never text characters like `/` or `>` in the DOM — a screen reader reading `slash` between every level is unusable.
- **JSON-LD `BreadcrumbList`** is emitted on every category and product page, matching the visible breadcrumb exactly including the collapsed levels (the structured data always contains the full path, even when the visible list is collapsed). This is a hard SEO requirement, and a mismatch between the visible and structured breadcrumb is a Search Console error.
- The `…` control's `aria-label` includes the count (`Show 2 more levels`) so its purpose is clear without opening it.
- On the mobile `icon/home` form, the home item keeps an accessible name (`Home` / `হোম`) via `aria-label`; an icon-only breadcrumb root with no name is a common failure.

---

## 23. `Pagination`

### 23.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Variant` | `Numbered`, `LoadMore`, `Compact` | 3 |
| **Total** | | **3** |

Child set **`Pagination/Page`**: axis `State=Default|Current|Disabled` (3).

- `Numbered` — full page list. **Category, brand and blog listings.** Required for SEO: crawlers need real paginated URLs.
- `LoadMore` — a single button appending the next page. **Search results and infinite rails only**, where crawl depth does not matter.
- `Compact` — `Prev · Page 3 of 18 · Next`. Mobile form of `Numbered` under 480px.

### 23.2 Component properties

**`Pagination/Page`:**

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `3` | `Label` |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

**`Pagination` (frame):**

| Property | Type | Default | Bound to |
|---|---|---|---|
| `loadMoreLabel` | TEXT | `Show more products` | `LoadMore › label` |
| `statusText` | TEXT | `Showing 24 of 418 products` | `Status` |
| `showStatus` | BOOLEAN | `true` | `Status` visibility |
| `compactText` | TEXT | `Page 3 of 18` | `Compact › Text` |
| `loading` | BOOLEAN | `false` | `LoadMore › loading` |

### 23.3 Geometry

| Element | Value |
|---|---|
| Page item | **44 × 44** FIXED |
| Page item radius | `radius/full` |
| Page item text | `en/body-sm` 14 / 1.5 / 600, `tabular-nums` |
| Gap between items | `spacing/2` (8) |
| Prev / Next | `IconButton Size=Medium Variant=Ghost` (44 × 44), `icon/chevron-left` / `icon/chevron-right` at 20 |
| Prev/Next gap from the number run | `spacing/3` (12) |
| Ellipsis `…` | 44 × 44, non-interactive, `color/text/tertiary` |
| Row height | FIXED 44 |
| Status line | `en/body-sm` 14 / 1.5 / 400, `color/text/secondary`, centred, gap `spacing/3` (12) above the row |
| `LoadMore` button | `Button Variant=Outline Size=Large`, width **FILL** on mobile, FIXED **280** on desktop, centred |
| `LoadMore` status gap | `spacing/3` (12) |
| `Compact` text | `en/body-sm` 14 / 1.5 / 600, `tabular-nums`, min-width **112** FIXED |
| Section padding | `spacing/8` (32) above, `spacing/12` (48) below, on all variants |

`tabular-nums` on the page numbers and on `Page 3 of 18` so the row's width does not change between page 9 and page 10.

Page items are 44 × 44 and `radius/full` on the storefront; admin instances override to `radius/md` as with Button (§1.3).

### 23.4 Token bindings

| Element | State | Fill | Text | Border |
|---|---|---|---|---|
| `Pagination/Page` | `Default` | `None` | `color/text/primary` | none |
| | Hover | `color/bg/subtle` | `color/text/primary` | none |
| | Pressed | `color/bg/subtle` + `_scrim` 12% | `color/text/primary` | none |
| | Focus-visible | `None` | `color/text/primary` | `_focus` |
| | `Current` | `color/bg/brand` | `color/text/on-brand` | none |
| | `Disabled` | `None` | `color/text/tertiary` | none |
| Ellipsis | — | `None` | `color/text/tertiary` | none |
| Status | — | — | `color/text/secondary` | — |

The current page is `color/bg/brand` with `color/text/on-brand` — fill plus contrast, and it also carries `aria-current="page"` and weight 700, so the state is not conveyed by colour alone.

### 23.5 The page window

With `c` = current page and `n` = total pages, `Numbered` renders:

`[Prev] 1 … c−1 c c+1 … n [Next]`

| Rule | Detail |
|---|---|
| Always shown | Page 1, page `n`, and `c−1, c, c+1`. |
| Ellipsis | Inserted only where it replaces **two or more** omitted pages. Replacing a single page with `…` is strictly worse than showing it. |
| Near the ends | `c ≤ 4` renders `1 2 3 4 5 … n`; `c ≥ n−3` renders `1 … n−4 n−3 n−2 n−1 n`. The item count is therefore constant at **7 numbers + 2 chevrons**, so the row's width never changes as the user pages through. |
| `n = 1` | The whole component is not rendered. |
| `n ≤ 7` | All pages shown, no ellipsis. |
| Prev / Next at the ends | `aria-disabled="true"` (focusable, so a keyboard user can discover there is no previous page), `color/text/tertiary`, click handler returns early. Not `disabled`. |

Width check: 7 × 44 + 6 × 8 + 2 × 44 + 2 × 12 = **476px** — fits a 1280px content column with room, and **exceeds 390px**, which is why `Compact` exists.

### 23.6 `Compact` (mobile)

Under 480px, `Numbered` is replaced by:

`[Prev]  Page 3 of 18  [Next]`

Row: `Space between`, `FILL` width, 44 tall. The centre text is a non-interactive label with `min-width: 112px` (FIXED) so the two chevrons do not move between page 9 and page 10. Tapping the centre text does nothing — there is no page-jump dialog, because a numeric page jump on a toy catalogue is a feature nobody uses and it costs a modal, a keyboard and a validation path.

Total: 44 + 112 + 44 = 200px of content in a 358px row, with the chevrons pinned to the edges where the thumb is.

### 23.7 `LoadMore`

| Rule | Detail |
|---|---|
| Control | `Button Variant=Outline Size=Large`, `label = Show more products`, `loading` bound to the fetch state. FILL width on mobile. |
| Status line | **Required**, above the button: `Showing 24 of 418 products`. Without it the user has no idea how much is left, which is the main reason load-more feels worse than pagination. |
| Announcement | On append, an `aria-live="polite"` message: `24 more products loaded. Showing 48 of 418.` |
| Focus | After append, focus moves to the **first newly added item**'s heading, so a keyboard user continues from where the new content starts rather than from the button. |
| No auto-load on scroll | Infinite scroll is **not** used on any listing. It breaks the footer, breaks back-navigation restoration, and makes the page unusable with a screen reader. `LoadMore` is always an explicit button. |
| Exhausted | When all items are loaded, the button is replaced by the status line alone (`Showing all 418 products`), not a disabled button. |
| SEO | `LoadMore` listings also emit `rel="next"` paginated URLs in the head and are crawlable at `?page=n`; the button is a progressive enhancement over real pages. |

### 23.8 Auto-layout

```
Pagination (vertical, FILL × HUG, gap spacing/3, align Center, padding-top spacing/8, padding-bottom spacing/12)
├── Status (HUG × HUG, centred)                      [showStatus]
├── Row  [Numbered]  (horizontal, HUG × FIXED 44, gap spacing/2, counter align Center)
│   ├── Prev (IconButton Medium)
│   ├── Numbers (horizontal, HUG × FILL, gap spacing/2)
│   │   └── Pagination/Page ×7 | _ellipsis
│   └── Next (IconButton Medium)
├── Row  [Compact]   (horizontal, FILL × FIXED 44, Space between, counter align Center)
└── Row  [LoadMore]  (vertical, FILL × HUG, gap spacing/3, align Center)
    └── Button (Outline, Large, FILL on mobile / FIXED 280 desktop)
```

`Numbered`'s row is `HUG` and centred in a `FILL` parent. `Compact`'s row is `FILL` with `Space between`.

### 23.9 Bangla

**Strategy: numbers are Western and locale-invariant; the two labels GROW or WRAP.**

| Element | en | bn | Behaviour |
|---|---|---|---|
| Page numbers | `1`–`18` | `1`–`18` (**Western**, per §19.6 — they are data, and they appear in the URL) | Identical width. The 476px `Numbered` row is the same in both builds. |
| `Page 3 of 18` | 88px | `পৃষ্ঠা 3 / 18` 96px | GROW inside the 112px FIXED min-width; no change to the row. |
| `Show more products` | 156px | `আরও পণ্য দেখুন` 148px | GROW. Fits the FILL button on mobile and the 280px desktop button. |
| `Showing 24 of 418 products` | 186px | `418টি পণ্যের মধ্যে 24টি দেখানো হচ্ছে` 248px | WRAP to 2 lines at 358px. The status line is `FILL` × `HUG` and centred; the component's root is `HUG` height so the extra 24px is absorbed. |
| `Showing all 418 products` | 168px | `সব 418টি পণ্য দেখানো হচ্ছে` 196px | One line at 358px. |
| Prev / Next `aria-label` | `Previous page` / `Next page` | `পূর্ববর্তী পৃষ্ঠা` / `পরবর্তী পৃষ্ঠা` | No layout effect. |

The status line is the only element that wraps, and the 112px fixed centre and 44px fixed items mean no Bangla string can change the position of a control.

### 23.10 Touch target

| Element | Size | Verdict |
|---|---|---|
| `Pagination/Page` | 44 × 44 | Passes natively |
| Prev / Next | 44 × 44 | Passes natively |
| `LoadMore` | 52 tall × FILL | Passes natively |
| Ellipsis | 44 × 44 but `pointer-events: none` | n/a (non-interactive) |
| `Compact` centre text | 112 × 44, non-interactive | n/a |

Page items are 8px apart, so their 44px targets tile without overlap. Prev and Next are 12px from the number run.

### 23.11 Accessibility

```html
<nav aria-label="Pagination" class="pagination">
  <p class="pagination__status" aria-live="polite">Showing 24 of 418 products</p>
  <ul>
    <li><a href="?page=2" rel="prev" aria-label="Previous page">…</a></li>
    <li><a href="?page=1" aria-label="Page 1">1</a></li>
    <li><span aria-hidden="true">…</span></li>
    <li><a href="?page=3" aria-current="page" aria-label="Page 3, current page">3</a></li>
    …
    <li><a href="?page=4" rel="next" aria-label="Next page">…</a></li>
  </ul>
</nav>
```

- `<nav aria-label="Pagination">` around a list. One `nav` per page; when pagination appears above **and** below a grid, the second instance is `aria-label="Pagination (bottom)"`.
- **Real `<a href>` elements with real URLs** (`?page=3`), not buttons. This is non-negotiable: it is what makes the catalogue crawlable, middle-clickable and restorable on back-navigation. `rel="prev"` / `rel="next"` on the chevrons.
- `aria-current="page"` on the current page, plus an `aria-label` that says `current page` — `aria-current` alone is announced inconsistently on older Android TalkBack builds.
- Each number's `aria-label` is `Page 3`, not bare `3`, so it is not read as a quantity.
- The ellipsis is `aria-hidden="true"` and non-focusable.
- The status line is the component's `aria-live="polite"` region and is the single place page changes are announced; the grid itself does not announce.
- Keyboard: no custom key handling at all. These are links; `Tab` and `Enter` are the whole model. Arrow keys are left to the browser for scrolling.

---

## 24. `Alert`

### 24.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Type` | `Info`, `Success`, `Warning`, `Danger` | 4 |
| `Variant` | `Inline`, `Banner` | 2 |
| **Total** | | **8** |

`Inline` sits inside a form or a card (bordered, radius, HUG width). `Banner` spans the full viewport width (no side radius, no side border, used for site-wide notices and the checkout error summary).

### 24.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `title` | TEXT | `We could not place your order` | `Title` |
| `showTitle` | BOOLEAN | `true` | `Title` visibility |
| `body` | TEXT | `Your card was declined. Try another payment method or choose cash on delivery.` | `Body` |
| `showBody` | BOOLEAN | `true` | `Body` visibility |
| `iconSwap` | INSTANCE_SWAP | per `Type` (§24.4) | `Icon › icon` |
| `actionsSlot` | INSTANCE_SWAP | `Alert/Actions` | `Actions` |
| `showActions` | BOOLEAN | `false` | `Actions` visibility |
| `dismissible` | BOOLEAN | `false` | `Dismiss` visibility |
| `dismissAriaLabel` | TEXT | `Dismiss` | `Dismiss › _aria-label` |

### 24.3 Geometry

| Element | `Inline` | `Banner` |
|---|---|---|
| Width | `FILL` | `FILL` (full viewport) |
| Height | `HUG`, `Min height` 56 | `HUG`, `Min height` 56 |
| Padding | `spacing/4` (16) all sides | X = page gutter (16 / 24 / 32), Y `spacing/4` (16) |
| Radius | **`radius/md` (10)** | 0 |
| Border | 1px, all edges | 1px top and bottom only |
| Gap (icon → text → dismiss) | `spacing/3` (12) | `spacing/3` (12) |
| Icon | **20**, stroke 2px | 20 |
| Title text | `en/body` 15 / 1.6 / **700** | same |
| Title → body gap | `spacing/1` (4) | same |
| Body text | `en/body-sm` 14 / 1.5 / 400 | same |
| Actions gap above | `spacing/3` (12) | same |
| Actions | `Button Variant=Outline Size=Small` + optional `Variant=Ghost Size=Small`, gap `spacing/2` | same |
| Dismiss | `IconButton Size=Medium Variant=Ghost` (44 × 44), `icon/x` at 20 | same |
| Icon alignment | `Top`, with `padding-top: calc((1lh - 20px) / 2)` so it centres on the title's first line | same |

**`radius/md`, not `radius/lg` or `radius/2xl`.** An alert — and especially a `Danger` alert in a form — is a credibility surface. The playful radii belong to product cards. This is the same reasoning as `Tooltip` (§17.3), and it is a rule the lead should expect to defend.

### 24.4 Token bindings

| Type | Fill | Border | Icon | Icon glyph | Title | Body |
|---|---|---|---|---|---|---|
| `Info` | `color/bg/surface` | 1px `color/feedback/info` | `color/feedback/info` | `icon/info` | `color/text/primary` | `color/text/secondary` |
| `Success` | `color/bg/surface` | 1px `color/feedback/success` | `color/feedback/success` | `icon/check-circle` | `color/text/primary` | `color/text/secondary` |
| `Warning` | `color/bg/surface` | 1px `color/feedback/warning` | `color/feedback/warning` | `icon/alert-triangle` | `color/text/primary` | `color/text/secondary` |
| `Danger` | `color/bg/surface` | 1px `color/feedback/danger` | `color/feedback/danger` | `icon/alert-circle` | `color/text/primary` | `color/text/secondary` |

**Surface fill, coloured border, coloured 20px icon, neutral text.** Four reasons this is the pattern rather than a tinted background:

1. The semantic palette has no `bg/feedback/*-subtle` tokens, and inventing four would be four tokens plus eight dark-mode values to serve a decorative tint.
2. Coloured text on a coloured tint is the pattern most likely to fail contrast; neutral text on `color/bg/surface` is 16.1:1 for the title and 7.0:1 for the body, in both modes, with no per-type verification needed.
3. The icon at 20px with a 2px stroke needs 3:1 as a non-text graphic; all four feedback colours clear that against white (`info` 5.2:1, `success` 3.4:1, `warning` 3.1:1, `danger` 4.8:1).
4. In dark mode the `e1`/`e2` border substitution (source spec §3.3) means bordered surfaces are already the house style.

An alert's meaning is therefore carried by **icon shape + icon colour + border colour + the title's words** — four channels, of which only two are colour. WCAG 1.4.1 satisfied without relying on the border.

No hover, pressed or focus states on the alert itself; the dismiss button and the action buttons own theirs.

### 24.5 Behaviour and the form-error rule

| Rule | Detail |
|---|---|
| `Info` / `Success` | `role="status"`, `aria-live="polite"` |
| `Warning` / `Danger` | `role="alert"` (implicitly assertive) |
| Dismissal | `dismissible` alerts fade out over `fast` 120ms. No slide, no collapse-height animation — a collapsing alert moves the whole form under the user's finger. The space is reclaimed instantly after the fade. |
| Auto-dismiss | **Never.** An `Alert` is persistent by definition; a self-dismissing message is a `Toast` (§25). |
| Placement | Immediately above the element it concerns, inside the same scroll container, never pinned. |
| Focus on appear | A `Danger` alert raised by a failed submit receives focus (`tabindex="-1"` + `.focus()`), so the first thing a keyboard or screen-reader user encounters is the explanation. `Info` and `Success` alerts never steal focus. |
| Animation on appear | opacity 0→1 over `fast` 120ms. No slide-down, no scale, no spring. Reduced motion: instant. |

> **The form-error contract.** `Type=Danger` on a form is the single most constrained surface in this design system:
> - `radius/md` (10). Never `radius/xl` or `radius/2xl`.
> - **No illustration, no emoji, no mascot, no exclamation marks.** Illustration is permitted in exactly four places in this system (empty states, 404, no-results, order-success — source spec §3.4) and a form error is not one of them.
> - No colour celebration on recovery: fixing the error removes the alert; nothing turns green and nothing animates.
> - The title names the failure, the body names the fix: `We could not place your order` / `Your card was declined. Try another payment method or choose cash on delivery.` Never `Oops!`, never `Something went wrong`, never `Please try again later` with no reason.
> - On a multi-field validation failure the body contains an `<ul>` of in-page links, one per invalid field, each link's text being the field's label; activating a link focuses that field. The per-field inline errors (§3.6) remain.
> - The form's state survives the error. This is a code requirement, stated here because the alert is where the user discovers whether it did.

### 24.6 Auto-layout

```
Alert (horizontal, FILL × HUG min 56, counter align Top, padding per Variant, gap spacing/3,
       radius per Variant, border per Type, Clip content false)
├── IconWrapper (20 × HUG, padding-top calc)
│   └── Icon (20)
├── TextBlock (vertical, FILL × HUG, gap spacing/1)
│   ├── Title   (FILL × HUG, wrap)
│   ├── Body    (FILL × HUG, wrap)
│   └── Actions (horizontal, HUG × HUG, gap spacing/2, padding-top spacing/3)  [showActions]
└── Dismiss (44 × 44)  [dismissible]
```

Counter alignment `Top` on the root, with the icon's `padding-top: calc((1lh - 20px) / 2)` centring it on the title's first line — the same mechanism as `Checkbox` (§7.3), and it adapts to the `bn` line-height automatically. The dismiss button is top-aligned, not centred, so it does not float to the middle of a four-line Bangla alert.

### 24.7 Bangla

**Strategy: WRAP — title 2 lines, body unbounded.**

| Element | en | bn | Lines at 358px |
|---|---|---|---|
| Title `We could not place your order` | 29 chars | `আমরা আপনার অর্ডারটি সম্পন্ন করতে পারিনি` 39 chars | 1 → **2** |
| Body `Your card was declined. Try another payment method or choose cash on delivery.` | 78 chars | `আপনার কার্ডটি বাতিল হয়েছে। অন্য একটি পেমেন্ট মাধ্যম বেছে নিন বা ক্যাশ অন ডেলিভারি নির্বাচন করুন।` 96 chars | 3 → **5** |

At `bn/body` 15 × 1.6 × 1.15 = 27.6px and `bn/body-sm` 14 × 1.5 × 1.15 = 24.2px, that Bangla alert is `2 × 27.6 + 4 + 5 × 24.2 + 32` (padding) = **208px** tall against **136px** in English — a 53% increase. The root is `FILL` × `HUG` so the form absorbs it; the surrounding checkout layout has no fixed-height rows precisely so this is free.

The icon column is 20px and the dismiss column is 44px in both scripts; only `TextBlock` (`FILL`) changes. Actions are `HUG` and sit below the body; on viewports under 480px with two actions and a Bangla label, the `Actions` row switches to `FILL` stacked buttons at gap `spacing/2` — documentation instance `Alert / bn narrow actions`, not a variant.

Title cap: **48 Bangla characters.** Body cap: **200 Bangla characters**; longer explanations belong on a page, not in an alert.

### 24.8 Touch target

The alert itself is not interactive. Its interactive children all meet 44×44 natively: `Dismiss` is `IconButton Size=Medium` (44 × 44), actions are `Button Size=Small` with `_hit44` (§1.8) — and because `Button Size=Small` is forbidden on storefront mobile, **storefront alert actions use `Size=Medium`** and only admin alerts use `Small`. The dismiss button is `spacing/3` (12) from the text block, so its hit area does not overlap an in-body link.

### 24.9 Accessibility

```html
<div class="alert alert--danger" role="alert" tabindex="-1">
  <svg aria-hidden="true" width="20" height="20">…</svg>
  <div>
    <p class="alert__title">We could not place your order</p>
    <p class="alert__body">Your card was declined. …</p>
    <ul class="alert__fields">
      <li><a href="#card-number">Card number</a></li>
    </ul>
  </div>
  <button type="button" class="icon-button" aria-label="Dismiss">…</button>
</div>
```

- `role="alert"` for `Warning`/`Danger`, `role="status"` for `Info`/`Success`. The role is on the **container that persists**, and the alert is rendered into a container that already exists in the DOM, so the announcement fires (same reasoning as §3.6 rule 2).
- The icon is `aria-hidden="true"` — its meaning is in the title's words.
- `tabindex="-1"` on `Danger` so it can receive programmatic focus after a failed submit.
- The type is never conveyed by colour alone: the title's wording states it, and the icon shape differs per type (`info` circle-i, `check-circle`, `alert-triangle`, `alert-circle`) so it is distinguishable in greyscale and in Windows High Contrast Mode, where the border colour is overridden by the system.
- An alert containing links has them as real `<a href="#field-id">`; activating one moves focus to that field (`.focus()` on the input, not just a hash change, which only scrolls).
- Dismissing returns focus to the element that preceded the alert in the DOM, never to `<body>`.

---

## 25. `Toast`

### 25.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Type` | `Info`, `Success`, `Warning`, `Danger` | 4 |
| `HasAction` | `False`, `True` | 2 |
| **Total** | | **8** |

### 25.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `message` | TEXT | `Added to cart` | `Message` |
| `detail` | TEXT | `Robot building kit · 1 item` | `Detail` |
| `showDetail` | BOOLEAN | `false` | `Detail` visibility |
| `iconSwap` | INSTANCE_SWAP | per `Type` (as §24.4) | `Icon › icon` |
| `actionLabel` | TEXT | `View cart` | `Action › label` |
| `dismissAriaLabel` | TEXT | `Dismiss` | `Dismiss › _aria-label` |

### 25.3 Geometry

| Element | Mobile (< 768px) | Desktop (≥ 768px) |
|---|---|---|
| Width | `FILL` minus 2 × 16 gutter (**358** at 390px) | **FIXED 360** |
| Height | `HUG`, `Min height` **56** | same |
| Padding | X `spacing/4` (16), Y `spacing/3` (12) | same |
| Radius | `radius/lg` (14) | `radius/lg` (14) |
| Border | 1px `color/border/default` | same |
| Elevation | `e3` | `e3` |
| Gap | `spacing/3` (12) | same |
| Icon | 20, stroke 2px | same |
| Message text | `en/body` 15 / 1.6 / 600 | same |
| Detail text | `en/body-sm` 14 / 1.5 / 400 | same |
| Message → detail gap | `spacing/1` (4) | same |
| Action | `Button Variant=Ghost Size=Small`, right-aligned | same |
| Dismiss | `IconButton Size=Medium Variant=Ghost` (44 × 44) | same |
| Swipe grab area | full toast, 56 tall minimum | n/a (no swipe on pointer devices) |

### 25.4 Position and stacking

| Parameter | Mobile | Desktop |
|---|---|---|
| Anchor | **Bottom centre** | **Top right** |
| Side offset | 16 | 24 |
| Bottom / top offset | **72** = `MobileNav` height (56) + `spacing/4` (16) | 24 from the top |
| Stack direction | upward (newest nearest the bottom edge, i.e. nearest the thumb) | downward (newest nearest the top edge) |
| Gap between toasts | `spacing/2` (8) |
| Maximum visible | **3**. A fourth queues and appears as one is dismissed. |
| Above | the `MobileNav` tab bar, the sticky `AddToCartBar` and the `WhatsAppFab` — the toast container's `z-index` is the highest in the application, below only `Modal` and `Drawer` overlays. |
| Safe area | `bottom: calc(72px + env(safe-area-inset-bottom))` |
| Never covers | the `Add to cart` button. The 72px offset is measured from the nav; when the sticky `AddToCartBar` is present (PDP), the offset becomes **136** = 56 nav + 64 bar + 16. The toast container reads the bar's presence from a CSS custom property set by the layout. |

Mobile toasts sit at the bottom because that is where the thumb is and because the top of a 390px viewport is occupied by the sticky search header. Desktop toasts sit top-right because the bottom-right is the `WhatsAppFab` and because a desktop user's attention is at the top of the viewport.

### 25.5 Token bindings

| Element | Binding |
|---|---|
| Fill | `color/bg/surface-raised` |
| Border | 1px `color/border/default` |
| Shadow | `e3` |
| Message | `color/text/primary` |
| Detail | `color/text/secondary` |
| Icon | `color/feedback/info` / `success` / `warning` / `danger` per `Type` |
| Action label | `color/text/link` |

Same reasoning as `Alert` (§24.4): neutral surface, coloured icon, neutral text. A toast is small and ephemeral, so a coloured fill would be the loudest thing on the screen for four seconds.

No hover/pressed/focus on the toast; the action and dismiss buttons own theirs. Hovering a toast **pauses its dismiss timer** (and focusing anything inside it does the same) — WCAG 2.2.1.

### 25.6 Timing and dismissal

| `Type` / case | Duration |
|---|---|
| `Success`, `Info`, no action | **4,000ms** |
| `Success`, `Info`, with an action | **6,000ms** |
| `Warning` | **6,000ms** |
| `Danger` | **Never auto-dismisses.** Manual dismissal only. |
| Timer paused | on pointer hover, on focus within, and while the document is hidden (`visibilitychange`) — a toast must not expire in a backgrounded tab |
| Timer reset | never; pausing resumes rather than restarts |

| Parameter | Value |
|---|---|
| Enter (mobile) | `translateY(8px) → 0` + opacity 0→1, `base` 200ms `decelerate` |
| Enter (desktop) | `translateX(8px) → 0` + opacity 0→1, `base` 200ms `decelerate` |
| Exit | opacity 1→0 + `translateY/X(4px)`, `fast` 120ms |
| Stack reflow | remaining toasts move up/down over `base` 200ms `standard` |
| Reduced motion | **opacity only**, 120ms in and out; no translate; stack reflow is instant |
| Swipe to dismiss (touch) | horizontal swipe, threshold **25% of width** or velocity **0.5px/ms**; the whole 358 × 56 toast is the grab area; `touch-action: pan-y` so a vertical drag still scrolls the page |
| Swipe (pointer) | not enabled |

### 25.7 What a Toast is not for

| Case | Correct component |
|---|---|
| Form validation errors | `Input` inline error (§3.6) + `Alert Type=Danger` summary. **Never a toast** — it disappears before the user has finished reading, it is not adjacent to the field, and it cannot be re-read. |
| A blocking failure (payment declined) | `Alert Type=Danger` on the page, persistent. |
| A decision | `Modal` (§26). A toast carries at most one action and that action is never destructive. |
| Progress over 10s (bulk booking, export) | `ProgressBar Variant=Indeterminate` (§31) in place, with a status line. |
| Undo of a destructive action | Permitted: `Removed from cart` + `Undo`, `Type=Info`, 6,000ms. This is the only destructive-adjacent toast, and the undo window in the data layer is longer than the toast's lifetime so a missed toast is not a lost cart item. |
| Confirmation of a cart add | Permitted and primary: `Added to cart` + `View cart`, `Type=Success`, 6,000ms. |

### 25.8 Auto-layout

```
ToastRegion (vertical, HUG × HUG, gap spacing/2, fixed position, align per §25.4)
└── Toast (horizontal, FIXED 360 | FILL × HUG min 56, counter align Center,
           padding X spacing/4 / Y spacing/3, gap spacing/3, radius/lg, border 1px, e3)
    ├── Icon (20 × 20)
    ├── TextBlock (vertical, FILL × HUG, gap spacing/1)
    │   ├── Message (FILL × HUG, wrap, max 2 lines)
    │   └── Detail  (FILL × HUG, wrap, max 1 line)
    ├── Action  (HUG)   [HasAction=True]
    └── Dismiss (44 × 44)
```

Counter alignment `Center` (not `Top` as in `Alert`) because a toast's text is capped at two lines and a 20px icon centred against a two-line block reads correctly at that size. With `showDetail = true` and a wrapped Bangla message the root switches to `Top` with the icon's `padding-top: calc((1lh - 20px) / 2)`.

### 25.9 Bangla

**Strategy: WRAP — message 2 lines max, detail 1 line, then the detail is dropped.**

| Element | en | bn | Lines at 358px |
|---|---|---|---|
| `Added to cart` | 13 chars, 112px | `কার্টে যোগ হয়েছে` 16 chars, 134px | 1 → 1 |
| `View cart` (action) | 76px | `কার্ট দেখুন` 84px | 1 → 1 |
| `Removed from cart` | 142px | `কার্ট থেকে সরানো হয়েছে` 178px | 1 → 1 |
| `Could not add — only 3 left in stock` | 274px | `যোগ করা যায়নি — স্টকে মাত্র ৩টি আছে` 296px | 1 → **2** |
| `Robot building kit · 1 item` (detail) | 196px | `রোবট বিল্ডিং কিট · ১টি` 168px | 1 → 1 |

Available text width on mobile: 358 − 32 (padding) − 20 (icon) − 12 − 12 − 44 (dismiss) = **238px**; with an action instead of a dismiss, less. So a 296px Bangla message wraps to 2 lines, giving `2 × 27.6 + 24` = **79px** tall — above the 56 minimum, absorbed by `HUG`.

Rules for the squeeze:

- **Two lines is the hard cap for the message.** A three-line toast on a 390px viewport covers the add-to-cart button area and reads as a modal.
- When the message wraps to 2 lines, **the detail is not rendered** (`showDetail` is forced `false`). The detail is always redundant with the page the user is on.
- When both an action and a dismiss would be present, **the dismiss is dropped on mobile** (the swipe gesture and the auto-timer cover it); desktop keeps both. This reclaims 44 + 12 = 56px of text width, which is what makes the 296px Bangla message fit in two lines rather than three.
- Message cap: **56 Bangla characters.** Detail cap: **32.**

### 25.10 Touch target

| Element | Size | Verdict |
|---|---|---|
| Action (`Button Ghost Small`) | 36 tall, `_hit44` → 44 | Passes. **Exception to §1.8's mobile ban on `Size=Small`**, because a `Medium` action plus a dismiss plus two lines of Bangla does not fit a 358px toast. The hit area is a full 44 and the toast has no competing targets within 12px. |
| Dismiss | 44 × 44 | Passes (desktop only when an action is present) |
| Swipe area | 358 × ≥56 | Passes |
| The toast body | not a target — tapping the body does nothing. A whole-toast tap that navigates is a mis-tap generator next to a dismiss button. | n/a |

### 25.11 Accessibility

```html
<!-- Persistent in the DOM from first paint, empty -->
<div class="toast-region" role="region" aria-label="Notifications">
  <output aria-live="polite" aria-atomic="true"><!-- Info / Success toasts --></output>
  <div role="alert"><!-- Warning / Danger toasts --></div>
</div>
```

- **Two live regions, created at first paint and never unmounted.** `polite` for `Info`/`Success`, `role="alert"` for `Warning`/`Danger`. Toasts are rendered into the matching region. A region created at toast time does not announce (§3.6 rule 2).
- `aria-atomic="true"` so the whole toast is read, not a diff.
- **Focus is never moved to a toast.** Moving focus to an element that disappears in four seconds leaves the user's focus on `<body>`.
- The toast's interactive children **are** in the tab order while it is visible, reachable by `Tab` from wherever focus is; the dismiss timer pauses on focus (§25.6) so a keyboard user can reach the action. After the action is activated or the toast is dismissed, focus returns to where it was.
- `Esc` dismisses the newest toast when focus is inside the region; it does **not** dismiss toasts when focus is elsewhere (that would conflict with closing a `Select` or `Modal`).
- The icon is `aria-hidden`; the type is carried by the message's wording and by which live region it lands in.
- Cart quantity changes are announced by the **cart's own** `aria-live` region (the one in `MobileNav`'s badge), not by the toast — otherwise a single add announces twice.

---

## 26. `Modal`

### 26.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Size` | `Small`, `Medium`, `Large` | 3 |
| **Total** | | **3** |

No `Type` axis. A confirmation, a form and an image lightbox differ in their **content**, not their frame; the frame is three widths. The destructive-confirm behaviour is a property (`dismissible = false`), not a variant.

### 26.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `title` | TEXT | `Size & age guide` | `Header › Title` |
| `description` | TEXT | `Measurements are approximate` | `Header › Description` |
| `showDescription` | BOOLEAN | `false` | `Header › Description` visibility |
| `bodySlot` | INSTANCE_SWAP | `Modal/BodyPlaceholder` | `Body › Content` |
| `showHeader` | BOOLEAN | `true` | `Header` visibility |
| `showFooter` | BOOLEAN | `true` | `Footer` visibility |
| `showClose` | BOOLEAN | `true` | `Header › Close` visibility |
| `primaryLabel` | TEXT | `Confirm` | `Footer › Primary › label` |
| `secondaryLabel` | TEXT | `Cancel` | `Footer › Secondary › label` |
| `showSecondary` | BOOLEAN | `true` | `Footer › Secondary` visibility |
| `destructive` | BOOLEAN | `false` | `Footer › Primary` → `Variant=Danger`; `showClose` forced `false` |

### 26.3 Geometry

| Size | Desktop width (FIXED) | Use |
|---|---|---|
| `Small` | **400** | Confirmations, OTP entry, a single field |
| `Medium` | **560** | Forms, the size & age guide, address edit |
| `Large` | **720** | Image lightbox, variant matrix preview, long content |

| Element | Value |
|---|---|
| Max-height | **90vh** (`calc(100dvh - 2 * 24px)` on desktop, `90dvh` on mobile), body scrolls |
| Radius | **`radius/xl` (20)** |
| Fill | `color/bg/surface` |
| Elevation | `e4` |
| Border | none in light mode; 1px `color/border/default` in dark mode (per source spec §3.3) |
| Header padding | X `spacing/6` (24), top `spacing/6` (24), bottom `spacing/4` (16) |
| Header gap | `spacing/4` (16) |
| Title text | `en/h3` 18 / 1.3 / 600 (20 on desktop) |
| Description text | `en/body-sm` 14 / 1.5 / 400, `color/text/secondary`, gap `spacing/1` below the title |
| Close button | `IconButton Size=Medium Variant=Ghost` (44 × 44), `icon/x` at 20, top-right |
| Body padding | X `spacing/6` (24), Y 0 |
| Body gap | `spacing/4` (16) |
| Footer padding | X `spacing/6` (24), top `spacing/4` (16), bottom `spacing/6` (24) |
| Footer gap | `spacing/3` (12) |
| Scroll shadows | 1px `color/border/default` on the header's bottom edge and the footer's top edge, shown **only while the body is scrolled** away from that edge |

Mobile (< 768px): width is `FILL` with 16px gutters; **on viewports under 768px every `Modal` except a `destructive` confirm becomes a bottom `Drawer`** (§27). See §26.6.

### 26.4 Overlay

| Property | Value |
|---|---|
| Fill | `color/text/primary` at **48%** opacity |
| `backdrop-filter` | **none** |
| Enter | opacity 0 → 48% over `base` 200ms `standard` |
| Exit | opacity → 0 over `fast` 120ms |
| Reduced motion | instant |
| Click | closes the modal unless `destructive = true` or the body contains dirty form state |

`backdrop-filter: blur()` is **forbidden**. On a mid-range Android it forces a full-screen GPU readback every frame of the fade and is one of the cheapest ways to drop the modal's open animation to single-digit frame rates. A 48% neutral scrim is sufficient separation, and `color/text/primary` is used rather than pure black because the elevation system is warm-tinted (source spec §3.3).

### 26.5 Footer button layout

| Viewport | Layout |
|---|---|
| ≥ 768px | Horizontal, right-aligned (`Packed` + `Right`), gap `spacing/3`, order: `Secondary` then `Primary`. Both `HUG` width, `Size=Medium`. |
| < 768px | **Vertical, both `FILL` width, gap `spacing/2`, `Primary` on top, `Secondary` below.** `Size=Large`. |

Primary on top on mobile, not at the bottom: the thumb rests at the bottom of the screen and the bottom position is where a mis-tap is most likely, so the destructive-or-committing action is the one position **away** from the resting thumb. Stacked buttons are `FILL` so neither can be missed horizontally.

### 26.6 Mobile: Modal → Drawer

Under 768px, `Modal` renders as `Drawer Side=Bottom` (§27) with the same header/body/footer content and a bottom-sheet's radii and grab handle. Two exceptions stay centred modals:

1. **`destructive = true`** (ConfirmDialog). A destructive confirmation must not be dismissible by the same downward swipe that dismisses a filter sheet, and a centred dialog with no swipe affordance makes the commitment explicit.
2. **The image lightbox** (`Size=Large`), which is full-screen on mobile rather than either a modal or a sheet.

This is a responsive behaviour of one component, not two components. The lead builds `Modal / mobile sheet` as a documentation instance showing the `Drawer Side=Bottom` form.

### 26.7 Animation

| Phase | Desktop | Mobile (as a sheet) |
|---|---|---|
| Enter | `scale(.96) → 1` + opacity 0→1, `base` 200ms `standard`, transform-origin centre | `translateY(100%) → 0`, **240ms `decelerate`** |
| Exit | `scale(1) → .98` + opacity → 0, `fast` 120ms | `translateY(0 → 100%)`, `base` 200ms `standard` |
| Reduced motion | **opacity only**, 120ms; no scale, no translate | opacity only, 120ms |

No `spring`. A modal that bounces is a modal a parent does not trust with a payment.

### 26.8 Auto-layout

```
ModalOverlay (fixed, FILL × FILL, fill color/text/primary @48%, center/center, padding 24)
└── Modal (vertical, FIXED width per Size × HUG, max-height 90vh, radius/xl, e4,
           Clip content true)
    ├── Header (horizontal, FILL × HUG, counter align Top,
    │           padding X spacing/6 / top spacing/6 / bottom spacing/4, gap spacing/4)
    │   ├── TextBlock (vertical, FILL × HUG, gap spacing/1)
    │   │   ├── Title
    │   │   └── Description
    │   └── Close (44 × 44)
    ├── Body (vertical, FILL × FILL, padding X spacing/6, gap spacing/4, vertical scroll)
    │   └── Content (bodySlot)
    └── Footer (horizontal | vertical, FILL × HUG,
                padding X spacing/6 / top spacing/4 / bottom spacing/6, gap spacing/3)
        ├── Secondary (Button)
        └── Primary   (Button)
```

`Body` is the only `FILL`-height child, so it absorbs the available space and scrolls; `Header` and `Footer` are `HUG` and pinned. `Modal`'s height is `HUG` up to the 90vh cap, so a short modal is short.

### 26.9 Bangla

**Strategy: WRAP — title 2 lines, description 2 lines, body unbounded; widths unchanged.**

- Widths stay 400 / 560 / 720 in both builds. Widening a modal for Bangla would change the line-measure and require re-reviewing every body layout; growing vertically costs nothing because `Body` scrolls.
- `Size & age guide` → `সাইজ ও বয়স নির্দেশিকা` (21 chars) is one line at 400px minus 24px padding minus the 44px close button (332px of title width). The worst case, `Confirm you want to cancel this order` → `আপনি কি সত্যিই এই অর্ডারটি বাতিল করতে চান` (41 chars), wraps to **2 lines** at `bn/h3` (18 × 1.3 × 1.15 = 26.9px/line), making the header 16px taller. `Header` is `HUG` so it absorbs it; `Body`'s available height shrinks by 16px and it scrolls.
- Footer buttons: on desktop, `HUG` width buttons grow — `Cancel` (84px) → `বাতিল করুন` (104px), `Confirm` (96px) → `নিশ্চিত করুন` (124px); total 228 + 12 gap = 240px inside a 352px footer at `Size=Small`. Fits. On mobile both are `FILL` and wrap internally per §1.7.
- Title cap: **48 Bangla characters.**

### 26.10 Touch target

| Element | Size | Verdict |
|---|---|---|
| Close | 44 × 44 | Passes |
| Footer buttons (desktop) | 44 tall × ≥96 | Passes |
| Footer buttons (mobile) | 52 tall × FILL | Passes |
| Overlay | the full remaining viewport | Passes (and is a dismiss affordance except when `destructive`) |

The close button is `spacing/4` (16) from the title block, so its 44px area does not overlap a long title's last line.

### 26.11 Accessibility

```html
<div class="overlay" data-state="open">
  <div role="dialog" aria-modal="true"
       aria-labelledby="m-title" aria-describedby="m-desc" tabindex="-1">
    <h2 id="m-title">Size &amp; age guide</h2>
    <p id="m-desc">Measurements are approximate</p>
    …
  </div>
</div>
```

| Requirement | Detail |
|---|---|
| Role | `role="dialog"` + `aria-modal="true"`. `aria-labelledby` → the title, always. A modal with no accessible name is a defect. `aria-describedby` → the description when present. |
| Focus on open | Moves to the **dialog container** (`tabindex="-1"`), not to the first input and not to the close button. Landing on the container causes the title and description to be announced before the content; landing on an input skips them. Exception: an OTP modal focuses the OTP field, because its entire content is that field and the title is also its label. |
| Focus trap | `Tab` and `Shift+Tab` cycle within the dialog. Implemented with a real trap, not with `tabindex` juggling. |
| Background | `inert` on the main application element (with the `inert` polyfill for older Android WebView), **in addition to** the focus trap — `aria-hidden` alone leaves the background clickable, and `inert` alone is not universally supported. |
| Focus on close | Returns to the element that opened the modal. If that element no longer exists (a row that was deleted), focus goes to the nearest surviving landmark heading, never to `<body>`. |
| `Esc` | Closes, unless `destructive = true`. A destructive confirm requires an explicit choice — but it must still be escapable, so `Esc` on a destructive confirm activates the **secondary** (`Cancel`) action rather than being swallowed. Swallowing `Esc` entirely fails WCAG 2.1.2 (No Keyboard Trap). |
| Scroll lock | `overflow: hidden` on `<html>` plus a scroll-position restore on close. `position: fixed` on `<body>` is not used — it loses the scroll position on Android Chrome. |
| Scrollable body | `tabindex="0"` on `Body` when it overflows, so a keyboard user can scroll it with arrow keys without a focusable child inside. |
| Destructive confirmation | Per the source spec, destructive actions require **typing the order number or product name** into a confirmation field. That field lives in the body; the primary button stays `disabled` (genuinely disabled, not `aria-disabled` — there is nothing to explain beyond the field's own label) until the typed value matches exactly. |
| Nesting | Modals do not nest. A flow needing two steps uses one modal with `Stepper` (§30) inside it. |

---

## 27. `Drawer`

### 27.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Side` | `Right`, `Left`, `Bottom` | 3 |
| `Size` | `Small`, `Medium`, `Large` | 3 |
| **Total** | | **9** |

- `Side=Right` — desktop cart, desktop filter panel, admin detail panes. The default.
- `Side=Left` — the storefront's mobile navigation drawer and the admin's off-canvas sidebar only.
- `Side=Bottom` — **every mobile sheet**: filters, sort, variant pickers, the mobile form of `Modal` (§26.6), the mobile form of `Popover` (§28.6).

### 27.2 Component properties

Same as `Modal` (§26.2), plus:

| Property | Type | Default | Bound to |
|---|---|---|---|
| `showHandle` | BOOLEAN | `true` on `Side=Bottom`, `false` otherwise | `Handle` visibility |
| `footerSticky` | BOOLEAN | `true` | `Footer` pinned vs. scrolling with the body |

### 27.3 Geometry — `Side=Right` and `Side=Left` (desktop, ≥768px)

| Size | Width (FIXED) | Use |
|---|---|---|
| `Small` | **400** | Mobile nav (`Left`), sort |
| `Medium` | **480** | Cart, filter panel |
| `Large` | **640** | Admin detail pane, order edit |

| Element | Value |
|---|---|
| Height | `FILL` — **100dvh**, pinned to both the top and bottom viewport edges |
| Radius | `radius/xl` (20) on the **two inner corners only** — top-left and bottom-left for `Side=Right`; top-right and bottom-right for `Side=Left`. The outer edge is square because it meets the viewport. |
| Fill | `color/bg/surface` |
| Elevation | `e4` |
| Header padding | X `spacing/6` (24), top `spacing/6`, bottom `spacing/4` |
| Body padding | X `spacing/6`, Y 0 |
| Footer padding | X `spacing/6`, top `spacing/4`, bottom `spacing/6` |
| Enter | `translateX(100% → 0)` (`Right`) / `translateX(-100% → 0)` (`Left`), **240ms `decelerate`** |
| Exit | reverse, **200ms `standard`** |
| Reduced motion | opacity only, 120ms; no translate |
| Drag to dismiss | not enabled on pointer devices |

### 27.4 Geometry — `Side=Bottom` (mobile bottom sheet)

| Size | Height | Use |
|---|---|---|
| `Small` | `HUG`, `Max height` **50dvh** | Sort, a short variant picker |
| `Medium` | `HUG`, `Max height` **85dvh** | Filters, the mobile form of `Modal` |
| `Large` | **90dvh** FIXED | Full-content sheets (size guide, delivery estimator detail) |

| Element | Value |
|---|---|
| Width | `FILL` — the full viewport width, **no side gutters** (the sheet's edges meet the screen's edges) |
| Radius | **`radius/2xl` (28)** on the top two corners; bottom corners 0 |
| Fill | `color/bg/surface` |
| Elevation | `e4` |
| Grab handle | **40 × 4**, `radius/full`, fill `color/border/strong`, horizontally centred, with `spacing/3` (12) above and `spacing/3` (12) below |
| Header padding | X `spacing/4` (16), top 0 (the handle's 12px supplies it), bottom `spacing/4` |
| Body padding | X `spacing/4` (16), Y 0 |
| Footer padding | X `spacing/4`, top `spacing/4`, bottom `calc(spacing/4 + env(safe-area-inset-bottom))` |
| Footer | **sticky**, `color/bg/surface`, 1px `color/border/default` top border, holding a `FILL`-width `Button Size=Large` |
| Enter | `translateY(100% → 0)`, **240ms `decelerate`** |
| Exit | `translateY(0 → 100%)`, **200ms `standard`** |
| Reduced motion | opacity only, 120ms; no translate |
| Drag to dismiss | **enabled** — see §27.5 |

`radius/2xl` (28) on a bottom sheet versus `radius/xl` (20) on a side drawer and a modal: the bottom sheet is the most-used mobile surface on the site and the larger radius is where this design system's playfulness is allowed to show in a utility surface. It costs nothing in usability and it is the one place the sheet reads as a toy-shop rather than a form.

Side gutters are **zero** on the bottom sheet (unlike `Toast`, which has 16px). A sheet that floats with side margins has an ambiguous bottom edge against the safe area and wastes 32px of a 390px viewport on the screen where the filter facets need it most.

### 27.5 Drag to dismiss (`Side=Bottom` only)

| Parameter | Value |
|---|---|
| Grab area | the **handle plus the header** — the full sheet width by (12 + 4 + 12 + header height) ≈ 84px. **Not the body**, which must scroll. |
| Dismiss threshold | drag distance > **25% of the sheet's height**, **or** release velocity > **0.5px/ms** downward |
| Below threshold | the sheet springs back to its open position over `base` 200ms `standard` |
| Overlay coupling | the overlay's opacity tracks the drag: `48% × (1 − progress)` |
| Upward drag | resisted (rubber-banding at 0.3× past the open position); the sheet does not expand to a larger size by dragging — there are no snap points. One sheet, one height. |
| `touch-action` | `none` on the handle/header, `pan-y` on the body |
| Body scroll interaction | when the body is scrolled to its top and the user drags down **on the body**, the sheet does **not** follow. Dragging the sheet requires the handle or header. This is deliberate: chaining body-scroll into sheet-dismissal makes a filter list feel like it is about to close every time the user scrolls up. |
| Reduced motion | drag still works (it is direct manipulation, not animation), but the spring-back and the dismiss are opacity-only transitions |

No snap points and no expandable sheet. A multi-height sheet needs gesture arbitration against the inner scroller, and on a mid-range Android that arbitration is where the jank lives.

### 27.6 Overlay

Identical to `Modal` §26.4: `color/text/primary` at 48%, no `backdrop-filter`, 200ms in / 120ms out, click to close (except `destructive`). On `Side=Bottom` the overlay's opacity additionally tracks the drag (§27.5).

### 27.7 Responsive pairing — the single rule

> **One component, chosen by viewport: `Side=Right` at ≥768px, `Side=Bottom` below 768px.**

| Surface | ≥768px | <768px |
|---|---|---|
| Cart | `Side=Right Size=Medium` (480) | `Side=Bottom Size=Medium` (85dvh) |
| Filters | not a drawer — a persistent left sidebar in the page (≥1024px); `Side=Right Size=Medium` at 768–1023px | `Side=Bottom Size=Medium` |
| Sort | `Popover` (§28) | `Side=Bottom Size=Small` |
| Navigation | not a drawer — the full mega menu | `Side=Left Size=Small` |
| `Modal` content | centred `Modal` (§26) | `Side=Bottom Size=Medium` |
| Admin sidebar | icon rail (768–1023px) / expanded (≥1024px) | `Side=Left Size=Small` |

The component's internal content is identical across the pair; only `Side`, `Size`, the radii, the padding scale (`spacing/6` desktop → `spacing/4` mobile) and the handle change. The lead builds the pair as two instances on one board row labelled `Drawer / responsive pair` so the equivalence is visible.

### 27.8 Auto-layout

```
DrawerOverlay (fixed, FILL × FILL, fill color/text/primary @48%,
               align per Side: Right→Right/Center, Left→Left/Center, Bottom→Center/Bottom)
└── Drawer (vertical, Clip content true, radius per Side, e4, fill color/bg/surface)
    │   Right/Left:  FIXED width per Size × FILL height
    │   Bottom:      FILL width × HUG height (max per Size)
    ├── Handle (FILL × HUG, padding Y spacing/3, align Center)   [Side=Bottom]
    │   └── _bar (40 × 4, radius/full)
    ├── Header (horizontal, FILL × HUG, counter align Top, padding per Side, gap spacing/4)
    │   ├── TextBlock (vertical, FILL × HUG, gap spacing/1)
    │   └── Close (44 × 44)
    ├── Body (vertical, FILL × FILL, padding X per Side, gap spacing/4, vertical scroll)
    └── Footer (vertical | horizontal, FILL × HUG, padding per Side,
                border-top 1px color/border/default, fill color/bg/surface)
```

`Body` is the only `FILL`-height child in all three `Side` values, so it is always the scroller and the header/footer are always pinned. On `Side=Bottom` the drawer's height is `HUG` up to the max, so a three-option sort sheet is 240px tall rather than 85dvh of empty space.

### 27.9 Bangla

**Strategy: widths unchanged; headers and footer labels WRAP; bodies grow and scroll.**

| Element | en | bn | Behaviour |
|---|---|---|---|
| `Filters` (header) | 62px | `ফিল্টার` 68px | GROW, one line. |
| `Clear all` (header action) | `Button Ghost Small`, 96px | `সব মুছুন` 92px | GROW, one line. |
| `Show 128 results` (sticky footer) | 164px | `128টি ফলাফল দেখুন` 176px | GROW inside a `FILL` `Button Size=Large`; one line at 358px. |
| `Your cart` | 86px | `আপনার কার্ট` 102px | GROW. |
| `Delivery charge by district` | 232px | `জেলা অনুযায়ী ডেলিভারি চার্জ` 254px | WRAP to 2 lines at `Side=Bottom` (available header width = 358 − 32 − 44 = 282px). Header is `HUG`; absorbs 27px. |
| Filter facet labels (in the body) | — | up to 40% longer | WRAP to 2 lines; each facet row is `FILL` × `HUG` with `Min height` 44. The body scrolls. |

Side-drawer widths are **not** widened for Bangla: 480px with `spacing/6` padding gives a 432px measure, and the longest Bangla facet label (`শিক্ষামূলক ও বিজ্ঞানভিত্তিক খেলনা`, 33 chars, 296px) fits on one line with a count and a checkbox. The bottom sheet's 85dvh max is also unchanged; Bangla simply means more scrolling, which is correct.

Header title cap: **40 Bangla characters.** Sticky footer button cap: **24.**

### 27.10 Touch target

| Element | Size | Verdict |
|---|---|---|
| Grab handle | visual 40 × 4; **hit area the full sheet width × 28** (the handle's padding block), and the header above it is also draggable, giving ≈84px | Passes (the 28px band exceeds 44 when combined with the header; the handle alone is specified with a `_hit44`-style 44px-tall band centred on the bar) |
| Close | 44 × 44 | Passes |
| Sticky footer button | 52 tall × FILL | Passes |
| Facet rows in the body | `Min height` 44 | Passes |
| Overlay | the remaining viewport (≥15dvh on a `Large` bottom sheet = 97px at 650dvh) | Passes |

The handle's hit band is 44px tall, centred on the 4px bar, which overlaps the header's top padding — acceptable, because the header's own content starts below it and the header is also a drag surface.

### 27.11 Accessibility

| Requirement | Detail |
|---|---|
| Role | `role="dialog"` + `aria-modal="true"` + `aria-labelledby` → the header title. Same contract as `Modal` (§26.11): focus to the container, focus trap, `inert` on the background, focus returned on close, `Esc` closes, scroll lock on `<html>`. |
| Navigation drawer exception | `Side=Left` for the storefront nav is **also** `aria-modal="true"` with a trap. A nav drawer that leaves the page behind it focusable lets a keyboard user tab into content hidden under an opaque overlay. |
| Handle | `aria-hidden="true"`. It is a touch affordance; keyboard and screen-reader users close with `Esc` or the close button, both of which are always present on `Side=Bottom` even when the handle is shown. **The handle is never the only dismiss affordance.** |
| Drag | the gesture has a keyboard equivalent (`Esc`) and a pointer equivalent (the close button and the overlay), so it is an enhancement, not a requirement — WCAG 2.5.7 (Dragging Movements). |
| Sticky footer live count | `Show 128 results` updates as facets change; the count lives in the filter panel's `aria-live="polite"` region so each facet toggle announces `128 products` once. The button's accessible name updates with it. |
| Nested scrollers | `overscroll-behavior: contain` on `Body`, so reaching its end does not scroll the page behind the sheet. |
| `dvh` not `vh` | All heights use `dvh`. On Android Chrome, `vh` ignores the retracting URL bar and a `85vh` sheet is taller than the visible viewport, hiding its own sticky footer. |

---

## 28. `Popover`

### 28.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Side` | `Top`, `Bottom`, `Left`, `Right` | 4 |
| `Align` | `Start`, `Center`, `End` | 3 |
| **Total** | | **12** |

`Align` is an axis here (unlike `Tooltip`, §17.1) because a popover is wide and is frequently anchored to an edge control — the account menu at the top-right of the header needs `Side=Bottom Align=End` so its right edge lines up with its trigger rather than overhanging the viewport.

### 28.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `title` | TEXT | `Why is this unavailable?` | `Title` |
| `showTitle` | BOOLEAN | `false` | `Title` visibility |
| `contentSlot` | INSTANCE_SWAP | `Popover/ContentPlaceholder` | `Content` |
| `showArrow` | BOOLEAN | `true` | `Arrow` visibility |
| `showClose` | BOOLEAN | `false` | `Close` visibility |

### 28.3 Geometry

| Element | Value |
|---|---|
| Min-width | **200** |
| Max-width | **320** |
| Width | `HUG` within those bounds; `FIXED` when the content is a menu (then it matches the trigger's width or 240, whichever is larger) |
| Height | `HUG`, `Max height` **320**, then vertical scroll |
| Padding | `spacing/4` (16) all sides; **0** when the content is a menu (the menu items supply their own padding) |
| Radius | `radius/lg` (14) |
| Fill | `color/bg/surface-raised` |
| Border | 1px `color/border/default` |
| Elevation | `e3` |
| Title text | `en/h4` 16 / 1.35 / 600, `color/text/primary`, gap `spacing/2` below |
| Content text | `en/body-sm` 14 / 1.5 / 400, `color/text/primary` |
| Menu item (when a menu) | 44 FIXED tall, padding X `spacing/3` (12), gap `spacing/3`, radius `radius/sm`, text `en/body` 15 |
| Arrow | **12 wide × 6 deep**, same fill, border on the two outer edges |
| Offset from trigger | **`spacing/2` (8)**, trigger edge to popover edge (the arrow sits inside it) |
| Viewport inset on collision | **16** |
| Close button | `IconButton Size=Small Variant=Ghost` (36 + `_hit44`), top-right, `showClose` |

### 28.4 Token bindings

| Element | Binding |
|---|---|
| Fill | `color/bg/surface-raised` |
| Border | 1px `color/border/default` |
| Shadow | `e3` |
| Title | `color/text/primary` |
| Content | `color/text/primary` |
| Menu item (default) | fill `None`, text `color/text/primary` |
| Menu item (hover) | fill `color/bg/subtle` |
| Menu item (focus-visible) | `_focus` at `radius/sm` |
| Menu item (destructive) | text `color/feedback/danger`, icon `color/feedback/danger`, hover fill `color/bg/subtle` |
| Divider between menu groups | 1px `color/border/default`, `spacing/2` above and below |

No state axis on the popover frame — it is open or it is not. The trigger and the menu items own their states.

### 28.5 Behaviour — and how it differs from `Tooltip`

| | `Tooltip` (§17) | `Popover` |
|---|---|---|
| Opened by | hover (300ms) or focus (0ms) | **click / tap only** |
| Contains interactive content | **never** | **yes** — links, buttons, form fields, menus |
| In the tab order | no | yes, and focus moves into it on open |
| Exists on touch devices | **no** | **yes** — this is the point |
| Dismiss | `Esc`, pointer leave, blur, scroll | `Esc`, outside click/tap, trigger re-click, route change |
| Role | `role="tooltip"`, `aria-describedby` | `role="dialog"` (non-modal) or `role="menu"`, `aria-controls` + `aria-expanded` |

| Parameter | Value |
|---|---|
| Open delay | **0ms** — it is a click, not a hover |
| Enter | opacity 0→1 + 4px translate toward the trigger, `fast` 120ms `decelerate` |
| Exit | opacity → 0, `fast` 120ms |
| Reduced motion | opacity only, 120ms, no translate |
| Collision | flip `Side`, then shift along the cross-axis to stay 16px inside the viewport; `Align` is preserved where possible and relaxed to `Center` if not |
| Portal | document root, above the sticky bars; inside a `Drawer`/`Modal`, portalled into that container so the scroll lock does not clip it |
| Singleton | one popover at a time; opening a second closes the first. Popovers do not nest. |
| Focus on open | moves to the popover container (`tabindex="-1"`) for a content popover, or to the **first menu item** for a menu popover |
| Focus trap | **none.** A popover is non-modal: `Tab` moves through its content and then **out**, closing the popover. This is the correct behaviour for a menu and is what distinguishes it from a `Modal`. |
| Focus on close | returns to the trigger |
| Background | **not** `inert`. The page behind a popover stays interactive, and clicking it closes the popover and delivers the click. |

### 28.6 Mobile: Popover → Drawer

> **On viewports under 480px, a `Popover` whose content holds more than one interactive element renders as `Drawer Side=Bottom Size=Small`** (§27).

Reasons: a 320px-wide floating panel inside a 390px viewport has 35px of total margin and collides with the safe area; its outside-tap dismissal competes with the page scroll; and a menu of 44px rows reads better as a sheet, where the rows are full-width and the thumb reaches them.

Popovers that **stay** popovers on mobile: single-purpose, non-menu content — the `Breadcrumb` overflow list (§22.5, 2–3 links, which is below the threshold only because it is itself a short list; it is specified as a popover because a bottom sheet for two breadcrumb levels is disproportionate) and the `Tooltip` touch fallback (§17.6), which has no interactive content at all.

The threshold is "more than one interactive element", measured at design time per instance, not at runtime.

### 28.7 Auto-layout

```
Popover (vertical, HUG × HUG, min-w 200, max-w 320, max-h 320,
         padding spacing/4 | 0, gap spacing/2, radius/lg, border 1px, e3, Clip content false)
├── Header (horizontal, FILL × HUG, gap spacing/2)   [showTitle || showClose]
│   ├── Title (FILL × HUG, wrap)
│   └── Close (36 × 36)
├── Content (vertical, FILL × HUG, gap spacing/2, vertical scroll past 320)
│   └── contentSlot
└── Arrow (Ignore auto-layout, 12 × 6, anchored opposite Side)
```

When the content is a menu, `padding` is 0 and `Content`'s gap is 0, with each menu item `FILL` × `FIXED 44`.

### 28.8 Bangla

**Strategy: WRAP within the 320px max-width; menu items WRAP to 2 lines.**

- Max-width stays **320** in both builds. Content width is 320 − 32 = 288px.
- `Why is this unavailable?` (24 chars, 1 line) → `এটি কেন অনুপলব্ধ?` (17 chars) 1 line.
- The commonest popover body — the out-of-stock-variant explanation — `This size is sold out. Choose another size or tap "Notify me" to hear when it is back.` (85 chars, 4 lines at 288px) → `এই সাইজটি শেষ হয়ে গেছে। অন্য একটি সাইজ বেছে নিন বা "আমাকে জানান"-এ ট্যাপ করুন।` (78 chars) wraps to **4 lines** at `bn/body-sm` (24.2px/line) = 97px, plus the title and padding = **169px**. Under the 320 max; no scroll.
- Menu items wrap to 2 lines, with the row changing from `FIXED 44` to `HUG` with `Min height 44` and padding Y `spacing/2`. The account menu's longest item, `Order history` → `অর্ডারের ইতিহাস` (15 chars, 132px), fits one line in a 240px menu.
- Content cap: **160 Bangla characters.** Beyond that it is a `Drawer` or a page.

### 28.9 Touch target

| Element | Size | Verdict |
|---|---|---|
| Trigger | must be ≥44 × 44 — an info-icon trigger is `IconButton Size=Medium` with a 16px glyph | Passes |
| Menu items | 44 FIXED tall × FILL | Passes |
| Close | 36 × 36 + `_hit44` | Passes |
| Links inside content | inline links in a 24.2px Bangla line-box **do not** meet 44px | **Resolved by rule:** a popover's content contains at most **one** inline link, and it is rendered as a `Button Variant=Ghost Size=Medium` on a line of its own rather than as inline text. Inline links inside popovers are forbidden. |
| Arrow | 12 × 6, `pointer-events: none` | n/a |

### 28.10 Accessibility

```html
<button type="button" id="variant-help-trigger"
        aria-expanded="false" aria-controls="variant-help">
  Why is this unavailable?
</button>

<div id="variant-help" role="dialog" aria-labelledby="variant-help-title" tabindex="-1" hidden>
  <h3 id="variant-help-title">This size is sold out</h3>
  <p>Choose another size, or tap “Notify me” to hear when it is back.</p>
  <button type="button" class="btn btn--ghost">Notify me</button>
</div>
```

| Requirement | Detail |
|---|---|
| Role | `role="dialog"` (**without** `aria-modal`) for content popovers; `role="menu"` + `role="menuitem"` for menus. `aria-modal="true"` on a non-trapping popover is a lie and breaks screen-reader navigation. |
| Trigger | `aria-expanded` + `aria-controls`. For a menu, also `aria-haspopup="menu"`. |
| Name | `aria-labelledby` → the title, or `aria-label` when `showTitle = false`. |
| Keyboard — content popover | `Esc` closes and returns focus to the trigger. `Tab` moves through the content and out, closing it. |
| Keyboard — menu popover | `ArrowDown`/`ArrowUp` move between items (wrapping), `Home`/`End` jump, `Enter`/`Space` activate, `Esc` closes, `Tab` closes and moves to the next element after the trigger. Type-ahead jumps to an item by first character. |
| Hidden state | the `hidden` attribute, so content is out of the a11y tree and the tab order when closed — and the element persists so `aria-controls` never dangles. |
| Not a tooltip | a popover never opens on hover. A control that opens something on hover and contains a button creates content the keyboard cannot reach without the hover. |
| Destructive menu items | carry `color/feedback/danger` **and** the word (`Delete order`), and open a `Modal destructive=true` rather than acting immediately. |
| Route change | closes the popover and does not restore it. |

---

## 29. `EmptyState`

### 29.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Type` | `NoResults`, `EmptyCart`, `EmptyWishlist`, `NoOrders`, `Error404`, `Error500` | 6 |
| **Total** | | **6** |

`Type` drives the default illustration, copy and action set. `Size` is not an axis: the component is `FILL` width with a 420px max content width at every breakpoint, and the illustration's two sizes are a media query.

### 29.2 Component properties

| Property | Type | Default (`NoResults`) | Bound to |
|---|---|---|---|
| `title` | TEXT | `No toys match those filters` | `Title` |
| `body` | TEXT | `Try removing a filter, or browse by age instead.` | `Body` |
| `illustrationSwap` | INSTANCE_SWAP | `illustration/no-results` | `Illustration` |
| `showIllustration` | BOOLEAN | `true` | `Illustration` visibility |
| `primaryLabel` | TEXT | `Clear all filters` | `Primary › label` |
| `showPrimary` | BOOLEAN | `true` | `Primary` visibility |
| `secondaryLabel` | TEXT | `Shop by age` | `Secondary › label` |
| `showSecondary` | BOOLEAN | `true` | `Secondary` visibility |
| `extraSlot` | INSTANCE_SWAP | `EmptyState/Suggestions` | `Extra` |
| `showExtra` | BOOLEAN | `false` | `Extra` visibility |

### 29.3 Geometry

| Element | Value |
|---|---|
| Frame width | `FILL` |
| Content max-width | **420**, horizontally centred |
| Frame padding Y | `spacing/12` (48) mobile, `spacing/16` (64) desktop |
| Frame padding X | `spacing/4` (16) |
| Gap (illustration → title → body → actions) | `spacing/4` (16) |
| Illustration | **160 × 160** mobile, **200 × 200** desktop |
| Title text | `en/h3` 18 / 1.3 / 600 (20 desktop), centred |
| Body text | `en/body-sm` 14 / 1.5 / 400 (15 desktop), `color/text/secondary`, centred, max **2 lines** of English copy |
| Actions gap above | `spacing/2` (8) in addition to the stack gap (total `spacing/6` = 24) |
| Actions gap between | `spacing/3` (12) |
| `Extra` gap above | `spacing/8` (32) |
| `Extra` width | `FILL` (it breaks out of the 420px content column) |

### 29.4 Token bindings

| Element | Binding |
|---|---|
| Frame fill | `None` (it sits on `color/bg/canvas` or inside a card on `color/bg/surface`) |
| Title | `color/text/primary` |
| Body | `color/text/secondary` |
| Illustration | two-tone, `color/bg/brand` + `color/bg/accent` on a `color/bg/brand-subtle` ground (the custom category-icon style from source spec §3.4) |
| `Primary` | `Button Variant=Primary Size=Medium` (`Large`, `FILL` on mobile) |
| `Secondary` | `Button Variant=Ghost Size=Medium` (`Large`, `FILL` on mobile) |

No states. `EmptyState` is a static composition; its buttons own their states.

### 29.5 Content and illustration per Type

| Type | Illustration | Title (en) | Body (en) | Primary | Secondary | `Extra` |
|---|---|---|---|---|---|---|
| `NoResults` | `illustration/no-results` | `No toys match those filters` | `Try removing a filter, or browse by age instead.` | `Clear all filters` | `Shop by age` | **yes** — see §29.6 |
| `EmptyCart` | `illustration/empty-cart` | `Your cart is empty` | `Add a toy and it will show up here.` | `Browse best sellers` | `Shop by age` | no |
| `EmptyWishlist` | `illustration/empty-wishlist` | `Nothing saved yet` | `Tap the heart on any toy to save it for later.` | `Browse new arrivals` | — | no |
| `NoOrders` | `illustration/no-orders` | `No orders yet` | `When you place an order it will appear here with its delivery status.` | `Start shopping` | — | no |
| `Error404` | `illustration/404` | `We could not find that page` | `It may have moved, or the link may be wrong.` | `Go to home` | `Search for a toy` | no |
| `Error500` | **none** (`showIllustration = false`) | `Something went wrong on our side` | `Try again in a moment. If it keeps happening, call us on 0XXXXXXXXXX.` | `Try again` | `Call us` | no |

> **`Error500` carries no illustration.** Illustration is permitted in exactly four places in this system (source spec §3.4: empty states, 404, no-results, order-success). A server error is a failure the business owns, and a cheerful drawing next to it reads as not taking it seriously — the same reasoning that bans illustration from form errors (§24.5). `Error500` is title + body + two actions on a plain surface, and the body gives a phone number, because a parent whose checkout just failed needs a person, not a picture.

Copy rules, from the brand direction: no baby-talk, no exclamation marks, no `Oops`, no `Yay`. `Your cart is empty`, never `Your cart is feeling lonely!`.

### 29.6 `NoResults` — the suggestion block

Zero results never ends the journey (source spec §6.3). `extraSlot` holds `EmptyState/Suggestions`, a composite built in Tier 2 and specified here only as a contract:

```
EmptyState/Suggestions (vertical, FILL × HUG, gap spacing/6)
├── Correction  — "Showing results for গাড়ি. Search instead for gari."   [when a correction applies]
├── Categories  — a wrapping row of Chip instances (Selected=False, Removable=False),
│                 the closest 4 categories, gap spacing/2
└── BestSellers — a horizontal ProductCard rail (compact variant), heading "Best sellers"
```

The three parts appear in that order and each is independently optional. The correction line uses `en/body-sm` with the corrected term in weight 600 and `color/text/link` for the "search instead" link. This block is the reason `extraSlot` is `FILL` width rather than inside the 420px column.

### 29.7 Auto-layout

```
EmptyState (vertical, FILL × HUG, align Center,
            padding Y spacing/12 | spacing/16, padding X spacing/4, gap spacing/4)
├── Content (vertical, FIXED 420 (max) × HUG, align Center, gap spacing/4)
│   ├── Illustration (160 | 200 square)
│   ├── Title  (FILL × HUG, wrap, align Center)
│   ├── Body   (FILL × HUG, wrap, align Center)
│   └── Actions (horizontal | vertical, HUG | FILL × HUG, gap spacing/3,
│                padding-top spacing/2, align Center)
│       ├── Primary   (Button)
│       └── Secondary (Button)
└── Extra (FILL × HUG, padding-top spacing/8)   [showExtra]
```

`Content` is `Max width 420` with `FILL` behaviour below that, so the text measure is capped on desktop and the component is still fluid on a 358px mobile viewport. Text alignment is `Center` — the one place in this document where centred body copy is correct, because it is two lines inside a 420px column under a centred illustration.

Actions: `Horizontal` + `HUG` at ≥480px; `Vertical` + `FILL` below 480px, `Primary` first.

### 29.8 Bangla

**Strategy: WRAP — title 2 lines, body 3 lines; illustration and widths unchanged.**

| Element | en | bn | Lines at 358px |
|---|---|---|---|
| `No toys match those filters` | 27 chars | `এই ফিল্টারে কোনো খেলনা পাওয়া যায়নি` 34 chars | 1 → **2** |
| `Try removing a filter, or browse by age instead.` | 48 chars | `একটি ফিল্টার সরিয়ে দেখুন, অথবা বয়স অনুযায়ী খেলনা দেখুন।` 55 chars | 2 → **3** |
| `Something went wrong on our side` | 32 chars | `আমাদের দিকে কিছু একটা সমস্যা হয়েছে` 34 chars | 2 → **2** |
| `Clear all filters` (button) | 142px | `সব ফিল্টার মুছুন` 150px | 1 → 1 |
| `Browse best sellers` (button) | 164px | `বেস্টসেলার দেখুন` 156px | 1 → 1 |

The Bangla `NoResults` block is `160 + 16 + 2×26.9 + 16 + 3×24.2 + 24 + 52 + 12 + 52` = **461px** tall against **383px** in English. The root is `HUG` height on a scrolling page, so it grows freely; no empty state sits inside a fixed-height container.

Centred Bangla text wraps correctly, but the body's three-line cap matters more than in English: at 3 lines of 24.2px the block is 73px and still reads as a caption; at 4 lines it reads as a paragraph and the actions fall below the fold on a 390 × 844 viewport with the header and bottom nav present (which leave 652px). Hence: **title cap 48 Bangla characters, body cap 110.**

Both buttons stay single-line at `FILL` width on mobile; neither needs §1.7's two-line fallback.

### 29.9 Touch target

| Element | Size | Verdict |
|---|---|---|
| `Primary` / `Secondary` (mobile) | 52 tall × FILL | Passes |
| `Primary` / `Secondary` (desktop) | 44 tall × ≥96 | Passes |
| Illustration | `pointer-events: none` | n/a |
| Chips in `Extra` | 36 + `_hit44` → 44 | Passes (§12.8) |
| Correction link in `Extra` | an inline link in a 24.2px line-box | **Rendered as a `Button Variant=Ghost Size=Medium`**, not an inline link, so it meets 44. Same rule as `Popover` §28.9. |

### 29.10 Accessibility

```html
<div class="empty-state">
  <img src="/illustrations/no-results.svg" alt="" aria-hidden="true" width="160" height="160">
  <h2>No toys match those filters</h2>
  <p>Try removing a filter, or browse by age instead.</p>
  <div class="empty-state__actions">
    <button type="button" class="btn btn--primary">Clear all filters</button>
    <a href="/shop-by-age" class="btn btn--ghost">Shop by age</a>
  </div>
</div>
```

| Requirement | Detail |
|---|---|
| Illustration | `alt=""` + `aria-hidden="true"`. It is decorative; the title says everything it says. Explicit `width` and `height` so it reserves space and contributes no CLS. |
| Heading level | The title is a real heading at the level the page's outline requires — `<h2>` inside a listing page that already has an `<h1>`, `<h1>` on the 404 and 500 pages (where it **is** the page's main heading). Never a styled `<p>`. |
| Announcement | A `NoResults` state replacing a product grid is announced through the listing's existing `aria-live="polite"` region: `No products match those filters`. The empty state itself is not a live region — it is page content. |
| Focus | Focus is **not** moved to the empty state. After a filter change the user's focus stays on the filter control they just used. The live region carries the news. |
| Actions | A navigation action is an `<a href>` (`Shop by age`, `Go to home`); a state-changing action is a `<button>` (`Clear all filters`, `Try again`). Not all buttons. |
| 404 / 500 | Return the correct HTTP status (404 / 500), not a 200 with an error page — otherwise the page is indexed. The `<title>` names the error. |
| `Error500` phone number | A real `tel:` link, and it is also plain selectable text, so it works when the device cannot place calls. |
| Contrast | `color/text/secondary` on `color/bg/canvas` is 7.0:1; the two-tone illustration is decorative and exempt from 1.4.11. |

---

## 30. `Stepper`

Two sets: **`Stepper/Step`** (the variant set) and **`Stepper`** (a layout frame holding instances and connectors).

### 30.1 Variant matrix — `Stepper/Step`

| Axis | Values | Count |
|---|---|---|
| `State` | `Complete`, `Current`, `Upcoming`, `Error` | 4 |
| `Variant` | `Numbered`, `Dot` | 2 |
| **Total** | | **8** |

`Orientation` lives on the **`Stepper` frame**, not on the step, because it changes the frame's auto-layout direction and the connector's axis — not the step's own geometry. `Error` is included because the checkout's payment step can fail and the stepper must show which step failed.

### 30.2 Component properties

**`Stepper/Step`:**

| Property | Type | Default | Bound to |
|---|---|---|---|
| `number` | TEXT | `2` | `Indicator › Number` |
| `label` | TEXT | `Delivery method` | `Label` |
| `description` | TEXT | `Standard · ৳80 · 2–3 days` | `Description` |
| `showDescription` | BOOLEAN | `false` | `Description` visibility |
| `focusVisible` | BOOLEAN | `false` | `_focus` |

**`Stepper` (frame):**

| Property | Type | Default | Bound to |
|---|---|---|---|
| `srStatus` | TEXT | `Step 2 of 3` | `_sr-status` (visually hidden) |

### 30.3 Geometry

| Element | `Numbered` | `Dot` |
|---|---|---|
| Indicator | **28 × 28** | **12 × 12** |
| Indicator radius | `radius/full` | `radius/full` |
| Indicator border | 2px (`Current`, `Upcoming`, `Error`) / 0 (`Complete`) | 0 |
| Number text | `en/caption` 12 / 700, `tabular-nums` | n/a |
| Check icon (`Complete`) | 16, stroke 2.25px | n/a |
| Error icon (`Error`) | 16 (`icon/alert-circle`), stroke 2.25px | n/a |
| Connector thickness | **2** | **2** |
| Connector radius | `radius/full` | `radius/full` |

| Element | Horizontal | Vertical |
|---|---|---|
| Step direction | Vertical (indicator above label) | Horizontal (indicator left of label) |
| Indicator → label gap | `spacing/2` (8) | `spacing/3` (12) |
| Label text | `en/body-sm` 14 / 1.5 / 600, centred | `en/body-sm` 14 / 1.5 / 600, left |
| Description text | `en/caption` 12 / 1.4 / 400, centred | `en/caption` 12 / 1.4 / 400, left |
| Label → description gap | `spacing/1` (4) | `spacing/1` (4) |
| Connector | horizontal, `FILL` width, aligned to the indicator's vertical centre, `spacing/2` (8) from each indicator | vertical, `FIXED 2` wide, `FILL` height, aligned to the indicator's horizontal centre, `spacing/2` (8) above and below, left-inset to centre on the 28px indicator (13px) |
| Step min-width (Horizontal) | **88** | n/a |
| Step min-height (Vertical) | n/a | **56** |
| Gap between steps | `spacing/2` (8) — the connector lives in the gap and is `FILL` | `spacing/2` (8) |

### 30.4 Token bindings

| State | Indicator fill | Indicator border | Content | Label | Description | Connector **after** this step |
|---|---|---|---|---|---|---|
| `Complete` | `color/bg/brand` | none | `icon/check` in `color/text/on-brand` | `color/text/primary` | `color/text/secondary` | `color/bg/brand` |
| `Current` | `color/bg/surface` | 2px `color/bg/brand` | number in `color/text/primary` (700) | `color/text/primary` (**700**) | `color/text/secondary` | `color/border/default` |
| `Upcoming` | `color/bg/subtle` | 2px `color/border/default` | number in `color/text/tertiary` | `color/text/tertiary` | `color/text/tertiary` | `color/border/default` |
| `Error` | `color/bg/surface` | 2px `color/feedback/danger` | `icon/alert-circle` in `color/feedback/danger` | `color/text/primary` (700) | `color/feedback/danger` | `color/border/default` |

`Dot` variant: same fills, no border on `Complete`/`Current` (the `Current` dot is `color/bg/brand` at 12px with a 2px `color/bg/brand-subtle` ring at 20px total, built as a wrapper), `Upcoming` is `color/border/default`.

Interaction: steps are **non-interactive by default**. A `Complete` step is optionally a button (jump back to edit) — then it gains hover (`_scrim` 8% on the indicator, label → underlined), pressed, and focus-visible (`_focus` around the whole step at `radius/md`). `Current`, `Upcoming` and `Error` steps are never clickable. `Upcoming` in particular must not be clickable: jumping forward past an incomplete step is how a checkout loses an address.

Animation: on advancing, the completing indicator crossfades to `color/bg/brand` and its check draws over `fast` 120ms; the connector fills left-to-right over `base` 200ms `standard`. **No spring, no bounce, no scale, no confetti.** Reduced motion: both instant.

### 30.5 The checkout rule

> The checkout's stepper is structural, not celebratory. `Variant=Numbered`, `Orientation=Horizontal`, three steps, no description, no illustration, `standard` easing only, and the completion of a step produces a check mark and a filled connector — nothing else. No colour celebration, no animation beyond the two transitions above, no sound, no haptic. This is the surface where a parent is deciding whether to hand over money.

### 30.6 Orientation rule

| Steps | Viewport | Orientation |
|---|---|---|
| 2–3 | any | `Horizontal` |
| 4+ | ≥768px | `Horizontal` |
| 4+ | <768px | **`Vertical`** |
| Any, with descriptions | <768px | **`Vertical`** |

Width check for `Horizontal` on mobile: 3 steps × 88 min-width + 2 × (8 + connector) = **280px** minimum, which fits a 358px row. Four steps at 88 = 352 + 3 connectors = **376px**, which does **not** fit 358. Hence the 4-step rule. The `OrderTimeline` (5 shipment statuses) and the admin `OrderStatusStepper` (6 statuses) are therefore `Vertical` on mobile and `Horizontal` on desktop.

### 30.7 Auto-layout

**Horizontal:**
```
Stepper (horizontal, FILL × HUG, counter align Top, gap spacing/2)
├── Stepper/Step (vertical, FILL × HUG min-w 88, align Center, gap spacing/2)
│   ├── Indicator (28 × 28 | 28 wrapper for Dot)
│   ├── Label       (FILL × HUG, wrap, align Center)
│   └── Description (FILL × HUG, wrap, align Center)
├── _connector (FILL × FIXED 2, margin-top 13 so it centres on the 28px indicator)
└── … repeated
```

**Vertical:**
```
Stepper (vertical, FILL × HUG, gap 0)
└── StepRow (horizontal, FILL × HUG min-h 56, counter align Top, gap spacing/3)
    ├── Rail (vertical, FIXED 28 × FILL, align Center, gap spacing/2)
    │   ├── Indicator (28 × 28)
    │   └── _connector (FIXED 2 × FILL)
    └── TextBlock (vertical, FILL × HUG, gap spacing/1, padding-top 4)
        ├── Label
        └── Description
```

In the horizontal form the connector is a sibling of the steps inside the frame, with a `margin-top` of 13px (half of 28, minus half of 2) so it meets the indicators' centres. In the vertical form the connector is **inside** the step's rail and is `FILL` height, so it stretches to whatever height a wrapped Bangla label produces — which is why vertical is the orientation that handles long labels for free.

### 30.8 Bangla

**Strategy: Horizontal WRAP (2 lines, min-width 88); Vertical WRAP (unbounded).**

| Step label | en | bn | Horizontal lines at 88px |
|---|---|---|---|
| `Contact` | 58px | `যোগাযোগ` 68px | 1 → 1 |
| `Delivery` | 62px | `ডেলিভারি` 70px | 1 → 1 |
| `Payment` | 66px | `পেমেন্ট` 58px | 1 → 1 |
| `Delivery method` | 118px | `ডেলিভারি পদ্ধতি` 126px | 2 → **2** |
| `Payment & review` | 132px | `পেমেন্ট ও রিভিউ` 128px | 2 → 2 |

At 88px min-width and `bn/body-sm` (24.2px/line), a two-line Bangla label makes the step 28 + 8 + 48 = **84px** tall against 28 + 8 + 42 = 78px in English. The frame's counter alignment is `Top`, so a two-line label on one step does not push the other indicators down — all indicators stay on the same line, which is the reason `Top` and not `Center`.

- **Two lines is the horizontal cap.** A three-line label means the checkout's stepper is 108px tall on a 390px viewport, which is 17% of the usable height on the page that most needs it. Labels are capped at **16 Bangla characters** for horizontal use.
- With longer labels, switch to `Vertical`, where the label is `FILL` in a `FILL`-width text block and wraps freely; the rail's connector stretches to match.
- `Description` (`Standard · ৳80 · 2–3 days` → `স্ট্যান্ডার্ড · ৳80 · ২-৩ দিন`) is `Vertical`-only and wraps to 2 lines. Note that the `৳80` inside it keeps **Western digits** (it is a price, §19.6) while `২-৩ দিন` uses Bengali (it is prose) — the data/prose boundary applied inside a single string.
- `srStatus` (`Step 2 of 3` → `ধাপ ২ / ৩`) uses Bengali numerals: it is prose, read aloud, never displayed.

### 30.9 Touch target

| Element | Size | Verdict |
|---|---|---|
| Non-interactive step | n/a | No target needed |
| `Complete` step as a jump-back button (Horizontal) | ≥88 × 78 | Passes |
| `Complete` step as a jump-back button (Vertical) | FILL × ≥56 | Passes |
| Indicator alone | 28 × 28 | **Never a target on its own.** When a step is interactive, the whole step (indicator + label + description) is the button. An indicator-only hit area is 28px and would need a `_hit44` that overlaps the connector. |
| Connector | 2px, `pointer-events: none` | n/a |

### 30.10 Accessibility

```html
<nav aria-label="Checkout progress">
  <p class="sr-only" aria-live="polite">Step 2 of 3: Delivery method</p>
  <ol class="stepper">
    <li class="step step--complete">
      <button type="button" aria-label="Step 1, Contact and delivery, completed. Edit.">…</button>
    </li>
    <li class="step step--current" aria-current="step">
      <span aria-hidden="true">2</span>
      <span>Delivery method</span>
    </li>
    <li class="step step--upcoming">
      <span aria-hidden="true">3</span>
      <span>Payment &amp; review</span>
    </li>
  </ol>
</nav>
```

| Requirement | Detail |
|---|---|
| Structure | `<nav aria-label>` around an **ordered** list. `aria-current="step"` on the current step. |
| Hidden status line | A visually-hidden `aria-live="polite"` line stating `Step 2 of 3: Delivery method`, updated on advance. Without it, a screen-reader user hears the new section's heading but not their position in the flow. |
| Step names | Composed in full for interactive steps: `Step 1, Contact and delivery, completed. Edit.` The visible number is `aria-hidden` (a bare `2` announced before a label is noise). |
| State not by colour | Each state has a distinct **shape**: `Complete` is a filled circle with a check, `Current` is an outlined circle with a number, `Upcoming` is a muted outlined circle with a number, `Error` is an outlined circle with an alert glyph. Distinguishable in greyscale and in Windows High Contrast Mode. The `Current` step additionally carries weight 700. |
| `Error` | the step's description carries the failure in words (`Card declined`), and the page also renders an `Alert Type=Danger` (§24) — the stepper's red ring is never the only signal. |
| Connectors | `aria-hidden="true"`; the list's order carries the sequence. |
| Non-linear jumps | Only backwards, only to `Complete` steps, only as real buttons. `Upcoming` steps are `<span>`, not disabled buttons — there is nothing to focus and nothing to explain beyond "you have not got there yet", which the list order already says. |

---

## 31. `ProgressBar`

### 31.1 Variant matrix

| Axis | Values | Count |
|---|---|---|
| `Variant` | `Determinate`, `Indeterminate` | 2 |
| `Size` | `Small`, `Medium` | 2 |
| `Tone` | `Brand`, `Success` | 2 |
| **Total** | | **8** |

`Tone=Success` exists for one case: the free-delivery threshold meter turning green when the threshold is met (§31.6). Without it the "you qualify" state would be a colour override on an instance, which is the kind of drift this system is built to prevent.

### 31.2 Component properties

| Property | Type | Default | Bound to |
|---|---|---|---|
| `label` | TEXT | `৳450 more for free delivery` | `Header › Label` |
| `showLabel` | BOOLEAN | `false` | `Header › Label` visibility |
| `valueText` | TEXT | `64%` | `Header › Value` |
| `showValue` | BOOLEAN | `false` | `Header › Value` visibility |
| `fillWidth` | TEXT | `64%` | documentation only — the indicator's width is set per instance in Figma |

In Figma the indicator's width is an instance-level resize, not a property (Figma has no numeric component property that drives a child's width percentage). The lead builds the component at 64% and documents five reference instances at 0%, 1%, 50%, 99% and 100% on the board, so the edge cases are visible.

### 31.3 Geometry

| Size | Track height | Indicator height | Radius |
|---|---|---|---|
| `Small` | **4** | 4 | `radius/full` |
| `Medium` | **8** | 8 | `radius/full` |

| Element | Value |
|---|---|
| Track width | `FILL` |
| Indicator min-width | **8** — so 1% is visible rather than a sliver. At values between 0% and the equivalent of 8px, the indicator renders at 8px. |
| Indicator at exactly 0% | **width 0, not 8** — nothing started means nothing shown. The 8px floor applies to any value above 0. |
| Header row | `FILL` × `HUG`, `Space between`, gap `spacing/2` (8), **`spacing/2` (8) below** the row |
| Label text | `en/body-sm` 14 / 1.5 / 500 |
| Value text | `en/caption` 12 / 1.4 / 600, `tabular-nums`, right-aligned, min-width **40** FIXED |
| Indeterminate indicator width | **40%** of the track |

`tabular-nums` and a 40px fixed min-width on the value so the label's available width does not change as the percentage goes from 9% to 10% to 100%.

### 31.4 Token bindings

| Element | `Tone=Brand` | `Tone=Success` |
|---|---|---|
| Track fill | `color/bg/subtle` | `color/bg/subtle` |
| Indicator fill | `color/bg/brand` | `color/status/in-stock` |
| Label | `color/text/primary` | `color/text/primary` |
| Value | `color/text/secondary` | `color/text/secondary` |

No border on the track. Non-text contrast: `color/bg/brand` (brand.500, #F4453A) against `color/bg/subtle` (neutral.100, #F4F4F5) is **4.3:1** and `color/status/in-stock` (#12A150) against the same is **3.6:1** — both clear the 3:1 required by WCAG 1.4.11 for a meaningful non-text graphic. `color/bg/accent` would be **1.8:1** and is therefore **not available as a Tone** (§11.5).

No states. The bar is not interactive.

### 31.5 Animation

**`Determinate`:**

| Parameter | Value |
|---|---|
| Width transition | `base` 200ms `standard` on `width` |
| Reduced motion | the width changes instantly (no transition) |
| Never animated | from 0 on mount. A determinate bar renders at its real value immediately; animating it up from zero on every page load is a lie about progress. |

**`Indeterminate`:**

```css
.progress__indicator--indeterminate {
  width: 40%;
  animation: indeterminate 1600ms cubic-bezier(.4, 0, .2, 1) infinite;
}
@keyframes indeterminate {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(250%); }
}
```

| Parameter | Value |
|---|---|
| Indicator width | 40% of the track |
| Travel | `translateX(-100% → 250%)` — the indicator fully exits the right edge before restarting |
| Duration | **1600ms** |
| Easing | `cubic-bezier(.4, 0, .2, 1)` |
| Composited property | `transform` only. Never `width` or `background-position`. |

**Reduced-motion fallback for `Indeterminate`:**

```css
@media (prefers-reduced-motion: reduce) {
  .progress__indicator--indeterminate {
    animation: none;
    width: 100%;
    background: var(--color-bg-brand-subtle);
  }
}
```

The bar becomes a **full-width `color/bg/brand-subtle` fill** — a static, filled-but-muted track that reads as "in progress, duration unknown" — and the accompanying `role="progressbar"` with no `aria-valuenow` plus the visible status label carry the actual information. This is the opposite of the `Spinner` decision (§14.5) and matches the `Skeleton` one (§15.5): a 1600ms sweep across a full-width bar is exactly the large-area horizontal motion most likely to provoke vestibular symptoms, and unlike a 20px spinner there is a sensible static state available. **The status label is mandatory whenever `Variant=Indeterminate`** (`showLabel = true`), precisely so that removing the motion removes no information.

### 31.6 The free-delivery meter

The highest-value use of this component on the storefront, specified because its copy and its tone change together:

| Cart subtotal | `Tone` | Label | `aria-valuetext` |
|---|---|---|---|
| Below threshold | `Brand` | `৳450 more for free delivery` | `450 taka more for free delivery` |
| At or above threshold | `Success` | `You have free delivery` + `icon/check` 16 in `color/status/in-stock` | `You have free delivery` |

- `Size=Small` (4px track), `showLabel = true`, `showValue = false` — the percentage is meaningless to the shopper; the remaining amount is the information.
- The label's `৳450` uses the `Price` component's symbol-and-digit rules (§19.3) inline: `৳` prefixed, Western digits, no decimals.
- The bar is capped at 100%; a subtotal well over the threshold does not overflow the track.
- **No celebration when the threshold is met.** The tone changes to green, a check appears, the words change. No animation, no confetti, no `spring`. The cart is a money surface.
- The label is the `aria-live="polite"` announcement on change, debounced 500ms so adding three items announces once.

### 31.7 Auto-layout

```
ProgressBar (vertical, FILL × HUG, gap spacing/2)
├── Header (horizontal, FILL × HUG, Space between, counter align Center, gap spacing/2)
│   ├── Label (FILL × HUG, wrap)
│   └── Value (FIXED min-w 40 × HUG, align Right)
└── Track (horizontal, FILL × FIXED 4|8, radius/full, Clip content true)
    └── Indicator (FIXED % | 40% × FILL, radius/full, min-w 8)
```

`Track` has `Clip content = true` so the indicator's `radius/full` is masked by the track's at both ends and the indeterminate indicator is clipped as it enters and exits. `Indicator` is `FILL` height so it always matches the track exactly — a 1px mismatch on a 4px bar is visible.

### 31.8 Bangla

**Strategy: label WRAP to 2 lines; the bar itself is locale-invariant.**

| Element | en | bn | Lines at 358px |
|---|---|---|---|
| `৳450 more for free delivery` | 196px | `ফ্রি ডেলিভারির জন্য আরও ৳450` 242px | 1 → 1 |
| `You have free delivery` | 168px | `আপনি ফ্রি ডেলিভারি পাচ্ছেন` 206px | 1 → 1 |
| `Uploading 3 of 12 images` | 186px | `১২টির মধ্যে ৩টি ছবি আপলোড হচ্ছে` 256px | 1 → 1 |
| `Booking courier for 48 orders — do not close this page` | 398px | `৪৮টি অর্ডারের জন্য কুরিয়ার বুক হচ্ছে — এই পেজটি বন্ধ করবেন না` 468px | 2 → **2** |

Available label width is `358 − 40 (value) − 8 (gap)` = 310px when `showValue = true`, and the full 358px when it is false (which it is on the free-delivery meter). The longest Bangla label wraps to 2 lines at `bn/body-sm` (24.2px/line) = 48px, and `Header` is `HUG` so the component grows by 24px. The track's height and the indicator's percentage are unaffected.

Note the mixed numerals inside one label: `ফ্রি ডেলিভারির জন্য আরও ৳450` keeps **Western digits** after `৳` (it is a price) while `১২টির মধ্যে ৩টি ছবি` uses **Bengali** digits (it is a count in prose) — the §19.6 data/prose boundary again, and the admin upload counter is prose.

Label cap: **56 Bangla characters.**

### 31.9 Touch target

Not applicable — `ProgressBar` is non-interactive, has no tab stop and carries `pointer-events: none` on its track and indicator. It is never a seek control, and a cancellable long operation pairs it with a separate `Button Variant=Ghost` labelled `Cancel` beside or below the label.

### 31.10 Accessibility

```html
<!-- Determinate -->
<div class="progress">
  <p class="progress__label" id="fd-label" aria-live="polite">৳450 more for free delivery</p>
  <div role="progressbar"
       aria-labelledby="fd-label"
       aria-valuemin="0" aria-valuemax="2000" aria-valuenow="1550"
       aria-valuetext="450 taka more for free delivery">
    <div class="progress__indicator" style="inline-size: 77.5%"></div>
  </div>
</div>

<!-- Indeterminate: no aria-valuenow -->
<div role="progressbar" aria-labelledby="bulk-label"></div>
```

| Requirement | Detail |
|---|---|
| Role | `role="progressbar"` on the track. `aria-valuemin` / `aria-valuemax` / `aria-valuenow` on `Determinate`; **all three omitted** on `Indeterminate` (an omitted `aria-valuenow` is what communicates "indeterminate"; setting it to 0 says "no progress"). |
| Name | `aria-labelledby` → the visible label, or `aria-label` when `showLabel = false`. A progress bar with no accessible name is a defect. |
| `aria-valuetext` | Used wherever the raw number is not the meaning. `77.5%` is not the information; `450 taka more for free delivery` is. Required on the free-delivery meter and on any count-based bar (`3 of 12 images uploaded`). |
| Announcements | The **label** is the live region, `aria-live="polite"`, debounced 500ms. The `progressbar` element itself is not a live region — a bar that announces every percentage tick is unusable. |
| Indeterminate requires a label | `showLabel = true` is mandatory, so that the reduced-motion static state (§31.5) still conveys that work is happening. |
| Not conveyed by colour | the label states the state in words; `Tone=Success` adds a check glyph as well as the colour change. |
| Long operations | Any operation over ~10 seconds uses `Indeterminate` with a label naming the work and a warning where applicable (`do not close this page`), rather than a `Spinner` (§14.9). |
| Contrast | indicator-vs-track verified at 4.3:1 (`Brand`) and 3.6:1 (`Success`) by the token-level CI test. |

---

# 32. Build order

The sequence below is dependency-ordered. Each **batch** is safe to create in one Figma call: no component in a batch depends on another component in the same batch, and each batch is sized so the call stays within a reasonable payload (at most ~5 component sets and ~45 total variants per batch).

**Rule for the lead:** do not start a batch until the previous one is published and its instances resolve. A batch that references an unpublished component produces detached frames, and detached frames are how hardcoded hex values get into a library.

### Batch 0 — Prerequisites (not components)

| Order | Item | Notes |
|---|---|---|
| 0.1 | **Variable collections** | `color/*` with Light and Dark modes, `spacing/*`, `radius/*`. Elevation `e1`–`e4` as effect styles (Figma variables do not yet bind shadows). All 26 colour tokens, all 12 spacing tokens, all 6 radius tokens. |
| 0.2 | **Text styles** | `en/*` (Plus Jakarta Sans) and `bn/*` (Hind Siliguri at 1.15× line-height) — both sets, all 14 scale steps. `tnum` enabled on `en/price*`. |
| 0.3 | **Icon set** | Lucide at 1.75px stroke, as 16 / 20 / 24 components. The ~30 glyphs named in this document: `check, check-circle, x, minus, plus, chevron-down, chevron-up, chevron-left, chevron-right, alert-circle, alert-triangle, info, shopping-cart, arrow-right, phone, eye, user, home, truck, shield-check, flame, star, heart, search, filter, sort, edit, trash, copy, circle, share, zoom-in, menu`. |
| 0.4 | **Helper components** | `_focus`, `_scrim`, `_aria-label`, `_hit44` (§0.2, §0.8). Build these as four local components before anything else; every batch below instances them. |
| 0.5 | **Illustration placeholders** | Six 200×200 frames for `EmptyState` (§29.5). Final artwork is a separate task; the placeholders unblock the component. |

Nothing in Batches 1–9 can be built correctly before 0.1–0.4 exist. 0.5 can lag until Batch 9.

### Batch 1 — Zero-dependency atoms (6 sets, 42 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Spinner` (§14) | 12 | 0.1–0.4 |
| `Skeleton` (§15) | 3 | 0.1 |
| `Divider` (§16) | 4 | 0.1 |
| `Badge` (§11) | 7 | 0.1, 0.3 |
| `Avatar` (§13) | 15 | 0.1, 0.3 |
| `ProgressBar` (§31) | 8 | 0.1 |

`Spinner` is first in the batch and first overall among components, because `Button`, `IconButton` and `Switch` all instance it for their loading states. Building `Button` before `Spinner` means either a placeholder that must be swapped later or a detached loading state.

### Batch 2 — Buttons (2 sets, 24 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Button` (§1) | 15 | **`Spinner`** (loading state), 0.3 (icon slots), 0.4 (`_focus`, `_scrim`, `_hit44`) |
| `IconButton` (§2) | 9 | **`Spinner`**, 0.3, 0.4 |

Build `Button` first, then `IconButton` — not because `IconButton` instances it, but because the token-binding table for `Button` (§1.4) is the one the lead will copy for every other variant-bearing component, and getting it right once saves re-work.

### Batch 3 — Button-dependent atoms (4 sets, 24 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Chip` (§12) | 4 | **`IconButton`** pattern for the remove control (built inline, not instanced — the 28px remove box is smaller than `IconButton Size=Small`), 0.4 (`_hit44`) |
| `QuantityStepper` (§10) | 8 | **`IconButton`** geometry (cells are built inline so they can share the pill's border), 0.3 |
| `Rating` (§18) | 6 | 0.3 (`icon/star`), 0.4 |
| `Price` (§19) | 12 | 0.1, 0.2 (`en/price*` with `tnum`), and the subsetted `৳` woff2 from §19.3 |

`Price` has no component dependencies and could have been in Batch 1, but it is placed here deliberately: it is the most load-bearing component in the library, it should be built when the lead is warm on the token bindings, and it should get a dedicated review pass before the composites that consume it are started. Build it last in this batch and review it before starting Batch 4.

### Batch 4 — Form fields, part 1 (4 sets, 48 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Input` (§3) | 12 | 0.3 (prefix/suffix slots, error icon), 0.4 |
| `Textarea` (§4) | 12 | **`Input`** (identical token table and row structure — build by duplicating `Input` and changing the Field) |
| `NativeSelect` (§6) | 12 | **`Input`** (identical trigger geometry) |
| `Checkbox` (§7) | 6 | 0.3 (`icon/check`), 0.4 |
| `Radio` (§8) | 4 | **`Checkbox`** row structure |
| `Radio/Card` (§8.5) | 4 | **`Radio`** |

Six sets and 50 variants is above the batch guideline; split it if the call is large: **4a** = `Input` + `Textarea` + `NativeSelect` (36 variants), **4b** = `Checkbox` + `Radio` + `Radio/Card` (14 variants). `Textarea` and `NativeSelect` both depend on `Input`, so `Input` must be first in 4a and must be published before 4b only if 4b is parallelised.

### Batch 5 — Form fields, part 2 (3 sets, 22 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Switch` (§9) | 4 | **`Spinner`** (knob loading state), 0.4 |
| `Select/Option` (§5.3) | 4 | 0.3 (`icon/check`), 0.4 |
| `Select` (§5) | 14 | **`Input`** (trigger geometry), **`Select/Option`** (menu items), **`Input Size=Large`** (in-menu search field), 0.3 |
| `QuantityStepper/Editable` (§10.6) | 8 | **`QuantityStepper`**, **`Input`** (value-cell states) |

`Select` is the most dependency-heavy primitive in the library: it needs `Input`, `Select/Option` and `Input Size=Large` all published. Build `Select/Option` first in this batch.

### Batch 6 — Overlays (4 sets, 28 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Tooltip` (§17) | 4 | 0.1 (`surface-raised`, `border/default`), `e3` |
| `Popover` (§28) | 12 | **`IconButton`** (close), `e3` |
| `Modal` (§26) | 3 | **`IconButton`** (close), **`Button`** (footer), `e4` |
| `Drawer` (§27) | 9 | **`IconButton`**, **`Button`**, `e4`, and `Modal`'s header/body/footer structure to copy |

Build in this order: `Tooltip` (simplest overlay, establishes the arrow construction) → `Popover` (reuses the arrow) → `Modal` → `Drawer` (reuses `Modal`'s internal stack). `Drawer` must come after `Modal` because §26.6 defines the mobile `Modal` as a `Drawer Side=Bottom`, and the lead builds that documentation instance at the end of this batch.

### Batch 7 — Navigation (4 sets, 20 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Tabs/Item` (§20) | 6 | **`Badge`** pattern for the count pill (built inline at 20px, not instanced), 0.3, 0.4 |
| `Tabs` (frame) | n/a | **`Tabs/Item`** |
| `Breadcrumb/Item` (§22) | 2 | 0.4 |
| `Breadcrumb` (frame) | 2 | **`Breadcrumb/Item`**, **`Popover`** (the `…` overflow list), 0.3, 0.4 |
| `Pagination/Page` (§23) | 3 | 0.4 |
| `Pagination` (frame) | 3 | **`Pagination/Page`**, **`IconButton`** (prev/next), **`Button`** (`LoadMore`) |
| `Accordion/Item` (§21) | 6 | **`Badge`** (header slot), 0.3, 0.4 |
| `Accordion` (frame) | n/a | **`Accordion/Item`** |

`Breadcrumb` depends on `Popover` from Batch 6 — this is the one cross-batch dependency that is easy to miss.

### Batch 8 — Messaging (2 sets, 16 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Alert` (§24) | 8 | **`IconButton`** (dismiss), **`Button`** (actions), 0.3 |
| `Toast` (§25) | 8 | **`IconButton`** (dismiss), **`Button Variant=Ghost Size=Small`** (action), 0.3 |

Build `Alert` first; `Toast` reuses its icon-per-type table verbatim (§25.5 references §24.4).

### Batch 9 — Composed states (2 sets, 14 variants)

| Component | Variants | Depends on |
|---|---|---|
| `Stepper/Step` (§30) | 8 | 0.3 (`icon/check`, `icon/alert-circle`), 0.4 |
| `Stepper` (frame) | n/a | **`Stepper/Step`** |
| `EmptyState` (§29) | 6 | **`Button`** (two actions), **`Chip`** (the `NoResults` category row, inside `extraSlot`), 0.5 (illustration placeholders) |

`EmptyState` is last because it is the only primitive that depends on illustration assets, and because its `extraSlot` contract (§29.6) is fulfilled by a Tier 2 composite — so it is also the natural handover point into Tier 2.

### Batch 10 — Documentation board (no new components)

Built after all nine batches, in one pass:

| Item | Contents |
|---|---|
| `Button / States` | §1.1 — Primary/Medium × 6 states as static instances with overrides |
| `Button / Admin override` | §1.3 — `radius/md` |
| `Button / bn wrap` | §1.7 — two-line FILL button |
| `Input / interactive suffix` | §3.8 |
| `Select / bn wrap` | §5.9 |
| `Switch / settings row`, `Switch / inline`, `Switch / bare` | §9.3, §9.7 |
| `Checkbox/Bare` | §7.7 — the 44×44 table-select wrapper (a real component, 6 variants) |
| `Radio/Card / narrow` | §8.7 |
| `Modal / mobile sheet` | §26.6 |
| `Drawer / responsive pair` | §27.7 |
| `Tabs / two-up` | §20.5 |
| `Accordion/Item / bn long title` | §21.6 |
| `Alert / bn narrow actions` | §24.7 |
| `ProgressBar` reference instances | §31.2 — 0%, 1%, 50%, 99%, 100% |
| Bangla pair row | One instance of every text-bearing component with its Bangla string, beside the English one. 22 pairs. |
| `_invalid` markers | `Select Mode=Interactive Size=Small` (§18.3), `Chip Selected=False Removable=True` (§12.1), `Divider Vertical + label` (§16.2) |

### Dependency summary — the five that must not be reordered

1. **`Spinner` → `Button`, `IconButton`, `Switch`.** Loading states instance it.
2. **`Input` → `Textarea`, `NativeSelect`, `Select`, `QuantityStepper/Editable`.** They share its token table and field geometry.
3. **`Select/Option` → `Select`.** The menu is built from option instances.
4. **`IconButton` + `Button` → `Modal`, `Drawer`, `Alert`, `Toast`, `Pagination`, `EmptyState`, `Popover`.** Close buttons and footers.
5. **`Popover` → `Breadcrumb`.** The mobile `…` overflow list.

### Total

| | Count |
|---|---|
| Named primitives in the brief | **31** |
| Figma **component sets** (variant-bearing) | **37** — the 31 primitives plus 6 child/companion sets: `Select/Option`, `Checkbox/Bare`, `Radio/Card`, `QuantityStepper/Editable`, and the four item sets `Tabs/Item`, `Accordion/Item`, `Breadcrumb/Item`, `Pagination/Page`, `Stepper/Step` (the last five replace their parents as the variant-bearing set) |
| Figma **layout frames** (no variants) | **3** — `Tabs`, `Accordion`, `Stepper` |
| Total variant combinations | **269** |
| Largest single matrix | **15** — `Button` (5 × 3) and `Avatar` (5 × 3) |
| Matrices at or above 20 combinations | **none** |
| Batches | **10** — 1 prerequisite, 9 component, 1 documentation (Batch 4 may split into 4a / 4b) |

Every matrix in this library is under 20 combinations. That is the direct result of the §0.3 decision — content states are variant axes, interaction states are not — and it is what keeps the library maintainable when a sixth button variant or a third input size is eventually requested.
