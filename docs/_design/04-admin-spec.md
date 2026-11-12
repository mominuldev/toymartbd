# Toy Mart BD — Admin Layout Specification

**Owner:** Design Agent 4 · **Date:** 2026-11-08 · **Status:** Build-ready, for mechanical execution by the design lead
**Scope:** Tier 3 admin composites (UI-UX-DESIGN-SPEC §4) + 8 admin screens (§5) at 1440 / 1024 / 768, Light + Dark.
**Figma:** this agent wrote nothing to Figma. Every frame below is the lead's to build.

**Read order.** Part C holds the sample dataset and its arithmetic. Parts A and B quote it by order number, so if a figure looks arbitrary it is not — it traces to Part C. The screen clock for every mock is **2026-11-08 14:30 Asia/Dhaka (+06)**.

---

## Part 0 — The admin design contract

Eleven rules. They resolve most of the decisions that would otherwise come back as questions.

1. **Radius is `radius/md` (10px) everywhere a control is interactive** — buttons, inputs, selects, chips, tabs. `radius/sm` (6px) for status chips, table-internal pills and code/mono fields. `radius/lg` (14px) for cards and panels. `radius/xl` (20px) for modals and drawers. **`radius/full` appears nowhere in the admin** except avatars, the notification count dot, and the risk-score gauge arc. A pill button in this product is a defect.
2. **Surfaces.** Page = `color/bg/canvas`. Cards, panels, table bodies, modals = `color/bg/surface`. Popovers, dropdowns, command palette, date-picker panel, tooltips = `color/bg/surface-raised`. Table zebra rows, inert fills, status-chip fills, disabled inputs = `color/bg/subtle`.
3. **Elevation is for layering, not decoration.** Cards get `e1`. Sticky topbar and sticky save bar get `e2`. Popovers/dropdowns `e3`. Modals and drawers `e4`. In dark mode `e1` and `e2` are replaced by a 1px `color/border/default` stroke, per §3.3 — a shadow on a dark surface is invisible and the border is what actually separates the layers.
4. **Colour never carries meaning alone.** Every status, risk level and delta is **text + icon shape + colour**, in that order of load-bearing. Status chips fill with `color/bg/subtle` and label in `color/text/primary` (16.9:1 light, 15.1:1 dark); the feedback token tints only the 14px icon and, for anomalies, a 2px left border. This is deliberate: `color/feedback/success` (#12A150) is 3.4:1 on white and `color/feedback/warning` (#E07C02) is 2.9:1 — **neither may ever carry 12–14px text, as a fill or as a foreground.** Do not "improve" these chips into a contrast failure.
5. **Admin type overrides** the storefront scale, because 32px page titles waste a working screen:

| Admin token | Size / weight / line-height | Use |
|---|---|---|
| `admin/page-title` | 24 / 700 / 1.2 | One per screen, in the content header |
| `admin/section` | 17 / 600 / 1.35 | Card and `FormSection` headings |
| `admin/body` | 14 / 400 / 1.5 | Default. Table cells, labels, help text |
| `admin/body-strong` | 14 / 600 / 1.5 | Emphasised cell (order number, customer name) |
| `admin/num` | 14 / 600 / 1.4, `tabular-nums` | Every money and quantity cell |
| `admin/num-lg` | 26 / 700 / 1.2, `tabular-nums` | `StatCard` value, P&L totals |
| `admin/num-xl` | 34 / 800 / 1.1, `tabular-nums` | P&L net-profit figure only |
| `admin/caption` | 12 / 500 / 1.4 | Metadata, timestamps, chip labels |
| `admin/overline` | 11 / 700 / 1.3, +0.08em, uppercase | Panel eyebrows, column groups |
| `admin/mono` | 13 / 500 / 1.4 | SKU, consignment ID, slug, ledger reference |

6. **Money is always `tabular-nums`, right-aligned, with the `৳` prefix and a thousands comma.** `৳5,060`. Negative money is `−৳300` with a true minus (U+2212), never parentheses and never red-only. Quantities right-aligned. Dates and phone numbers left-aligned and `tabular-nums`.
7. **Every date, time and date-range in the admin is Asia/Dhaka.** Timestamps render `08 Nov 2026, 2:30 pm`; in dense tables `08 Nov · 2:30 pm`; within 24 hours, relative (`5h 25m ago`) with the absolute value in the tooltip. The timezone is stated once in the topbar, not on every field.
8. **Focus is visible and uniform:** 2px `color/border/focus` ring, 2px offset, `radius` matching the element +2. Never removed. Hover on rows and list items = `color/bg/subtle`; selected = `color/bg/brand-subtle` with a 2px `color/bg/brand` left border.
9. **Grid.** 1440: sidebar 248 expanded / 64 collapsed, topbar 56, content padding `spacing/6` (24), content width **1144**, 12 columns, gutter `spacing/6`. 1024: sidebar locked to the 64 icon rail, content padding `spacing/5` (20), content width **920**, 12 columns, gutter `spacing/5`. 768: sidebar off-canvas (0 in flow), content padding `spacing/4` (16), content width **736**, 6 columns, gutter `spacing/4`. Every width in Part B sums to these numbers; if your frame doesn't add up, a column is wrong.
10. **Density toggle** is a two-state global preference persisted per user: `comfortable` (row 56, cell padding 12/16) and `compact` (row 40, cell padding 6/12). It changes row height, cell padding and `StatCard` padding only — never font size, never column widths.
11. **Loading never reflows.** Every table, card and chart has a skeleton at its final dimensions. Numbers animate in; boxes do not grow.

---

## Part A — Composite components

Each spec gives variant axes, Figma component properties, exact geometry, auto-layout, per-state token bindings, and behaviour at 1440 / 1024 / 768. Build order follows dependency: A1–A6 first (shell and data), then forms, then the four domain components, then the rest.

### A1 · `AppShell`

**Variant axes** — `sidebar`: `expanded` | `collapsed` | `offcanvas-closed` | `offcanvas-open` · `theme`: `light` | `dark`
**Properties** — `activeSection` (text), `notificationCount` (number), `userName` (text), `userRole` (text), `showShiftContext` (bool), `breadcrumb` (text)

| Region | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Fill | Horizontal, gap 0, padding 0 | `color/bg/canvas` |
| Sidebar `expanded` | W 248, H Fill | Vertical, gap `spacing/1`, padding `spacing/3` | `color/bg/surface`; right border 1px `color/border/default` |
| Sidebar `collapsed` | W 64 | Vertical, gap `spacing/1`, padding `spacing/2` | same |
| Brand block | H 56, Hug×Fill | Horizontal, gap `spacing/3`, padding L `spacing/3` | logo 28×28; wordmark `admin/body-strong`, `color/text/primary`; hidden when collapsed |
| Nav group label | H 28 | — | `admin/overline`, `color/text/tertiary`, padding L `spacing/3`; replaced by a 1px `color/border/default` divider when collapsed |
| Nav item | H 40, Fill×Hug | Horizontal, gap `spacing/3`, padding `spacing/2` `spacing/3`, `radius/md` | icon 20 `color/text/secondary`; label `admin/body` `color/text/primary` |
| Nav item `hover` | — | — | bg `color/bg/subtle` |
| Nav item `active` | — | — | bg `color/bg/brand`, label + icon `color/text/on-brand`; **no** left bar (the fill is the signal) |
| Nav item `active` collapsed | 48×40 centred | — | same fill, icon only, `aria-label` = label, tooltip on hover |
| Nav badge (count) | H 18, min-W 18 | padding `spacing/1`, `radius/sm` | bg `color/bg/subtle`, text `admin/caption` `color/text/primary`; when the item is active, bg `color/bg/surface` / text `color/text/primary` |
| Collapse toggle | 32×32 | — | pinned to sidebar footer, icon `panels-left`; `IconButton ghost` |
| Topbar | H 56, Fill×Hug | Horizontal, gap `spacing/4`, padding 0 `spacing/6` | `color/bg/surface`, bottom border 1px `color/border/default`, `e2`, sticky top |
| Breadcrumb | Hug | Horizontal, gap `spacing/2` | `admin/caption` `color/text/tertiary`; last crumb `color/text/primary` |
| Global search | W 420 (Fill at <1280), H 36 | Horizontal, gap `spacing/2`, padding 0 `spacing/3`, `radius/md` | bg `color/bg/subtle`, 1px `color/border/default`, placeholder `color/text/tertiary`: "Search orders, products, phone numbers… ⌘K" |
| Search `focus` | — | — | bg `color/bg/surface`, 2px `color/border/focus` ring |
| Topbar right cluster | Hug | Horizontal, gap `spacing/2` | timezone chip, theme toggle, notifications, avatar+role |
| Timezone chip | H 28 | padding `spacing/1` `spacing/2`, `radius/sm` | bg `color/bg/subtle`, `admin/caption` `color/text/secondary`: "Dhaka · 2:30 pm" |
| Theme toggle | 36×36 | — | `IconButton ghost`, `sun`/`moon`, `aria-pressed` |
| Notifications | 36×36 | — | count dot 16×16 `radius/full`, bg `color/feedback/danger`, text `color/text/on-brand` 10/700; **the dot also renders the number**, so it is never colour-only |
| Shift context | H 28 | padding `spacing/1` `spacing/2`, `radius/sm` | bg `color/bg/brand-subtle`, `admin/caption` `color/text/primary`: "Shift: Rina · since 9:00 am" |
| Content header | H 72, Fill×Hug | Horizontal, space-between, padding `spacing/6` `spacing/6` 0 | title `admin/page-title`; right slot for primary actions |
| Content body | Fill × Fill | Vertical, gap `spacing/6`, padding `spacing/6` | scroll container |

**Global search results** — popover W 560, `color/bg/surface-raised`, `e3`, `radius/lg`, max-H 440, grouped: Orders (number, customer, total, status triad), Customers (name, masked phone, order count), Products (thumb 32, name, SKU, available). A pure-digit query of 11 characters matching `01[3-9]\d{8}` promotes a **"Risk lookup for 01712•••318"** row to the top of the list — the single most-used admin action in a COD business should be one keystroke from anywhere.

**Responsive** — **1440:** sidebar `expanded`, user preference may collapse it. **1024:** sidebar forced to `collapsed` (64 icon rail, labels as tooltips); the collapse toggle is disabled with a tooltip "Expanded sidebar needs a wider screen"; global search shrinks to Fill. **768:** sidebar `offcanvas-closed`; a 40×40 hamburger appears as the first topbar item; drawer W 280 slides from the left over a 50%-opacity `color/text/primary` scrim, `e4`; focus trapped, `Esc` closes, focus returns to the hamburger. Breadcrumb collapses to the current page name. Global search collapses to a 36×36 icon that opens a full-width sheet. Content padding `spacing/4`.

---

### A2 · `DataTable`

The workhorse. Everything in Part B except the P&L is an instance of it.

**Variant axes** — `density`: `comfortable` | `compact` · `state`: `default` | `loading` | `empty` | `error` · `selection`: `none` | `some` | `all-page`
**Properties** — `columns[]`, `rows[]`, `sortKey`, `sortDir`, `selectedIds[]`, `savedViewName`, `hasNextPage`, `hasPrevPage`, `totalLabel`

| Region | Geometry (1440) | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap 0 | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1`, `overflow: clip` |
| Toolbar | H 56, Fill×Hug | Horizontal, space-between, padding `spacing/3` `spacing/4`, gap `spacing/3` | bottom border 1px `color/border/default` |
| — Saved-view tabs | Hug, H 32 | Horizontal, gap `spacing/1` | each: padding `spacing/2` `spacing/3`, `radius/md`, `admin/body`; inactive text `color/text/secondary`; active bg `color/bg/brand-subtle`, text `color/text/primary`, 600; trailing count in `color/text/tertiary` |
| — "Save view" | H 32 | — | `Button ghost sm`, appears only when a filter/sort/column change is unsaved, with a `●` dot before the label |
| — Search-in-table | W 240, H 32 | — | as topbar search, `radius/md` |
| — Filter button | H 32 | Horizontal, gap `spacing/2` | `Button outline sm`, icon `filter`; active filter count as a trailing 18×18 `radius/sm` `color/bg/brand` / `color/text/on-brand` badge |
| — Column visibility | 32×32 | — | `IconButton outline`, icon `columns-3`; popover: `color/bg/surface-raised`, W 240, `e3`, one `Switch` per column, "Reset to default" footer |
| — Density toggle | H 32 | 2 segments × 32 | segmented control, `radius/md`, 1px `color/border/default`; active segment bg `color/bg/subtle`, icon `rows-3` / `rows-4` |
| — Export CSV | 32×32 | — | `IconButton outline`, icon `download` |
| Active-filter chip row | H 44, Fill×Hug | Horizontal wrap, gap `spacing/2`, padding `spacing/2` `spacing/4` | shown only when filters are active; bg `color/bg/subtle`; each chip = `Chip` removable, `radius/sm`; trailing `Button ghost sm` "Clear all" |
| Header row | H 40, sticky | Horizontal, gap 0 | bg `color/bg/subtle`, bottom border 1px `color/border/strong`; `admin/overline` `color/text/secondary`; sticky with `e1` once scrolled |
| Header cell | per-column W | padding 0 `spacing/4` | sortable cells show a 14px `chevron-up`/`chevron-down`/`chevrons-up-down`; `aria-sort` set |
| Body row `comfortable` | H 56 | Horizontal | bottom border 1px `color/border/default`; odd rows `color/bg/surface`, even `color/bg/subtle` |
| Body row `compact` | H 40 | — | same |
| Body row `hover` | — | — | bg `color/bg/subtle` (light) / `color/bg/surface-raised` (dark) |
| Body row `selected` | — | — | bg `color/bg/brand-subtle`, 2px `color/bg/brand` left border inset |
| Checkbox cell | W 40 | centred | `Checkbox`; header checkbox is tri-state |
| Row focus | — | — | 2px `color/border/focus` inset ring on the whole row, keyboard only |
| Footer | H 52, Fill×Hug | Horizontal, space-between, padding `spacing/3` `spacing/4` | top border 1px `color/border/default` |
| — Count label | — | — | `admin/caption` `color/text/secondary`: "Showing 1–12 of 12" |
| — Per-page select | H 32, W 92 | — | `NativeSelect sm`: 25 / 50 / 100 |
| — Cursor pager | H 32 | Horizontal, gap `spacing/2` | two `Button outline sm`: "← Newer" / "Older →". **No page numbers** — the API is cursor-paginated, so a page number would be a lie. Disabled state uses `color/text/tertiary` + `aria-disabled`. |

**Sorting, filtering, pagination are all server-side.** On every change the table enters `loading`: rows are replaced by skeleton rows at the identical height (shimmer = `color/bg/subtle` → `color/bg/surface-raised`, 1.2s), the header and toolbar stay live, and an `aria-live="polite"` region announces "Loading… / 12 orders". The row count in the footer is the server's; the saved view stores `columns`, `filters`, `sort`, `density`.

**Bulk selection** survives sort and filter changes within a view and is cleared by a view change, with a confirm if more than 10 rows are selected. Selecting the header checkbox selects the current page only and the `BulkActionBar` then offers "Select all 12 matching".

**Empty / error** — `EmptyState` (A24) centred in a 320-high body. `error` shows an `Alert danger` with the failing request id and a "Retry" button; the toolbar stays usable so the operator can relax the filter that broke it.

**Responsive** — **1440:** full table, widths per Part B. **1024:** the table keeps its 1440 column widths and scrolls horizontally inside the card; the checkbox + first data column are frozen with a 1px `color/border/strong` right edge and `e2`; the toolbar wraps to two rows (filters/saved views on line 1, search/column/density/export on line 2, root H grows to 104). **768:** the table is replaced by a **card list**. Each card: Fill × Hug, padding `spacing/4`, gap `spacing/2`, `radius/lg`, 1px `color/border/default`, `color/bg/surface`. Line 1: checkbox + primary identifier (`admin/body-strong`) + right-aligned money (`admin/num`). Line 2: secondary identifier (`admin/caption` `color/text/secondary`). Line 3: the status triad. Tap opens the detail; long-press selects. Sort becomes a `NativeSelect`; filters become a bottom sheet; the pager becomes a full-width "Load older" button.

---

### A3 · `StatCard`

**Variant axes** — `size`: `md` | `sm` · `delta`: `up` | `down` | `flat` | `none` · `sentiment`: `good` | `bad` | `neutral` · `state`: `default` | `loading` | `no-comparison`
**Properties** — `label`, `value`, `unit`, `deltaPercent`, `comparisonLabel`, `sparklinePoints[]`, `drillHref`, `footnote`

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root `md` | W 268 × H 132 | Vertical, gap `spacing/2`, padding `spacing/4` | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Root `md` compact density | W 268 × H 112 | padding `spacing/3` | same |
| Root `sm` | W 268 × H 92 | gap `spacing/1`, padding `spacing/3`; no sparkline | same |
| Root `hover` (drill-through) | — | — | border `color/border/strong`, `e2`, cursor pointer; a 14px `arrow-up-right` in `color/text/tertiary` fades in at top-right |
| Label row | Fill × 20 | Horizontal, space-between | `admin/overline` `color/text/secondary`; optional 14px `info` icon with a `Tooltip` naming the exact source table |
| Value | Hug × 34 | — | `admin/num-lg` `color/text/primary` |
| Delta row | Hug × 20 | Horizontal, gap `spacing/1` | icon 14 + `admin/caption` 600 + comparison label |
| Delta `up`/`sentiment:good` | — | — | icon `trending-up` + text `color/feedback/success`… **not permitted at 12px.** Binding is: icon `trending-up` tinted `color/feedback/success`, text `color/text/primary`, and the sign is written out (`+37.8%`). Colour reinforces; the arrow glyph and the sign carry it. |
| Delta `down`/`sentiment:bad` | — | — | icon `trending-down` tinted `color/feedback/danger`, text `color/text/primary`, `−12.4%` |
| Delta `down`/`sentiment:good` | — | — | e.g. cancellations falling: icon `trending-down` tinted `color/feedback/success` |
| Delta `flat` | — | — | icon `minus` tinted `color/text/tertiary`, text "0.0%" |
| Comparison label | — | — | `admin/caption` `color/text/tertiary`: "vs 26 Oct – 1 Nov" |
| Sparkline | Fill × 32 | absolute, bottom-aligned | 7 points, 1.5px stroke `color/bg/brand`, no axes, no dots except the last (3px, `color/bg/brand`); area fill `color/bg/brand-subtle`; a 1px `color/border/default` baseline at zero when the series crosses zero |
| `loading` | same box | — | label skeleton 80×12, value skeleton 120×28, sparkline skeleton Fill×32, all `color/bg/subtle`, `radius/sm` |
| `no-comparison` | — | — | delta row replaced by `admin/caption` `color/text/tertiary` "No comparable period" — **never a 0% delta**, which reads as "no change" |

A `StatCard` whose number drills through is a real link (`<a>`), not a click handler, so it is middle-clickable and keyboard-reachable. The drill target carries the active date range in the query string.

**Responsive** — **1440:** 4-up, W 268, gap `spacing/6`. **1024:** 3-up, W (920 − 2×20)/3 = 293.33. **768:** 2-up, W (736 − 16)/2 = 360, `size: sm` (sparkline dropped — a 32px-tall sparkline at phone width is decoration).

---

### A4 · `ChartCard`

**Variant axes** — `type`: `line` | `bar` | `stacked-bar` | `donut` · `legend`: `top` | `right` | `none` · `state`: `default` | `loading` | `empty` | `partial`
**Properties** — `title`, `subtitle`, `series[]`, `xAxisLabel`, `yAxisFormat`, `height`, `comparisonSeries` (bool)

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap `spacing/4`, padding `spacing/5` | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Header | Fill × Hug | Horizontal, space-between, gap `spacing/3` | title `admin/section` `color/text/primary`; subtitle `admin/caption` `color/text/tertiary` on line 2 |
| Header right slot | Hug | Horizontal, gap `spacing/2` | optional segmented control (e.g. Value / Orders), `IconButton ghost` "Download PNG" |
| Legend `top` | Fill × 24 | Horizontal wrap, gap `spacing/4` | each item: 10×10 swatch `radius/sm` + `admin/caption` `color/text/secondary` + right-aligned value `admin/num` `color/text/primary` |
| Plot | Fill × `height` (default 280) | — | — |
| Grid lines | — | — | 1px `color/border/default`, horizontal only, 4 ticks; no vertical grid |
| Axis labels | — | — | `admin/caption` `color/text/tertiary`; Y axis formatted `৳38k`, X axis `02 Nov` |
| Line series | 2px stroke | — | series 1 `color/bg/brand`; series 2 (comparison) 2px dashed 4-4 `color/text/tertiary`; dots 3px on hover only; area fill `color/bg/brand-subtle` at 1 series only |
| Bar series | bar W = min(40, slot×0.6), `radius/sm` top corners | — | `color/bg/brand`; hovered bar `color/bg/brand` + 2px `color/border/strong` outline |
| Stacked-bar segments | — | — | fixed semantic order bottom→top: delivered `color/feedback/success`, shipped `color/feedback/info`, open `color/bg/brand`, cancelled `color/text/tertiary`, returned `color/feedback/danger`. Each segment also carries a distinct 4px-pitch SVG pattern (solid / 45° hatch / dots / 135° hatch / cross-hatch) so the chart survives greyscale printing and colour-blind readers. |
| Donut | outer R 100, inner R 64, gap 2° | — | slice fills cycle `color/bg/brand`, `color/feedback/info`, `color/status/low-stock`, `color/feedback/success`, `color/text/secondary`, `color/feedback/danger`, `color/text/tertiary`, each with the same pattern set; centre holds total `admin/num-lg` + `admin/overline` label |
| Tooltip | Hug, max-W 280 | Vertical, gap `spacing/1`, padding `spacing/3` | `color/bg/surface-raised`, `radius/md`, `e3`, 1px `color/border/default`; header = x value; rows = swatch + label + right-aligned `admin/num` |
| `empty` | plot area | — | `EmptyState` variant `chart`, 180 tall |
| `partial` | — | — | an `Alert info` strip above the plot: "6 of 7 days have data. 03 Nov is still rolling up." Partial days render with a dashed stroke and no area fill. |

**Accessibility** — every `ChartCard` ships a `<table>` of the same series, visually hidden, referenced by `aria-describedby`, and exposed by a "View as table" `Button ghost sm` in the header. The chart is not the only way to read the data.

**Responsive** — **1440:** as specified; wide charts 744, donut card 376. **1024:** full width 920, `height` 240, X-axis labels every other tick. **768:** full width 736, `height` 200, legend moves to `top` and wraps, donut outer R drops to 76 / inner 48, stacked bars switch to horizontal so day labels stay readable.

---

### A5 · `DateRangePicker`

**Variant axes** — `state`: `closed` | `open` · `mode`: `preset` | `custom` · `width`: `md` (240) | `sm` (180, icon + short label)
**Properties** — `activePreset`, `from`, `to`, `comparisonMode` (`previous-period` | `previous-year` | `none`)

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Trigger | W 240 × H 36 | Horizontal, gap `spacing/2`, padding 0 `spacing/3`, `radius/md` | `color/bg/surface`, 1px `color/border/default`; icon `calendar` 16 `color/text/secondary`; label `admin/body` `color/text/primary`; `chevron-down` 16 |
| Trigger label | — | — | `"Last 7 days"` on line 1 is not enough — the trigger renders **preset name + resolved dates**: `Last 7 days · 02–08 Nov` |
| Trigger `hover` / `open` | — | — | border `color/border/strong` / 2px `color/border/focus` ring |
| Panel | W 648 × Hug | Horizontal, gap 0, padding 0 | `color/bg/surface-raised`, `radius/lg`, `e3`, 1px `color/border/default`; anchored below-right, 8px offset |
| Preset rail | W 184 × Fill | Vertical, gap `spacing/1`, padding `spacing/3` | right border 1px `color/border/default` |
| Preset item | Fill × 36 | padding `spacing/2` `spacing/3`, `radius/md` | `admin/body` `color/text/primary`; `hover` bg `color/bg/subtle`; `active` bg `color/bg/brand-subtle` + 600 + trailing `check` 16 |
| Preset list | — | — | Today · Yesterday · Last 7 days · Last 30 days · This month · Last month · Custom. Each item shows its resolved Dhaka dates in `admin/caption` `color/text/tertiary` on a second line, resolved **now**: Today `08 Nov`, Yesterday `07 Nov`, Last 7 days `02–08 Nov`, Last 30 days `10 Oct – 08 Nov`, This month `01–08 Nov`, Last month `01–31 Oct` |
| Calendar area | W 464 × Hug | Vertical, gap `spacing/4`, padding `spacing/4` | two months side by side, each W 208, gap `spacing/4` |
| Day cell | 28×28 | centred, `radius/sm` | `admin/caption` `color/text/primary`; outside-range `color/text/tertiary`; today a 1px `color/border/strong` ring |
| Day `in-range` | — | — | bg `color/bg/brand-subtle`, square corners (so the range reads as continuous) |
| Day `endpoint` | — | — | bg `color/bg/brand`, text `color/text/on-brand`, `radius/sm` |
| Day `disabled` (future) | — | — | `color/text/tertiary`, no hover, `aria-disabled` |
| From/To fields | W 216 × 36 each | Horizontal, gap `spacing/3` | `Input sm`, `tabular-nums`, format `DD MMM YYYY`, typed entry accepted and validated inline |
| Comparison row | Fill × 36 | Horizontal, gap `spacing/2` | `admin/caption` `color/text/secondary` "Compare to" + `NativeSelect sm`: Previous period (26 Oct – 1 Nov) / Same period last year / None |
| Footer | Fill × 52 | Horizontal, space-between, padding `spacing/3` `spacing/4` | top border 1px `color/border/default`; left `admin/caption` `color/text/tertiary` "All dates Asia/Dhaka (+06)"; right `Button ghost md` Cancel + `Button primary md` Apply |

**Rules.** The range is inclusive of both endpoints and resolved against Dhaka midnight, not UTC — a 7-day range ending today covers `2026-11-02 00:00:00+06` to `2026-11-08 23:59:59+06`. The chosen range is written to the URL (`?from=2026-11-02&to=2026-11-08&preset=last-7-days`) so a screen is shareable and a drill-through keeps its period. Selecting a preset applies immediately and closes; `custom` requires Apply. `Esc` cancels, focus returns to the trigger.

**Responsive** — **1440 / 1024:** as specified (panel anchors right-aligned to stay on screen). **768:** trigger is Fill-width; the panel becomes a bottom sheet, full-width, `radius/xl` top corners, preset rail on top as a 2-column grid of 44-high tiles, one calendar month at a time with month arrows, sticky footer.

---

### A6 · `FormSection`

**Variant axes** — `layout`: `two-column` | `single` · `collapsible`: `true` | `false` · `state`: `default` | `collapsed` | `error`
**Properties** — `title`, `description`, `errorCount`, `badge` (e.g. "Required")

| Element | Geometry (1440) | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap 0 | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Root `error` | — | — | border 1px `color/feedback/danger` + a 3px `color/feedback/danger` left edge; header shows `alert-circle` 16 + "2 problems" in `admin/caption` `color/text/primary` |
| Header | Fill × Hug | Horizontal, space-between, padding `spacing/5` | title `admin/section`; description `admin/caption` `color/text/secondary`, max-W 560, line 2 |
| Header `collapsible` | — | — | whole header is a `button`, `aria-expanded`; trailing `chevron-down` 20 rotates 180° over `motion/fast` |
| Body `two-column` | Fill × Hug | Horizontal, gap `spacing/6`, padding 0 `spacing/5` `spacing/5` | left label column W 240 (Hug×Fill, top-aligned), right control column Fill |
| Body `single` | Fill × Hug | Vertical, gap `spacing/4`, padding 0 `spacing/5` `spacing/5` | — |
| Field row | Fill × Hug | Vertical, gap `spacing/2` | label `admin/body-strong`; required marker ` *` in `color/feedback/danger` plus the word "required" in the help text, never the asterisk alone |
| Help text | Fill × Hug | — | `admin/caption` `color/text/secondary`, max-W 560 |
| Error text | Fill × Hug | Horizontal, gap `spacing/1` | `alert-circle` 14 tinted `color/feedback/danger` + `admin/caption` `color/text/primary`, `aria-describedby`-linked, `role="alert"` |
| Divider between sections | Fill × 1 | — | not used — sections are separate cards separated by `spacing/6` |

**Responsive** — **1440:** `two-column`, label column 240. **1024:** `two-column`, label column 200. **768:** forced `single`; labels sit above controls; padding `spacing/4`; `collapsible` sections default to collapsed except the first.

---

### A7 · `FormActions` (sticky save bar)

**Variant axes** — `state`: `clean` | `dirty` | `saving` | `saved` | `error` · `position`: `sticky-bottom` | `inline`
**Properties** — `primaryLabel`, `secondaryLabel`, `destructiveLabel`, `dirtyFieldCount`, `lastSavedAt`

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × 64, sticky bottom 0 | Horizontal, space-between, padding `spacing/3` `spacing/6` | `color/bg/surface`, top border 1px `color/border/default`, `e4` (shadow upward); spans the content area only, not under the sidebar |
| Root `clean` | — | — | **not rendered.** A permanently visible save bar trains people to ignore it. |
| Root `dirty` | — | — | slides up over `motion/base` `decelerate`; left slot shows `circle-dot` 16 tinted `color/bg/brand` + "3 unsaved changes" `admin/body` `color/text/primary` |
| Root `error` | — | — | left slot `alert-circle` 16 tinted `color/feedback/danger` + "Could not save — 2 fields need attention" + `Button ghost sm` "Jump to first problem" |
| Left slot | Hug | Horizontal, gap `spacing/2` | — |
| Right slot | Hug | Horizontal, gap `spacing/3` | `Button ghost md` Discard · `Button outline md` secondary (e.g. "Save and add another") · `Button primary md` "Save changes" |
| `saving` | — | — | primary becomes `loading` with `Spinner` 16 and label "Saving…"; all inputs in the form go `aria-busy`, not disabled |
| `saved` | — | — | bar persists 2.4s showing `check-circle` 16 tinted `color/feedback/success` + "Saved · 2:30 pm", then slides out; a `Toast` is **not** also fired (two confirmations for one action is noise) |
| Destructive slot | Hug | — | pinned far left, `Button danger md` (e.g. "Delete product"), separated from the dirty indicator by a 24px gap and a 1px `color/border/default` vertical rule; always routes through `ConfirmDialog` (A23) |

`⌘S` / `Ctrl+S` saves from anywhere in the form and is announced via `aria-live`. Navigating away while `dirty` raises a `ConfirmDialog` variant `unsaved` offering Save / Discard / Stay.

**Responsive** — **1440 / 1024:** as specified. **768:** H 72, two rows — row 1 the dirty indicator, row 2 the buttons at Fill width in a 2-up grid with the primary on the right; the destructive action leaves the bar and moves to the bottom of the form as a Fill-width `Button danger`.

---

### A8 · `ImageUploader`

**Variant axes** — `state`: `empty` | `dragover` | `uploading` | `populated` | `error` · `tile`: `default` | `primary` | `dragging` | `selected`
**Properties** — `images[]`, `maxCount` (10), `maxBytes` (10 MB — matches `StoreProductImageRequest` `max:10240`), `acceptedTypes` (jpg/png/webp/avif), `requireAltBoth` (bool, default true)

| Element | Geometry (1440, inside a 744 form column) | Auto-layout | Tokens |
|---|---|---|---|
| Dropzone `empty` | Fill × 180 | Vertical, gap `spacing/3`, centred, padding `spacing/6` | `color/bg/subtle`, 2px dashed `color/border/default`, `radius/lg`; icon `image-plus` 32 `color/text/tertiary`; headline `admin/body-strong` "Drop images here or browse"; help `admin/caption` `color/text/secondary` "JPG, PNG, WebP or AVIF · max 10 MB each · up to 10 · square 1:1, minimum 1200×1200" |
| Dropzone `dragover` | — | — | bg `color/bg/brand-subtle`, 2px solid `color/bg/brand`, headline `color/text/primary`; scale 1.01 over `motion/fast` |
| Dropzone `populated` | Fill × 96 | Horizontal, centred | shrinks to a slim bar below the grid, same tokens as `empty` at 96 tall |
| Tile grid | Fill × Hug | Wrap, gap `spacing/4` | 4 per row at 744: tile W (744 − 3×16)/4 = **174**, H 174 + 96 meta = 270 |
| Tile image box | 174 × 174 | — | `radius/md`, 1px `color/border/default`, `object-fit: cover`, reserved aspect so nothing reflows |
| Tile `primary` | — | — | 2px `color/bg/brand` border + a chip at top-left: H 20, padding `spacing/1` `spacing/2`, `radius/sm`, bg `color/bg/brand`, text `color/text/on-brand` `admin/caption` "Primary" |
| Tile overlay (hover) | 174 × 36, bottom | Horizontal, gap `spacing/1`, padding `spacing/1` | bg `color/bg/surface` at 92% opacity; four 28×28 `IconButton ghost`: `crop`, `star` (make primary), `grip-vertical` (drag), `trash-2` |
| Tile drag handle | 28×28 | — | `grip-vertical`; keyboard reorder: focus the tile, `Space` to lift, `←`/`→` to move, `Space` to drop, with `aria-live` "Image 2 of 5 moved to position 1" |
| Tile `dragging` | — | — | `e3`, 4° rotation, 0.9 opacity; drop target shows a 2px `color/bg/brand` vertical insertion bar |
| Alt-text block | 174 × 96 | Vertical, gap `spacing/2`, padding `spacing/2` 0 | two stacked `Input xs` (H 32) |
| Alt field EN | 174 × 32 | — | prefix chip "EN" 20×20 `radius/sm` bg `color/bg/subtle` `admin/caption`; placeholder is the auto-composed value, greyed: "Brick Box 500 — image 1" |
| Alt field BN | 174 × 32 | — | prefix chip "বাং"; placeholder "ব্রিক বক্স ৫০০ — ছবি ১"; font `Hind Siliguri`, line-height ×1.15 per §3.2 |
| Alt missing | — | — | field border 1px `color/feedback/warning`, and the tile shows a 16px `alert-triangle` tinted `color/feedback/warning` at top-right with tooltip "Bangla alt text missing". ~~Not blocking~~ **Superseded (P4-CAT-06, 2026-11-11): missing alt text in either language blocks publishing** — the product API refuses `status: active` (422 on `status`) and the uploader shows a "required before publish" line. Still counted in the SEO checklist. |
| `uploading` tile | 174 × 174 | — | `color/bg/subtle` fill, `ProgressBar` 4px at the bottom in `color/bg/brand`, centred `admin/caption` "62% · 1.4 MB" |
| `error` tile | — | — | 1px `color/feedback/danger` border, centred `alert-circle` 20 tinted `color/feedback/danger`, `admin/caption` `color/text/primary` with the actual reason ("12.4 MB — over the 10 MB limit"), and a `Button ghost sm` "Remove" |
| Crop modal | W 680 × H 560 | Vertical | `radius/xl`, `e4`; 1:1 locked by default with a `NativeSelect` offering 1:1 / 4:5 / 16:9; grid overlay 3×3 in 1px `color/border/default` at 40% ; footer `Button ghost` Cancel + `Button primary` "Apply crop" |

Alt text in **both** languages per image is the spec, because the storefront ships Bangla and an English-only alt is a blank alt for half the audience. The auto-composed value from §7 (`{product} — {variant} — image {n}`) pre-fills both and is overridable.

**Responsive** — **1024:** 3 per row, tile W (684 − 2×16)/3 = 217. **768:** 2 per row, tile W (736 − 16)/2 = 360, image box 360×360, alt fields full width and stacked, drag reorder replaced by explicit "Move left / Move right" buttons (long-press drag on touch is unreliable and this screen matters).

---

### A9 · `VariantMatrixEditor`

**Variant axes** — `state`: `no-attributes` | `attributes-set` | `generated` | `dirty` · `row`: `default` | `edited` | `invalid` | `disabled`
**Properties** — `attributes[]`, `rows[]`, `bulkField`, `showCost` (permission-gated)

| Element | Geometry (1440, 1144 content) | Auto-layout | Tokens |
|---|---|---|---|
| Attribute builder | Fill × Hug | Vertical, gap `spacing/3`, padding `spacing/5` | inside a `FormSection`; one row per attribute |
| Attribute row | Fill × 44 | Horizontal, gap `spacing/3` | `NativeSelect` W 200 (attribute) + value `Chip` list (Wrap, gap `spacing/2`) + `Button ghost sm` "Add value" + `IconButton ghost` `trash-2` |
| Generate bar | Fill × 56 | Horizontal, space-between, padding `spacing/3` `spacing/5` | bg `color/bg/brand-subtle`, `radius/md`; text `admin/body` "2 attributes × 3 values = **6 combinations**"; `Button primary md` "Generate matrix" |
| Matrix table | Fill × Hug | `DataTable` instance, `compact` density | columns below sum to 1144 |
| — Enabled | W 56 | — | `Switch sm`; off = the combination is not sold. Disabled rows grey to `color/text/tertiary` and keep their values. |
| — Variant | W 200 | — | `admin/body-strong` "Red / Large"; second line `admin/caption` `color/text/tertiary` attribute names |
| — SKU | W 168 | — | `Input xs` `admin/mono`; auto-suggested `TMB-BRK-500-RD-L`; uniqueness checked server-side on blur, duplicate → 1px `color/feedback/danger` + "SKU already on TMB-RCC-118" |
| — Price | W 128 | right | `Input xs` `admin/num`, `৳` prefix adornment |
| — Cost | W 128 | right | `Input xs` `admin/num`; **hidden entirely** without the `products.view_cost` permission, not merely masked, and every change writes to `audit_sensitive` |
| — Margin | W 104 | right | computed, read-only, `admin/num` `color/text/secondary`: `39.5%`; turns to `color/text/primary` with a 14px `alert-triangle` tinted `color/feedback/warning` below 15% |
| — Stock | W 104 | right | `Input xs` `admin/num`; on an existing variant this field is **read-only** and links to `StockAdjustmentForm` — stock is only ever changed by a movement, never by typing over it |
| — Weight (g) | W 104 | right | `Input xs` `admin/num` |
| — Barcode | W 152 | — | `Input xs` `admin/mono`, optional |
| Bulk-edit bar | Fill × 52 | Horizontal, gap `spacing/3`, padding `spacing/2` `spacing/4` | appears when ≥1 row is selected; "Set [NativeSelect: Price / Cost / Weight] to [Input] for 4 selected" + `Button primary sm` Apply |
| Row `edited` | — | — | 2px `color/bg/brand` left border + a 6px `color/bg/brand` dot in the Enabled cell's top-right corner |
| Row `invalid` | — | — | 2px `color/feedback/danger` left border; the offending cell gets the border and the message appears in a row-spanning 32-high strip beneath it, bg `color/bg/subtle` |
| Footer summary | Fill × 44 | Horizontal, gap `spacing/6`, padding `spacing/2` `spacing/4` | `admin/caption` `color/text/secondary`: "6 combinations · 5 enabled · total stock 118 · stock value ৳34,220 · lowest margin 22.4%" |

**Regeneration safety.** Adding an attribute value appends rows and never touches existing ones. Removing a value shows a `ConfirmDialog` listing the exact SKUs that will be disabled and their current stock; variants with stock or order history are **disabled, never deleted**, because `order_items.variant_id` is `ON DELETE SET NULL` and deleting one silently orphans order history.

**Responsive** — **1024:** horizontal scroll with Enabled + Variant frozen. **768:** one card per variant — header line "Red / Large" + Enabled switch, then a 2-column field grid (Price/Cost, Stock/Weight), SKU full width. 6 variants = 6 cards; the bulk bar becomes a bottom sheet.

---

### A10 · `SlugField`

**Variant axes** — `state`: `default` | `editing` | `checking` | `available` | `taken` | `redirect-warning` · `entity`: `product` | `category` | `page` | `blog-post`
**Properties** — `value`, `publishedValue`, `baseUrl`, `isPublished`, `redirectCount`

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap `spacing/2` | — |
| Input row | Fill × 40 | Horizontal, gap 0 | `radius/md`, 1px `color/border/default` |
| URL prefix | Hug × 40 | padding 0 `spacing/3` | bg `color/bg/subtle`, `admin/mono` `color/text/tertiary` "toymartbd.com/products/", right border 1px `color/border/default` |
| Slug input | Fill × 40 | padding 0 `spacing/3` | `admin/mono` `color/text/primary`; auto-slugified on type (lowercase, `-`, ASCII transliteration of Bangla), max 80 chars |
| Trailing slot | 40 × 40 | centred | `checking` → `Spinner` 16; `available` → `check` 16 tinted `color/feedback/success`; `taken` → `x` 16 tinted `color/feedback/danger` |
| "Edit" affordance | Hug × 40 | — | when `isPublished` and not `editing`, the field is read-only with a trailing `Button ghost sm` "Edit URL" — a published URL should take one deliberate act to change |
| Status line `available` | Fill × Hug | Horizontal, gap `spacing/1` | `admin/caption` `color/text/secondary` "Available" |
| Status line `taken` | — | — | `alert-circle` 14 tinted `color/feedback/danger` + `admin/caption` `color/text/primary` "Already used by *Brick Box 500 — 1000 pcs*" + `Button ghost sm` "Open that product" |
| **Redirect warning panel** | Fill × Hug | Horizontal, gap `spacing/3`, padding `spacing/4` | bg `color/bg/subtle`, 1px `color/feedback/warning`, 3px `color/feedback/warning` left edge, `radius/md`; icon `corner-down-right` 20 tinted `color/feedback/warning` |
| — Headline | — | — | `admin/body-strong` `color/text/primary` "Changing this URL creates a 301 redirect" |
| — Body | max-W 600 | — | `admin/caption` `color/text/secondary`, with the two URLs stacked in `admin/mono`: old `…/products/brick-box-500-pcs` → new `…/products/classic-brick-box-500`. Then: "The old URL has been live since 14 Jul 2026 and is linked from 2 blog posts and the homepage 'Best sellers' block. The redirect will be added automatically and will appear in **SEO → Redirect manager**." |
| — Checkbox | — | — | `Checkbox` "I understand the old URL will redirect" — **required** to leave the field when `isPublished` and the value changed. This is the one piece of friction in the product form and it is there because an un-noticed slug change is an SEO loss that surfaces weeks later. |
| Status line `redirect-warning` + unpublished | — | — | panel suppressed; `admin/caption` `color/text/tertiary` "Not published yet — no redirect needed" |

**Responsive** — **768:** the URL prefix moves above the input as `admin/caption` `color/text/tertiary` (a 200px prefix on a 736 screen leaves nothing to type in); the redirect panel's two URLs stack with "was" / "now" labels.

---

### A11 · `SeoPanel`

**Variant axes** — `tab`: `basics` | `serp` | `social` | `advanced` · `titleLength`: `short` | `ok` | `long` · `state`: `default` | `inherited`
**Properties** — `metaTitle`, `metaDescription`, `ogTitle`, `ogDescription`, `ogImage`, `robots`, `canonical`, `inheritedFrom`

| Element | Geometry (1440, 744 column) | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap 0 | `FormSection` shell; `Tabs` strip H 44 under the header |
| Title field | Fill × Hug | Vertical, gap `spacing/2` | `Input` H 40 |
| **Pixel-width meter** | Fill × 20 | Horizontal, gap `spacing/2` | track H 4, `radius/full`, bg `color/bg/subtle`; fill measured in **rendered pixels at Google's 20px Arial desktop metric**, not characters — "Brick Box 500 pcs — Toy Mart BD" is 31 characters but 318 px |
| — fill `ok` (≤580 px) | — | — | `color/feedback/success` |
| — fill `long` (>580 px) | — | — | `color/feedback/warning`, and the overflow portion of the preview title is rendered in `color/text/tertiary` with a trailing `…` so the truncation is literally visible |
| — Counter | Hug | — | `admin/caption` `color/text/secondary` `tabular-nums`: "318 / 580 px · 31 chars" |
| Description field | Fill × Hug | — | `Textarea` H 80, same meter, limit 920 px / ~155 chars |
| **SERP preview** | Fill × Hug | Vertical, gap `spacing/1`, padding `spacing/4` | bg `color/bg/surface`, 1px `color/border/default`, `radius/md`; `admin/overline` `color/text/tertiary` eyebrow "GOOGLE RESULT — DESKTOP" with a `NativeSelect sm` toggling Desktop / Mobile |
| — Breadcrumb line | — | — | `admin/caption` `color/text/secondary` "toymartbd.com › products › classic-brick-box-500" |
| — Title line | max-W 600 | — | 20/400 `color/feedback/info`, single line, pixel-truncated with `…` |
| — Description line | max-W 600 | — | 14/400 `color/text/secondary`, 2 lines max |
| — Rich result row | — | — | when the product has ≥1 approved review it renders the star row + "4.6 ★★★★☆ · 23 reviews · ৳1,850 · In stock" from the actual Product schema, with a `Button ghost sm` "Validate schema" |
| **OG preview** | W 480 × Hug | Vertical, gap 0 | bg `color/bg/surface-raised`, 1px `color/border/default`, `radius/md`, `overflow: clip` |
| — Image | 480 × 251 (1.91:1) | — | `color/bg/subtle` placeholder with `admin/caption` "1200 × 630 recommended · current image is 1200 × 1200 and will be cropped top-and-bottom" when the aspect is wrong |
| — Meta block | Fill × Hug | Vertical, gap `spacing/1`, padding `spacing/3` | domain `admin/overline` `color/text/tertiary`; title `admin/body-strong` 1 line; description `admin/caption` `color/text/secondary` 1 line |
| — Platform toggle | Hug × 32 | — | segmented: Facebook / WhatsApp / X. WhatsApp is first-class because that is the sharing channel in this market; it crops to 1:1 and the preview reflects that. |
| Advanced tab | Fill × Hug | Vertical, gap `spacing/4` | `robots` as 4 `Checkbox`es (index, follow, noarchive, nosnippet) with the resolved header shown in `admin/mono` `color/bg/subtle`; `canonical` as a `SlugField`-style URL input; `Switch` "Exclude from sitemap" |
| `inherited` state | — | — | fields show the inherited value in `color/text/tertiary` with a trailing `Button ghost sm` "Override"; a 32-high strip above reads "Inherited from the *Building & Construction* category template" |
| Checklist | Fill × Hug | Vertical, gap `spacing/2`, padding `spacing/4` | bg `color/bg/subtle`, `radius/md`; each line = 16px `check`/`alert-triangle`/`x` + `admin/caption`. Live example: ✓ Title length · ✓ Description length · ✓ Primary image set · ⚠ 2 images missing Bangla alt text · ✓ Slug unique · ✓ In 1 sitemap |

**Responsive** — **1024:** SERP preview Fill width, OG preview Fill (image scales to 1.91:1). **768:** the four tabs become an `Accordion`; previews stack; the pixel meter stays (it is the point of the component).

---

### A12 · `OrderStatusBadge` — the three-dimension problem

**The problem, stated precisely.** `orders` carries three orthogonal columns (DATABASE-DESIGN §3.4): `status` (business workflow, 11 values), `payment_status` (money, 7 values), `fulfillment_status` (goods, 4 values). 11 × 7 × 4 = 308 nominal combinations. Rendering three equal chips per row gives 36 chips on a 12-row board and every one of them competes for attention. Rendering one composite chip means lying about two dimensions. Neither is acceptable.

**Worse, three columns are not enough.** In a COD market an order can be `status=delivered`, `payment_status=paid`, and the ৳4,550 can still be sitting in a courier's cash box. `payment_status=paid` means *the customer has paid*, not *we have the money*. So the badge renders a **fourth, derived** fact — the settlement state — sourced from `courier_settlement_items` / `financial_transactions.cod_received`, and labels it as derived.

**The solution: a fixed-position triad with exception-driven emphasis.**

Three slots, always in the same left-to-right order, so **position encodes dimension** and the operator never has to read a label to know which dimension they are looking at:

```
┌─────────────────────────────┐
│ ▸ Delivered                 │   slot 1  WORKFLOW   (orders.status)
│ ৳ Paid · with courier       │   slot 2  MONEY      (payment_status + settlement)
│ ▣ All items delivered       │   slot 3  GOODS      (fulfillment_status)
└─────────────────────────────┘
```

Then the move that makes it quiet: **define the expected pairing for each workflow state, and only deviations get visual weight.**

| `status` | expected `payment_status` (COD) | expected `payment_status` (prepaid) | expected `fulfillment_status` |
|---|---|---|---|
| `pending` | unpaid | paid | unfulfilled |
| `confirmed` | unpaid | paid | unfulfilled |
| `processing` | unpaid | paid | unfulfilled |
| `ready_to_ship` | unpaid | paid | unfulfilled |
| `shipped` | unpaid | paid | fulfilled |
| `delivered` | paid | paid | fulfilled |
| `completed` | paid | paid | fulfilled |
| `cancelled` | unpaid | refunded | unfulfilled |
| `returned` | unpaid \| refunded | refunded | returned |
| `partially_returned` | partially_paid | partially_refunded | partially_fulfilled |
| `on_hold` | any | any | unfulfilled |

**Three severities**, and this is the whole design:

| Severity | When | Slot 1 | Slots 2 and 3 |
|---|---|---|---|
| `expected` | actual == expected | full chip | **plain text, `admin/caption`, `color/text/tertiary`, no fill, no border, no icon tint** |
| `notable` | a benign, trackable divergence | full chip | chip with `color/bg/subtle` fill, 14px icon tinted `color/feedback/info`, leading `info` glyph, label `color/text/primary` |
| `anomaly` | a combination that should not exist | full chip | chip with `color/bg/subtle` fill, 2px `color/feedback/warning` (or `danger`) left border, 14px `alert-triangle`, label `color/text/primary`, plus the plain-language reason |

`notable` list: COD paid but unsettled ("with courier") · advance received before dispatch · `partially_refunded` on a delivered order · `on_hold` under 48 h.
`anomaly` list: `delivered`/`completed` with `payment_status != paid` ("Delivered, not paid") · `cancelled` with `paid_total > refunded_total` ("Cancelled — refund pending, 3 days") · any pre-ship status with `fulfillment_status != unfulfilled` ("Goods sent before confirmation") · `shipped` with `fulfillment_status = unfulfilled` ("Shipped, nothing fulfilled") · `returned` with `payment_status = paid` and `refunded_total = 0` · `payment_status = failed` · `on_hold` over 48 h.

The result on the Part C board: **11 of 12 rows show one coloured chip and two lines of quiet grey text.** One row — TMB-26-00128 — shows an amber-edged money chip. The exception is the only thing the eye lands on, which is the entire point.

**Variant axes** — `form`: `triad-stacked` (table rows, 3 lines) | `triad-inline` (1 line, detail header) | `single` (one dimension only, for filter chips) · `severity`: `expected` | `notable` | `anomaly` · `dimension` (for `single`): `workflow` | `money` | `goods` · `density`: `comfortable` | `compact`
**Properties** — `status`, `paymentStatus`, `fulfillmentStatus`, `isCod`, `settlementState` (`with-courier` | `settled` | `n/a`), `advanceAmount`, `anomalyReason`, `ageHours`

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root `triad-stacked` comfortable | W 188 × Hug | Vertical, gap `spacing/1` | transparent; sits inside a 188 table cell |
| Root `triad-stacked` compact | W 188 × 32 | Vertical, gap 0 | slot 1 full, slots 2+3 merged onto one line joined by ` · `, truncating slot 3 first |
| Slot 1 chip | Hug × 22 | Horizontal, gap `spacing/1`, padding `spacing/1` `spacing/2`, `radius/sm` | bg `color/bg/subtle`, 1px `color/border/default`, label `admin/caption` 600 `color/text/primary`, icon 14 tinted per state below |
| Slot 2/3 `expected` | Hug × 16 | Horizontal, gap `spacing/1` | no fill, no border; label `admin/caption` `color/text/tertiary`; 12px icon also `color/text/tertiary` |
| Slot 2/3 `notable` | Hug × 20 | padding `spacing/1` `spacing/2`, `radius/sm` | bg `color/bg/subtle`, label `admin/caption` `color/text/primary`, icon 14 tinted `color/feedback/info` |
| Slot 2/3 `anomaly` | Hug × 20 | padding `spacing/1` `spacing/2`, `radius/sm`, left border 2px | bg `color/bg/subtle`, left border `color/feedback/warning` \| `color/feedback/danger`, icon 14 `alert-triangle`, label `admin/caption` `color/text/primary` |
| Root `triad-inline` | Hug × 28 | Horizontal, gap `spacing/2` | all three as full chips H 28 separated by a 1px × 16 `color/border/default` rule; used in the order-detail header where there is room |
| Tooltip (any slot) | max-W 300 | Vertical, gap `spacing/1`, padding `spacing/3` | `color/bg/surface-raised`, `e3`, `radius/md`; line 1 the dimension name, line 2 the raw enum in `admin/mono`, line 3 the source column, line 4 "Changed 04 Nov, 3:12 pm by Rina" |

**Workflow icon + label map** (icon shape is the non-colour carrier; tint is reinforcement):

| `status` | Label | Icon | Icon tint |
|---|---|---|---|
| `pending` | Pending | `clock` | `color/status/low-stock` |
| `confirmed` | Confirmed | `check` | `color/feedback/info` |
| `processing` | Processing | `loader` | `color/feedback/info` |
| `ready_to_ship` | Ready to ship | `package-check` | `color/feedback/info` |
| `shipped` | Shipped | `truck` | `color/feedback/info` |
| `delivered` | Delivered | `circle-check` | `color/feedback/success` |
| `completed` | Completed | `check-check` | `color/feedback/success` |
| `cancelled` | Cancelled | `circle-slash` | `color/text/tertiary` |
| `returned` | Returned | `undo-2` | `color/feedback/danger` |
| `partially_returned` | Part. returned | `undo-dot` | `color/status/low-stock` |
| `on_hold` | On hold | `pause` | `color/status/low-stock` |

**Money slot labels** (icon `banknote` for unpaid/paid, `wallet` + `clock` for with-courier, `rotate-ccw` for refunds, `circle-alert` for failed):
`Unpaid` · `Paid · in bank` · `Paid · with courier` (notable) · `Part. paid ৳500 advance` · `Part. paid ৳1,330 · with courier` · `Payment pending` · `Refunded ৳200` · `Part. refunded ৳300` (notable) · `Payment failed` (anomaly).
`Paid · with courier` always appends the amount in the tooltip and the expected settlement date.

**Goods slot labels** (icon `package` family):
`Not sent` · `Partly sent` · `All items delivered` · `Returned to us`.

**Filter integration.** The order-board filter exposes the three dimensions as three independent multi-selects plus a fourth control, **"Only show anomalies"**, which is a single `Switch`. In a shop that is behind on its board, that switch is the first thing anyone touches.

**Responsive** — **1440:** `triad-stacked` in a 188 column. **1024:** unchanged (the column is frozen out of the horizontal scroll only if it is one of the first two — it is not, so it scrolls). **768:** slot 1 renders as a chip on the card's line 3; slots 2 and 3 render only when `notable` or `anomaly`; when both are `expected`, nothing is drawn — a phone-sized card shows the workflow state and the exceptions, and nothing else.

---

### A13 · `OrderStatusStepper`

Used on the order-detail screen and nowhere else. It answers "where is this order and what is left" for all three dimensions at once without pretending they are one sequence.

**Variant axes** — `orientation`: `horizontal` | `vertical` · `track`: `workflow` | `money` | `goods` · `state` per node: `done` | `current` | `upcoming` | `skipped` | `failed`
**Properties** — `status`, `paymentStatus`, `fulfillmentStatus`, `timestamps{}`, `actorNames{}`, `isCod`

| Element | Geometry (1440, 744 main column) | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap `spacing/5`, padding `spacing/5` | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Track group | Fill × Hug | Vertical, gap `spacing/3` | three stacked tracks, each labelled |
| Track label | Hug × 16 | Horizontal, gap `spacing/2` | `admin/overline` `color/text/secondary`: "WORKFLOW" / "MONEY" / "GOODS"; trailing `admin/caption` `color/text/tertiary` with the source column in `admin/mono`: `orders.status` |
| Track rail | Fill × 48 | Horizontal, gap 0 | — |
| Node | Hug × 48 | Vertical, gap `spacing/1`, centred | — |
| Node dot `done` | 20 × 20, `radius/full` | — | bg `color/feedback/success`, 12px `check` in `color/text/on-brand` |
| Node dot `current` | 24 × 24, `radius/full` | — | bg `color/bg/brand`, 3px `color/bg/canvas` inner ring, 2px `color/bg/brand` outer ring at 30% opacity; 12px state icon in `color/text/on-brand` |
| Node dot `upcoming` | 20 × 20, `radius/full` | — | bg `color/bg/subtle`, 1.5px `color/border/default` stroke, empty |
| Node dot `skipped` | 20 × 20, `radius/full` | — | bg `color/bg/subtle`, 1.5px dashed `color/border/default`, 12px `minus` in `color/text/tertiary` |
| Node dot `failed` | 20 × 20, `radius/full` | — | bg `color/feedback/danger`, 12px `x` in `color/text/on-brand` |
| Connector | Fill × 2 | — | `done→done` solid `color/feedback/success`; `done→current` solid `color/bg/brand`; anything→`upcoming` 2px dashed 4-4 `color/border/default`; into `failed` solid `color/feedback/danger` |
| Node label | Hug × 16 | — | `admin/caption` 600 `color/text/primary` for `done`/`current`, `color/text/tertiary` for `upcoming`/`skipped` |
| Node timestamp | Hug × 14 | — | `admin/caption` `color/text/tertiary` "04 Nov · 3:12 pm"; blank for `upcoming` |
| Node actor | — | — | tooltip only: "Marked by Rina Akter (order_operator)" or "Pathao webhook" |
| Branch marker | — | — | when a track leaves the happy path (`cancelled`, `returned`, `partially_returned`), the rail bends: the remaining happy-path nodes render `skipped` and a new node is appended after a 16px gap with a 2px `color/feedback/danger` connector and a `git-branch` 14 icon beside its label |

**Track node sequences** (fixed, so the stepper is readable before it is read):
- **Workflow:** Pending → Confirmed → Processing → Ready to ship → Shipped → Delivered → Completed. Branches: Cancelled (from any of the first four), Returned / Part. returned (from Shipped), On hold (overlay badge on the current node, not a sequence position).
- **Money (COD):** Unpaid → Collected by courier → Settled to us. **This is the track that makes the COD reality visible**, and its third node is derived from `courier_settlements`, labelled "derived from settlement PTH-STL-2026-45" in the tooltip.
- **Money (prepaid):** Payment pending → Paid → (Refunded, as a branch).
- **Goods:** Not sent → Packed → Handed to courier → All items delivered. Branches: Partly delivered, Returned to us.

**Responsive** — **1440:** `horizontal`, three stacked tracks, each 48 tall; node spacing = Fill distributed. **1024:** `horizontal`, labels truncate to one line with tooltips; timestamps drop to date only. **768:** `vertical` — each track becomes a 3-column block (dot rail 24 / label Fill / timestamp 88), tracks separated by a 1px `color/border/default` divider and a sticky `admin/overline` track label. The vertical form is actually better and is what the 768 order-detail uses by default.

---

### A14 · `RiskScoreCard` — two panels, never one number

**Non-negotiable.** `customer_risk_profiles` holds two kinds of evidence and the schema keeps them apart on purpose (DATABASE-DESIGN §3.3): `internal_score` + `score_breakdown` + `score_computed_at` are **ours**, a heuristic; `courier_data` + `courier_checked_at` + `courier_delivered_count` + `courier_cancelled_count` are **a provider's verified records**. The operator's decision is "do I phone this customer before booking", and to make it they need to know whose evidence it is and how old. The UI therefore renders **two separately titled, separately timestamped, visually distinct panels side by side, with no combined figure anywhere on the screen.** There is no average, no blended badge, no "overall risk" number.

**Variant axes** — `form`: `full` (two panels, 1144) | `compact` (stacked, 376 rail) · `panelA`: `scored` | `no-signals` | `stale` | `computing` · `panelB`: `not-connected` | `never-checked` | `fresh` | `stale` | `lookup-failed` | `scoring-disabled` · `manualStatus`: `normal` | `watch` | `blacklist` | `whitelist`
**Properties** — `phone`, `internalScore`, `scoreBreakdown[]`, `scoreComputedAt`, `providerName`, `providerConnected`, `courierData{}`, `courierCheckedAt`, `manualStatus`, `statusReason`, `statusSetBy`, `statusSetAt`

#### Shell

| Element | Geometry (1440) | Auto-layout | Tokens |
|---|---|---|---|
| Root `full` | W 1144 × Hug | Horizontal, gap `spacing/6`, align top | transparent |
| Panel A | W 560 × Hug | Vertical, gap `spacing/4`, padding `spacing/5` | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1`, **3px `color/bg/brand` top edge** |
| Panel B | W 560 × Hug | Vertical, gap `spacing/4`, padding `spacing/5` | `color/bg/surface`, `radius/lg`, **1px dashed `color/border/strong`**, no shadow, **3px `color/text/secondary` top edge** |
| Panel header | Fill × Hug | Vertical, gap `spacing/1` | — |
| Panel eyebrow | Hug × 16 | — | `admin/overline`; A: `color/bg/brand` is not legible at 11px — bind to `color/text/secondary` and carry the distinction with the top edge |
| Panel title | Fill × 24 | Horizontal, gap `spacing/2` | `admin/section` `color/text/primary` |
| Panel provenance line | Fill × Hug | Horizontal, gap `spacing/2` | `admin/caption` `color/text/tertiary`, 14px icon |

**The two panels are distinguished four ways at once**, so the difference survives greyscale, zoom and a tired operator: (1) a solid vs **dashed** border; (2) `e1` vs no shadow; (3) a brand vs neutral top edge; (4) explicit titles and provenance lines that name the source.

- **Panel A** — eyebrow `OUR ASSESSMENT`, title **"Toy Mart risk score"**, provenance `cpu` icon + "Computed by us from this phone's order history · 08 Nov 2026, 2:18 pm · recomputed on every order event".
- **Panel B** — eyebrow `EXTERNAL EVIDENCE`, title **"Courier-verified delivery record"**, provenance `shield` icon + the provider name and the retrieval timestamp, or the not-connected message below.

#### Panel A — our score, with the breakdown

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Score block | Fill × 96 | Horizontal, gap `spacing/5` | — |
| Gauge | 96 × 96 | — | 220° arc, R 44, track 8px `color/bg/subtle`; value arc 8px, `radius/full` caps; **arc colour is reinforcement only** — `color/feedback/success` 0–39, `color/status/low-stock` 40–69, `color/feedback/danger` 70–100 |
| Gauge centre | — | — | number `admin/num-lg` `color/text/primary` "80"; below it `admin/caption` `color/text/tertiary` "of 100" |
| Level chip | Hug × 28 | Horizontal, gap `spacing/2`, padding `spacing/1` `spacing/3`, `radius/sm` | bg `color/bg/subtle`, 2px left border in the level token, icon `shield-check` (low) / `shield-alert` (medium) / `shield-x` (high) tinted with the level token, label `admin/body-strong` `color/text/primary` **"High risk · 80 / 100"** — number and word together, so colour is never load-bearing |
| Verdict line | Fill × Hug | — | `admin/body` `color/text/primary`, one plain sentence: "Phone before booking. 4 of this phone's 7 previous orders were cancelled and 2 came back from the courier." |
| Policy line | Fill × Hug | Horizontal, gap `spacing/2` | `admin/caption` `color/text/secondary` + 14px `info`: "High risk requires phone verification before courier booking (`verification_status = pending`). The score never cancels an order by itself." |
| **Breakdown list** | Fill × Hug | Vertical, gap `spacing/2` | the required element — the number alone is not auditable |
| Breakdown row | Fill × 36 | Horizontal, gap `spacing/3` | 4 parts: signal name (W 196, `admin/body` `color/text/primary`), input evidence (Fill, `admin/caption` `color/text/secondary`), bar (W 96), points (W 56, right, `admin/num`) |
| Breakdown bar | 96 × 6, `radius/full` | — | track `color/bg/subtle`; fill proportional to `points / weight`, `color/text/secondary` for contributing rows, nothing drawn for zero rows |
| Breakdown row (zero) | — | — | signal name `color/text/tertiary`, evidence "No signal", points "0", and the row is moved below a 1px `color/border/default` divider labelled `admin/overline` `color/text/tertiary` "SIGNALS NOT TRIGGERED (3)", collapsed by default |
| Breakdown total | Fill × 36 | Horizontal, space-between | top border 1px `color/border/strong`; "Total" `admin/body-strong`; "80 / 100" `admin/num` |
| Model note | Fill × Hug | — | `admin/caption` `color/text/tertiary`: "Rate signals are capped: a 30% cancellation rate or a 20% return rate scores the full weight. With fewer than 3 previous orders, rate signals carry half weight. Weights are editable in **Risk → Rule configuration**." |
| `no-signals` state | Fill × Hug | Vertical, gap `spacing/2` | gauge still drawn at 0 with a `color/feedback/success` arc; level chip "Low risk · 0 / 100"; verdict "No risk signals on this phone. 3 previous orders, all delivered."; the breakdown shows **all eight rows at zero, expanded** — an empty panel would read as "not computed" |
| `stale` state | — | — | an `Alert warning` strip above the gauge: "Last computed 11 days ago, before this phone's most recent order. Recompute." + `Button outline sm` "Recompute now" |
| `computing` | — | — | gauge skeleton, breakdown skeleton rows, `aria-busy` |

#### Panel B — courier-verified data, including the state we are actually in

`courier_fraud_lookups` is populated only from a provider's officially published endpoint — today that is Steadfast's `GET /fraud_check/{phone}`. **Toy Mart has no Steadfast merchant account yet**, so there are no credentials, no lookups, and no external evidence for any phone number in the system. Panel B must say exactly that.

| `panelB` state | What it renders |
|---|---|
| **`not-connected`** | **The live state.** Border 1px dashed `color/border/strong`, body bg `color/bg/subtle`, no numbers anywhere. Centred block, 240 tall, Vertical, gap `spacing/3`, padding `spacing/6`: 32px `plug-zap-off` icon in `color/text/tertiary`; `admin/section` `color/text/primary` **"Provider not connected"**; `admin/body` `color/text/secondary` max-W 400: "No courier account is connected, so there is no external delivery record for this phone. This is **not** a clean record — it is an absence of evidence."; then a 1px `color/border/default` divider and `admin/caption` `color/text/tertiary`: "Steadfast's `/fraud_check/{phone}` needs a merchant account. Connect one in **System → Courier settings**."; then `Button outline md` "Open courier settings". |
| `never-checked` | Provider connected, no lookup yet for this phone. 160-tall centred block: 24px `search` icon, `admin/body` "Not checked yet", `Button primary md` "Check with Steadfast now", `admin/caption` "One lookup · result cached for 24 hours". |
| `fresh` | Full data block (below), with a `check` 14 tinted `color/feedback/success` + "Retrieved 08 Nov 2026, 1:52 pm · 38 minutes ago". |
| `stale` | Same data block, dimmed to 70% opacity, plus an `Alert warning` strip: "This record is 9 days old. The customer may have ordered elsewhere since." + `Button outline sm` "Refresh". |
| `lookup-failed` | 160-tall block: 24px `wifi-off` tinted `color/feedback/danger`, `admin/body-strong` "Lookup failed", `admin/caption` `color/text/secondary` with the literal provider message and the request id, `Button outline md` "Try again". **Previously cached data, if any, stays visible below with its original timestamp** — a failed refresh must not erase the last known truth. |
| `scoring-disabled` | Shown when a provider returns ratios but `scoring_disabled: true` (Steadfast's `/fraud_check/score/{phone}` currently returns `score: null`, `level: null`). The ratio rows render; the provider-score row renders as "Not provided by Steadfast" in `color/text/tertiary` — **never as 0**. |

**Panel B data block** (component-state reference; **no row of it is present in the Part C dataset**, because the provider is not connected):

| Element | Geometry | Tokens |
|---|---|---|
| Ratio row | Fill × 44, Horizontal, gap `spacing/3` | label W 200 `admin/body`; value `admin/num` `color/text/primary`; bar 120 × 6 `radius/full` track `color/bg/subtle` |
| Rows | — | Total parcels · Delivered · Cancelled · Fraud reports filed · Delivery ratio (`delivery_ratio`, 4dp in the tooltip, shown as a percentage) · Cancellation ratio |
| Provider score row | Fill × 44 | shown only if the provider actually supplies one; otherwise the `scoring-disabled` treatment |
| Raw payload | Fill × Hug | `Accordion` "Show provider response", body `admin/mono` 12 in `color/bg/subtle`, `radius/sm`, max-H 200 scroll — so a dispute can be settled against what the provider actually said |

#### Manual status strip (spans both panels)

| Element | Geometry | Tokens |
|---|---|---|
| Root | W 1144 × 64 | Horizontal, space-between, padding `spacing/4` `spacing/5`, `radius/lg`, bg `color/bg/subtle`, 1px `color/border/default`; appears **below** both panels |
| Status chip | Hug × 28 | `normal` → `color/text/secondary` icon `circle`; `watch` → `color/status/low-stock` icon `eye`; `blacklist` → `color/feedback/danger` icon `ban`; `whitelist` → `color/feedback/success` icon `badge-check`. Label always includes the word. |
| Reason + attribution | Fill | `admin/body` `color/text/primary` reason; `admin/caption` `color/text/tertiary` "Set by Mominul Islam · 29 Oct 2026, 11:04 am" |
| Actions | Hug | `Button outline md` "Change status" → `ConfirmDialog` requiring the phone number to be typed for `blacklist` |

A manual `blacklist` or `whitelist` **overrides** the booking gate and says so inline — "Blacklisted: new orders from this phone are held at `on_hold` automatically" — but it does not alter, hide or recolour the score in Panel A. Operator judgement and computed score stay separately visible.

**Responsive** — **1440:** `full`, 560 + 560. **1024:** `full`, 450 + 450 (gap `spacing/5`); the breakdown bar drops to 72 wide. **768:** `compact` — Panel A then Panel B stacked at Fill width, gap `spacing/4`; the gauge shrinks to 72×72 and moves inline with the level chip; the breakdown becomes a 2-line-per-signal list (name + points on line 1, evidence on line 2) with no bars. The manual strip becomes a 2-row block. **Panel B is never collapsed by default on any width** — hiding the provenance of external evidence behind a tap defeats the component.

---

### A15 · `CustomerPhoneLookup`

**Variant axes** — `state`: `idle` | `invalid` | `searching` | `found` | `not-found` · `context`: `page` (the Risk screen) | `inline` (manual order creation, order detail)
**Properties** — `query`, `normalisedPhone`, `customer{}`, `riskProfile{}`, `orderHistory[]`

| Element | Geometry (1440) | Auto-layout | Tokens |
|---|---|---|---|
| Search block | W 1144 × 96 | Vertical, gap `spacing/2`, padding `spacing/5` | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Input | W 400 × 48 | Horizontal, gap `spacing/3`, padding 0 `spacing/4`, `radius/md` | 1px `color/border/strong`; icon `phone` 20 `color/text/secondary`; `admin/num` 18/600 `tabular-nums`, letter-spacing 0.04em; placeholder "01XXXXXXXXX"; auto-formats as `01712 046 318` while typing; accepts `+8801712046318` and `8801712046318` and normalises |
| Submit | H 48 | — | `Button primary lg` "Look up", `Enter` submits, autofocus on page load |
| Helper | Fill × Hug | — | `admin/caption` `color/text/tertiary`: "11 digits, operator prefix 013–019. The operator is never inferred from the prefix — numbers have been portable since 2018." |
| `invalid` | — | — | input border 1px `color/feedback/danger`; `alert-circle` 14 + `admin/caption` `color/text/primary` naming the actual fault: "011 is not a valid prefix — 011 was Citycell and is retired" / "10 digits — one short" / "06x numbers are IoT SIMs, not mobiles" |
| `searching` | — | — | submit `loading`; below, three skeleton cards at the final heights of the summary, risk and history blocks |
| `not-found` | W 1144 × Hug | Vertical, gap `spacing/3`, padding `spacing/6`, centred | `EmptyState`: 32px `user-search` `color/text/tertiary`; "No order history for 01712 046 318"; `admin/body` `color/text/secondary` "This phone has never placed an order here. That is neutral, not safe — a first-ever order carries a 10-point first-order signal."; two buttons: `Button outline md` "Create order for this number", `Button ghost md` "Add to watch list" |
| `found` summary | W 1144 × 112 | Horizontal, gap `spacing/6`, padding `spacing/5` | `color/bg/surface`, `radius/lg`, 1px `color/border/default` |
| — Identity | W 320 | Vertical, gap `spacing/1` | `Avatar` 44 (initials, `color/bg/brand-subtle`); name `admin/section`; phone `admin/num` with a 24×24 `IconButton ghost` `copy`; `admin/caption` `color/text/tertiary` "Customer since 14 Mar 2026 · source: web · prefers Bangla" |
| — Metric strip | Fill | Horizontal, gap `spacing/6` | 5 `StatCard size: sm`-style cells, no border: Orders · Delivered · Cancelled · Returned · Lifetime value. Each: `admin/overline` label + `admin/num-lg` value. |
| — Actions | Hug | Vertical, gap `spacing/2` | `Button primary md` "Call now" (`tel:` link), `Button outline md` "Open customer record" |
| `found` risk | — | — | `RiskScoreCard` `form: full` (A14) |
| `found` history | W 1144 × Hug | — | `DataTable` `compact`, columns: Order (124) · Placed (112) · Items (56) · Total (104) · Status triad (188) · Courier (144) · Outcome (248) · Net profit (168). Outcome is the plain-language ending ("Refused at door, returned 08 Nov"); Net profit is permission-gated and shows `−৳300` for a loss-making order. |
| Pinned notes | W 1144 × Hug | Vertical, gap `spacing/2`, padding `spacing/4` | `customer_notes` where `is_pinned`; bg `color/bg/brand-subtle`, `radius/md`; each: `pin` 14 + body `admin/body` + `admin/caption` `color/text/tertiary` attribution |

**Responsive** — **1024:** input Fill-capped 400; metric strip wraps to 3+2. **768:** input Fill × 52 with `type="tel"` and `inputmode="numeric"`; metric strip becomes a 2-column grid of 5 cells; history becomes a card list; the risk card goes `compact`.

---

### A16 · `CourierBookingPanel`

**Variant axes** — `mode`: `single` | `bulk` · `state`: `ready` | `blocked` | `booking` | `submitted` | `booked` | `failed` · `providerTrust`: `verified` | `unverified` | `not-connected`
**Properties** — `orders[]`, `providerId`, `weightGrams`, `codAmount`, `declaredValue`, `chargePreview{}`, `note`, `pickupSlot`

| Element | Geometry (480 right drawer at 1440) | Auto-layout | Tokens |
|---|---|---|---|
| Drawer root | W 480 × Fill | Vertical, gap 0 | `color/bg/surface`, `e4`, `radius/xl` left corners, slides from the right over a 50% `color/text/primary` scrim |
| Header | Fill × 64 | Horizontal, space-between, padding `spacing/4` `spacing/5` | bottom border 1px `color/border/default`; title `admin/section` "Book courier — TMB-26-00125" or "Bulk book — 2 orders"; `IconButton ghost` `x` |
| **Unverified-provider banner** | Fill × Hug | Horizontal, gap `spacing/3`, padding `spacing/4` `spacing/5` | bg `color/bg/subtle`, 3px `color/status/low-stock` left edge, 1px `color/border/default`; icon `flask-conical` 20 tinted `color/status/low-stock`; `admin/body-strong` `color/text/primary` "Steadfast driver has never been tested against a live account"; `admin/caption` `color/text/secondary` "Credentials are not configured, so no booking has ever succeeded through this driver. Field names, charge rounding and the response shape are implemented from the published docs and are unverified. Book one low-value order first and confirm the consignment ID appears."; `Button ghost sm` "Open courier settings". Shown whenever `courier_providers.credentials` is empty **or** the provider has zero successful bookings on record. |
| Provider select | Fill × Hug | Vertical, gap `spacing/2`, padding `spacing/5` | radio cards, not a dropdown — the charge differs per provider and must be comparable at a glance |
| Provider card | Fill × 76 | Horizontal, gap `spacing/3`, padding `spacing/4`, `radius/md` | 1px `color/border/default`; `selected` → 2px `color/bg/brand` + bg `color/bg/brand-subtle` |
| — Content | Fill | Vertical, gap `spacing/1` | name `admin/body-strong`; `admin/caption` `color/text/secondary` "Dhaka ৳60 · suburb ৳110 · outside ৳140 · +৳20/kg over 1 kg · COD 1% · return ৳70"; trust chip |
| — Trust chip | Hug × 20 | padding `spacing/1` `spacing/2`, `radius/sm` | `verified` → bg `color/bg/subtle`, `check` 12 tinted `color/feedback/success`, "Live · 47 bookings"; `unverified` → `flask-conical` 12 tinted `color/status/low-stock`, "Untested driver"; `not-connected` → `plug-zap-off` 12 tinted `color/text/tertiary`, "No credentials" and the card is `disabled` with `aria-disabled` and a tooltip |
| — Balance | Hug | right | `admin/num` `color/text/primary` "৳3,240" + `admin/caption` `color/text/tertiary` "checked 1:40 pm"; below ৳500 the figure gets a 14px `alert-triangle` tinted `color/feedback/warning` and the Book button is blocked with "Top up your Pathao balance first" |
| Parcel fields | Fill × Hug | Vertical, gap `spacing/4`, padding 0 `spacing/5` | `FormSection single` |
| — Weight | W 200 × 40 | — | `Input` `admin/num`, suffix "g"; **pre-filled from the summed `product_variants.weight_grams`** (920 g for TMB-26-00125) with `admin/caption` `color/text/tertiary` "From variant weights — 2 items. Override if you weighed the parcel." |
| — Item count | W 120 × 40 | — | read-only, from `order_items` |
| — Declared value | W 200 × 40 | — | `Input` `admin/num`, `৳` prefix, defaults to `subtotal` |
| — COD amount | W 200 × 40 | — | `Input` `admin/num`, `৳` prefix. **Defaults to `grand_total − paid_total`, not `grand_total`**: for TMB-26-00125 that is ৳2,810 − ৳500 = **৳2,310**. A 20-high strip beneath states "৳500 advance already received by bKash on 07 Nov" with a `link` 12 icon to the payment. Setting COD above the due amount raises a blocking error. |
| — Area mapping | Fill × Hug | Vertical, gap `spacing/2` | Pathao only (`requires_location_mapping`): three `Select`s City / Zone / Area pre-resolved from `courier_locations`. Unmapped → `Alert danger`: "Khan Jahan Ali Road, Khulna is not mapped to a Pathao zone. Booking is blocked. Map it in **Fulfilment → Courier location mapping**." plus `Button outline sm` "Map now". Steadfast hides this block entirely — it has no city/zone/area model and accepts only a free-text `recipient_address`. |
| — Pickup slot | Fill × 40 | — | `NativeSelect`: "Today 4:00–7:00 pm" / "Tomorrow 10:00 am–1:00 pm" |
| — Note to courier | Fill × 72 | — | `Textarea`, 120 chars, `admin/caption` helper "Printed on the label. The landmark is already included." |
| **Charge preview** | Fill × Hug | Vertical, gap `spacing/2`, padding `spacing/4` `spacing/5` | bg `color/bg/subtle`, `radius/md`, margin `spacing/5`; recalculates on every field change, debounced 300 ms, with a 16px `Spinner` replacing the total while in flight |
| — Line | Fill × 24 | Horizontal, space-between | label `admin/body` `color/text/secondary`; value `admin/num` `color/text/primary` |
| — Lines (TMB-26-00125) | — | — | Delivery charge (outside Dhaka, 920 g — no weight surcharge) **৳140** · COD fee (1% of ৳2,310) **৳23** · Return charge if refused **৳70** |
| — Total | Fill × 32 | Horizontal, space-between | top border 1px `color/border/strong`; "Cost to us if delivered" `admin/body-strong`; **৳163** `admin/num-lg` |
| — Risk line | Fill × Hug | Horizontal, gap `spacing/2` | 14px `shield-alert` tinted `color/status/low-stock` + `admin/caption` `color/text/primary` "If this parcel is refused it costs **৳210** (৳140 out + ৳70 back) and earns nothing." |
| — Provenance | Fill × Hug | — | `admin/caption` `color/text/tertiary` "Calculated from the rate card stored in `courier_providers`, last edited 02 Oct 2026. Not a provider quote." |
| Footer | Fill × 72, sticky | Horizontal, space-between, padding `spacing/4` `spacing/5` | top border 1px `color/border/default`, `color/bg/surface`; `Button ghost md` Cancel; `Button primary lg` "Book with Pathao · ৳163" |
| `blocked` | — | — | primary `disabled` with the blocking reason as `admin/caption` `color/feedback/danger`-tinted icon + `color/text/primary` text directly above it; the reason is always actionable and names the screen that fixes it |
| `booking` | — | — | primary `loading` "Booking…"; all fields `aria-busy`; a 20-high `aria-live` line "Sending to Pathao — do not close this panel" |

#### The state that matters: `submitted` vs `booked`

Verified: **Pathao's bulk endpoint responds `202` with no consignment IDs, and Steadfast's bulk endpoint accepts up to 500 orders and returns none either.** A bulk-booked shipment has `status = submitted` and `consignment_id = NULL` until a webhook or the reconciliation poll supplies it. A staff member who believes a parcel is booked when it is not will not chase it, so these two states look nothing alike.

| | `submitted` — awaiting confirmation | `booked` — confirmed |
|---|---|---|
| Icon | `clock-arrow-up` 20, tinted `color/status/low-stock` | `circle-check` 20, tinted `color/feedback/success` |
| Chip label | **"Submitted — awaiting confirmation"** | **"Booked"** |
| Chip tokens | bg `color/bg/subtle`, **2px `color/status/low-stock` left border**, 1px dashed `color/border/strong` on the rest | bg `color/bg/subtle`, 1px solid `color/border/default` |
| Consignment ID | `admin/mono` `color/text/tertiary` **"Not issued yet"** — never a dash, never blank | `admin/mono` `color/text/primary` `DT261107FJ2D9Q` with a `copy` button |
| Elapsed timer | `admin/num` `color/text/primary` "5h 25m since 9:05 am" — crosses to a 14px `alert-triangle` tinted `color/feedback/warning` past 6 h with "Overdue — chase Pathao support" | — |
| Actions | `Button outline sm` "Poll for consignment ID" · `Button ghost sm` "View submitted payload" · `Button ghost sm` "Mark as failed and re-book" | `Button outline sm` "Print label" · `Button outline sm` "Track" · `Button ghost sm` "Cancel booking" |
| Label printing | **disabled**, tooltip "A label cannot be printed before the consignment ID arrives" | enabled |
| Order status | stays `ready_to_ship` | moves to `shipped` when the first pickup event arrives, not at booking |

A `submitted` shipment surfaces in three places so it cannot be forgotten: the "Submitted — awaiting confirmation" tab of the booking queue with a live count, a topbar notification at the 6-hour threshold, and a dashboard `StatCard` footnote.

#### `bulk` mode

Header becomes "Bulk book — 2 orders · ৳4,450 COD". A W 480 list of per-order mini-rows (H 64: order number + customer + zone + weight + computed charge), each with a `Checkbox` and an inline-editable weight. A totals strip: "2 parcels · 4,320 g · COD ৳4,450 · estimated cost **৳180**". Then a **required** acknowledgement, bg `color/bg/subtle`, 3px `color/status/low-stock` left edge: a `Checkbox` labelled "I understand bulk booking returns no consignment IDs. These 2 orders will show **Submitted — awaiting confirmation** until Pathao confirms them." The primary button reads "Submit 2 orders to Pathao" — **the word "book" is not used in bulk mode at all.** On `202`, the drawer swaps to a result panel: a `check` icon, "2 orders submitted at 9:05 am", a batch reference `BULK-2026-1108-01`, per-order `submitted` rows, and `Button primary md` "Go to Submitted queue".

`failed` — per-order rows with the literal provider error and a `Button outline sm` "Retry". Failures are never silently dropped; they land in **Fulfilment → Failed bookings retry** with their payload.

**Responsive** — **1440 / 1024:** right drawer W 480. **768:** full-screen sheet, `radius/xl` top corners, sticky header and footer, fields Fill width, the charge preview pinned directly above the footer so the number is always visible while editing weight.

---

### A17 · `ShipmentTrackingTimeline`

**Variant axes** — `source`: `webhook` | `poll` | `manual` (per event) · `state`: `awaiting-first-event` | `active` | `delivered` | `partially-delivered` | `returned` | `failed`
**Properties** — `shipment{}`, `events[]`, `providerName`, `lastSyncAt`, `nextPollAt`

| Element | Geometry (744 main column) | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap `spacing/4`, padding `spacing/5` | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Header | Fill × Hug | Horizontal, space-between | title `admin/section` "Shipment SHP-26-00095"; right: `admin/caption` `color/text/tertiary` "Synced 2:12 pm · next poll 2:42 pm" + `IconButton ghost` `refresh-cw` |
| Shipment facts | Fill × Hug | Wrap, gap `spacing/4` `spacing/6`, padding `spacing/4` | bg `color/bg/subtle`, `radius/md`; 6 cells, each Vertical gap `spacing/1`: `admin/overline` label + value |
| — Cells | — | — | Provider (Pathao) · Consignment (`admin/mono` + copy) · Weight (1,600 g) · COD (৳1,330 of ৳3,080) · Charge (৳130 + ৳13 fee + ৳70 return) · Attempts (1) |
| Tracking link | Hug × 32 | — | `Button outline sm` with `external-link` 14, opens `tracking_url` in a new tab; absent when `tracking_code` is null, replaced by `admin/caption` `color/text/tertiary` "No tracking code yet" |
| Timeline rail | Fill × Hug | Vertical, gap 0 | — |
| Event row | Fill × Hug | Horizontal, gap `spacing/4`, padding `spacing/3` 0 | — |
| — Dot column | W 24 | centred | 12×12 dot `radius/full`; connector 2px `color/border/default` running the full row height behind it |
| — Dot (normalised status) | — | — | `picked_up` `truck` tint `color/feedback/info` · `in_transit` `move-right` `color/feedback/info` · `out_for_delivery` `bike` `color/bg/brand` · `delivered` `circle-check` `color/feedback/success` · `partially_delivered` `circle-dot` `color/status/low-stock` · `failed_attempt` `alert-triangle` `color/status/low-stock` · `returned` `undo-2` `color/feedback/danger` · `cancelled` `circle-slash` `color/text/tertiary` |
| — Time column | W 104 | Vertical | `admin/num` 13 `color/text/primary` "07 Nov"; `admin/caption` `color/text/tertiary` "11:48 am" |
| — Body | Fill | Vertical, gap `spacing/1` | normalised status `admin/body-strong` `color/text/primary`; provider's own words `admin/body` `color/text/secondary` in quotes; location + `admin/caption` `color/text/tertiary` |
| — Source chip | Hug × 18 | padding `spacing/1`, `radius/sm` | bg `color/bg/subtle`, `admin/caption` `color/text/tertiary`: "webhook" / "poll" / "manual — Rina". **Every event states how we learned it**, because a manually-entered delivery and a webhook-confirmed delivery are different kinds of fact. |
| — Raw payload | Hug | — | `Button ghost xs` "Raw" → `Popover` `color/bg/surface-raised` W 420, `admin/mono` 12, max-H 240 scroll |
| `awaiting-first-event` | Fill × 140 | Vertical, centred | `EmptyState`: `clock-arrow-up` 24 tinted `color/status/low-stock`, "Submitted at 9:05 am — no tracking events yet", `admin/caption` "Pathao has not confirmed this consignment. Events appear here once it does." |
| Divergence banner | Fill × Hug | Horizontal, gap `spacing/3`, padding `spacing/4` | shown when `provider_status_raw` has no mapping: bg `color/bg/subtle`, 3px `color/status/low-stock` left edge, "Pathao sent `hold_by_merchant`, which we do not recognise. The shipment status is unchanged. Logged for mapping." |
| Partial-delivery block | Fill × Hug | Vertical, gap `spacing/2`, padding `spacing/4` | `partially_delivered` only; bg `color/bg/subtle`, `radius/md`; two labelled lists: **Delivered (1)** — Plush Teddy Bear 45cm × 1, ৳1,200; **Came back (1)** — Foam Blaster X2 × 1, ৳1,750, restocked via `courier_return_in` on 08 Nov at a written-down cost of ৳790 |

**Responsive** — **1024:** facts grid 3-up. **768:** facts grid 2-up; the time column moves above the body as a single `admin/caption` line; the Raw button becomes a long-press.

---

### A18 · `StockMovementLedger`

Append-only, read-only. The only write path into stock is a movement, which is why this screen is the authority and the variant Stock field in A9 is not editable.

**Variant axes** — `scope`: `all` | `single-variant` · `state`: `default` | `filtered` | `empty` · `showCost`: permission-gated
**Properties** — `rows[]`, `filters{}`, `runningBalanceColumn` (bool)

Built as a `DataTable` `compact` at W 1144. Columns:

| Column | W | Align | Content |
|---|---|---|---|
| When | 136 | left | `admin/num` "08 Nov" / `admin/caption` "12:20 pm" |
| Product / variant | 232 | left | `admin/body-strong` name, `admin/mono` 12 `color/text/tertiary` SKU |
| Type | 168 | left | movement chip (below) |
| Qty | 72 | right | `admin/num`, signed, **in colour + sign + weight**: positive `color/text/primary` 600 with `+`, negative `color/text/primary` 600 with `−`; the arrow icon in the Type chip is what encodes direction |
| Unit cost | 104 | right | `admin/num`, 4 dp in the tooltip, 2 dp displayed; hidden without `inventory.view_cost` |
| Value | 112 | right | `admin/num` = qty × unit cost, signed |
| Balance after | 104 | right | `admin/num` `color/text/primary` 600 — read straight from `stock_movements.balance_after`, never recomputed in the client |
| Avg cost after | 112 | right | `admin/num` `color/text/secondary`, 4 dp |
| Reference | 136 | left | `admin/mono` 12 link: order number, `PO-2026-0041`, `ADJ-2026-0012`; `reference_type` as a 16px icon prefix (`shopping-cart`, `truck`, `sliders`, `undo-2`) |
| By | 112 | left | `Avatar` 20 + first name, or "System" / "Pathao webhook" |
| Note | Fill (56) | left | truncated to 1 line, full text in a tooltip |

Sum: 136+232+168+72+104+112+104+112+136+112+56 = **1144**.

| Movement type chip | Icon | Icon tint | Label |
|---|---|---|---|
| `purchase_in` | `arrow-down-to-line` | `color/feedback/success` | Purchase in |
| `sale_out` | `arrow-up-from-line` | `color/feedback/info` | Sale out |
| `sale_cancelled_in` | `rotate-ccw` | `color/feedback/success` | Sale cancelled |
| `customer_return_in` | `package-open` | `color/status/low-stock` | Customer return |
| `courier_return_in` | `undo-2` | `color/feedback/danger` | Courier return |
| `adjustment_in` / `adjustment_out` | `sliders-horizontal` | `color/text/secondary` | Adjustment in / out |
| `damage_out` | `trash-2` | `color/feedback/danger` | Damage out |
| `transfer_in` / `transfer_out` | `arrow-left-right` | `color/text/secondary` | Transfer in / out |
| `opening_balance` | `flag` | `color/text/tertiary` | Opening balance |

All chips use the same `color/bg/subtle` fill and `color/text/primary` label; the icon shape and the written label carry the meaning.

| Element | Geometry | Tokens |
|---|---|---|
| Filter bar | W 1144 × 64, Horizontal, gap `spacing/3`, padding `spacing/3` `spacing/4` | `DateRangePicker sm` · product/variant `Select` (searchable, W 240) · type multi-`Select` (W 200) · reference-type `NativeSelect` (W 160) · user `Select` (W 160) · `Button ghost sm` "Clear" |
| Reconciliation strip | W 1144 × 56, Horizontal, space-between, padding `spacing/4` | bg `color/bg/subtle`, `radius/md`; left `admin/body` "Opening 210 units · in +26 · out −20 · closing **216 units**"; right `admin/num` "Stock value at cost ৳136,741" + a 14px `check-circle` tinted `color/feedback/success` with `admin/caption` "Ledger balance matches `stock_levels` for all 10 variants" |
| Mismatch state | — | — | the check becomes `alert-triangle` tinted `color/feedback/danger` and the strip expands to list the offending variants with both figures and a `Button outline sm` "Open reconciliation report". A silent mismatch here is how inventory quietly becomes fiction. |
| `single-variant` header | W 1144 × 96 | product card: 64×64 image, name, SKU, then 4 cells — On hand / Reserved / Available / Reorder point — and a `Button outline md` "New adjustment" |

**Responsive** — **1024:** horizontal scroll, When + Product frozen. **768:** card list — line 1 type chip + signed qty + balance after; line 2 product name + SKU; line 3 `admin/caption` timestamp + reference + actor. The reconciliation strip becomes a 2-row block pinned above the list.

---

### A19 · `ProfitLossStatement`

**The brief.** Net profit is **not** price minus cost. It nets courier charge, COD fee, return charge, gateway fee, packaging, discounts, refunds, return write-downs, inventory write-offs and allocated marketing (DATABASE-DESIGN §5.4). The owner is not an accountant. So the statement is built as **five labelled blocks, each with a plain-English question as its heading**, each ending in a running subtotal, with a colour-free vertical rail that gets progressively narrower as money is removed — the shape of the page is the story.

**Variant axes** — `period`: `day` | `week` | `month` | `custom` · `comparison`: `none` | `previous-period` | `previous-year` · `detail`: `summary` | `expanded` · `state`: `default` | `rolling-up` | `no-data`
**Properties** — `from`, `to`, `blocks[]`, `cashPanel{}`, `reconciliation{}`, `notInStatement[]`

#### Layout at 1440 — statement 736 + gutter 24 + rail 384 = 1144

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Statement card | W 736 × Hug | Vertical, gap 0, padding 0 | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Statement header | Fill × 88 | Vertical, gap `spacing/1`, padding `spacing/5` | title `admin/page-title` "Profit & loss"; `admin/caption` `color/text/secondary` "02 Nov – 08 Nov 2026 · Asia/Dhaka · accrual basis — revenue is recognised on delivery, not on order" |
| **Block** | Fill × Hug | Vertical, gap 0 | separated by a 1px `color/border/default` divider; **no block has a coloured background** — five tinted blocks would be a traffic light, not a statement |
| — Block rail | W 4 × Fill | absolute left | `color/border/strong` for the two inflow blocks, `color/text/tertiary` for the three outflow blocks; width steps 4 → 4 → 3 → 3 → 2 px down the page |
| — Block heading | Fill × Hug | Vertical, gap `spacing/1`, padding `spacing/4` `spacing/5` `spacing/2` `spacing/5` | `admin/overline` `color/text/tertiary` block number ("BLOCK 2 OF 5"); `admin/section` `color/text/primary` the plain question; `admin/caption` `color/text/secondary` one sentence of explanation |
| — Line row | Fill × 36 | Horizontal, space-between, padding 0 `spacing/5` | label `admin/body` `color/text/primary`; sublabel `admin/caption` `color/text/tertiary` inline after the label; value `admin/num` right, W 128 |
| — Line row `drill` | — | — | `hover` bg `color/bg/subtle`, label gains an `arrow-up-right` 12 in `color/text/tertiary`; the whole row is a link to the filtered list behind the number |
| — Deduction row | — | — | value rendered `−৳8,210` with a true minus; label prefixed by a 12px `minus` glyph in `color/text/tertiary` |
| — Subtotal row | Fill × 48 | Horizontal, space-between, padding `spacing/2` `spacing/5` | top border 1px `color/border/strong`; label `admin/body-strong`; value `admin/num-lg`; bg `color/bg/subtle` |
| Net profit row | Fill × 96 | Horizontal, space-between, padding `spacing/5` | bg `color/bg/brand-subtle`, bottom `radius/lg`; label block: `admin/section` "Net profit" + `admin/caption` `color/text/secondary` "What the week actually earned, after every cost above"; value `admin/num-xl` `color/text/primary`; beside it `admin/caption` `color/text/secondary` "5.2% of net revenue" |
| Net profit negative | — | — | value prefixed `−`, a 20px `trending-down` tinted `color/feedback/danger` beside the label, and a one-line diagnosis: "The week lost money. The largest single cost was marketing at ৳1,400." Never red text alone. |

**The five blocks, with their headings — this wording ships:**

| # | Heading | Sub-line | Contains |
|---|---|---|---|
| 1 | **What customers owed us** | "Only delivered orders count. An order that is still in a courier's van is not revenue yet." | Product revenue · Delivery charges collected · = Gross revenue · − Discounts and coupons · **= Net revenue** |
| 2 | **What the toys cost us** | "The purchase cost of exactly the items that were delivered, frozen at the moment each order was placed." | − Cost of goods sold · **= Gross profit** (with the margin % beside it) |
| 3 | **What it cost to deliver and get paid** | "In cash-on-delivery these are not small. They are the difference between a healthy-looking margin and the real one." | − Courier delivery charges · − COD collection fees · − Courier return charges · − Payment gateway fees · − Packaging · **= Profit after delivery** |
| 4 | **What went wrong** | "Money lost to things that did not work out. Watch this block — it is the one you can shrink." | − Refunds to customers · − Written-down returns · − Damaged stock written off · **= Profit after losses** |
| 5 | **What it cost to run the shop** | "Advertising and running costs for the period. Advertising is spread evenly across delivered orders, so it is an estimate; everything above it is a recorded fact." | − Advertising · − Other running costs · **= Net profit** |

#### Right rail (384 wide, three stacked cards, gap `spacing/6`)

| Card | Geometry | Content |
|---|---|---|
| **"Where the money actually is"** | W 384 × Hug, Vertical gap `spacing/3`, padding `spacing/5`, `radius/lg`, 1px `color/border/default`, `e1` | `admin/overline` "CASH, NOT PROFIT"; `admin/caption` `color/text/secondary` "Profit and cash are different numbers and both are correct. This card is cash."; then four rows (label `admin/body`, value `admin/num`): Received in your bank · Still with the courier · Owed back to a customer · **Net cash movement** (subtotal styling). A 2px `color/border/default` divider, then `admin/caption` `color/text/tertiary`: "Courier charges never leave your bank as a payment — they are deducted on the settlement statement, so they appear as expenses above and inside the net settlement here. They are counted once." |
| **Waterfall** | W 384 × 280 | `ChartCard type: bar`, 5 bars: Net revenue, Gross profit, After delivery, After losses, Net profit. Each bar labelled with its value in `admin/num` above it; the drop between bars drawn as a 1px dashed `color/border/strong` connector with the deducted amount in `admin/caption` `color/text/secondary`. This is the single most useful graphic on the screen — it shows the collapse. |
| **Reconciliation** | W 384 × Hug | `admin/overline` "DOES IT TIE UP?"; three rows: "Sum of every order's net profit" · "− period costs not charged to any order" · "= Net profit above"; then a 16px `check-circle` tinted `color/feedback/success` + `admin/body-strong` "Reconciled". On a mismatch: `alert-triangle` tinted `color/feedback/danger`, the variance in `admin/num`, and `Button outline sm` "Open variance report". The invariant test behind this compares `order_financials` against `financial_transactions`. |

#### "Not in this statement" footer

Fill × Hug, padding `spacing/5`, bg `color/bg/subtle`, `radius/lg`, Vertical gap `spacing/2`. `admin/overline` "NOT IN THIS STATEMENT"; then rows naming, with amounts, what a reader might wrongly assume is included: open pipeline value (orders placed but not delivered), monthly bills that fall outside the window, and inventory on hand. Without this block an owner reads a ৳717 profit and asks where the other ৳18,000 of orders went.

**Permissions.** The entire screen is gated on `finance.view_pnl`. The cost-of-goods block is additionally gated on `inventory.view_cost`; without it, Block 2 renders its subtotal only and a `lock` 14 + "Item costs are restricted to accountants and the owner".

**Export.** `Button outline md` "Export" → CSV (one row per statement line, with the raw `financial_transactions.type`) and PDF (the same layout, A4 portrait, company block snapshotted per §3.5).

**Responsive** — **1024:** statement Fill 920; the rail moves **below** the statement as three cards in a 3-up row of 293 each. **768:** statement Fill 736, line rows grow to 44 for touch, the value column drops to W 104; block headings keep their sub-lines (they are the explanation and must not be the thing that is cut); the waterfall rotates to horizontal bars; the rail cards stack.

---

### A20 · `BulkActionBar`

**Variant axes** — `state`: `hidden` | `visible` | `confirming` | `running` | `result` · `position`: `floating` | `docked-toolbar`
**Properties** — `selectedCount`, `totalMatching`, `actions[]`, `progressDone`, `progressTotal`, `resultSummary{}`

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root `floating` | W Hug (min 560, max 960) × 64 | Horizontal, space-between, gap `spacing/4`, padding `spacing/3` `spacing/4` | `color/bg/surface-raised`, `radius/lg`, 1px `color/border/strong`, `e4`; centred horizontally over the content area, 24px above the viewport bottom; slides up `motion/base` `decelerate` |
| Count block | Hug | Vertical, gap `spacing/1` | `admin/body-strong` `color/text/primary` "4 orders selected"; `Button ghost xs` "Select all 12 matching" / "Clear selection" |
| Context block | Hug | Horizontal, gap `spacing/4` | derived facts the operator needs before acting: `admin/caption` `color/text/secondary` "Total ৳12,580 · 2 outside Dhaka · **1 high risk**", the last item carrying a 14px `shield-alert` tinted `color/feedback/danger` |
| Action group | Hug | Horizontal, gap `spacing/2` | up to 4 `Button outline md` + an `IconButton outline` `ellipsis` overflow `Popover` for the rest; destructive actions live **only** in the overflow and render as `Button danger` inside it |
| Divider | 1 × 32 | — | `color/border/default`, before the close button |
| Close | 32×32 | — | `IconButton ghost` `x`, `Esc` equivalent |
| `confirming` | — | — | the bar expands to H 96; row 2 holds the confirmation sentence and `Button ghost md` Cancel + `Button primary md` "Yes, confirm 4 orders". Anything irreversible escalates to `ConfirmDialog` (A23) instead. |
| `running` | — | — | `ProgressBar` 4px across the bar's top edge in `color/bg/brand`; label "Booking 3 of 4…"; the bar cannot be dismissed; `aria-live` announces each completion |
| `result` | — | — | H 96; `check-circle` 16 tinted `color/feedback/success` + "3 booked" and, on the same line, `alert-circle` 16 tinted `color/feedback/danger` + "1 failed" with `Button ghost sm` "Show the 1 failure". **Partial success is always reported as two numbers**, never as "done". |
| `docked-toolbar` | Fill × 52 | Horizontal | an alternative that replaces the `DataTable` toolbar row in place; used where a floating bar would cover a sticky footer (the booking queue) |

**Responsive** — **1440 / 1024:** `floating`, centred on the content area (not the viewport, so it is not hidden by the sidebar). **768:** docked to the viewport bottom, Fill width, `radius/xl` top corners, H 72 in two rows: row 1 the count + clear, row 2 a horizontally scrollable action strip with the primary action pinned right and always visible.

---

### A21 · `PermissionMatrix`

**Variant axes** — `state`: `default` | `dirty` | `saving` · `cell`: `granted` | `denied` | `inherited` | `locked` | `mixed`
**Properties** — `roles[]`, `permissionGroups[]`, `matrix{}`, `editableRoles[]`

| Element | Geometry (1144) | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap 0 | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1`, clip |
| Header row | Fill × 72, sticky top | Horizontal | bg `color/bg/subtle`, bottom border 1px `color/border/strong` |
| — Permission column header | W 368, sticky left | padding `spacing/4` | `admin/overline` "PERMISSION"; right border 1px `color/border/strong` |
| — Role column | W 108 each × 7 | Vertical, centred, gap `spacing/1` | role name `admin/caption` 600 `color/text/primary`, wrapped to 2 lines; user count `admin/caption` `color/text/tertiary` "2 users"; `super_admin` column gets a 14px `lock` and `color/bg/subtle` fill |
| Group header row | Fill × 44 | Horizontal | bg `color/bg/subtle`; `admin/body-strong` + `admin/caption` `color/text/tertiary` count "(6)"; `chevron-down` collapses the group; per-role "grant all in group" tri-state `Checkbox` in each column |
| Permission row | Fill × 44 | Horizontal | label W 368 sticky left: `admin/body` `color/text/primary` + `admin/caption` `color/text/tertiary` with the raw permission key in `admin/mono` |
| Cell `granted` | 108 × 44, centred | — | `Checkbox` checked; bg `color/bg/brand-subtle` |
| Cell `denied` | — | — | `Checkbox` unchecked; bg transparent |
| Cell `inherited` | — | — | `Checkbox` checked and `disabled` with a 12px `arrow-down-right` beside it; tooltip "Granted by *manager* role inheritance" |
| Cell `locked` | — | — | 16px `lock` in `color/text/tertiary`, `aria-disabled`; `super_admin` holds everything and cannot be edited |
| Cell `mixed` | — | — | `Checkbox` indeterminate (group headers only) |
| Sensitive-permission row | — | — | the 7 permissions covered by `audit_sensitive` (price, cost, refund, role, credential, blacklist, P&L) get a 3px `color/status/low-stock` left edge on the label cell and a 14px `history` icon with the tooltip "Every change to this permission is written to the immutable audit log" |
| Danger pairing warning | Fill × Hug | Horizontal, gap `spacing/3`, padding `spacing/4` | appears above the matrix when a combination is granted that should be questioned: bg `color/bg/subtle`, 3px `color/feedback/warning` left edge, `alert-triangle` 16, "**order_operator** would be able to issue refunds and approve them. Separate those two." |
| Search | W 320 × 36 | — | above the matrix, filters permission rows live, keeping group headers that still have matches |

Column widths: 368 + 7 × 108 = 1124, plus a 20px right scroll affordance = 1144.

**Responsive** — **1440:** all 7 roles visible. **1024:** horizontal scroll with the permission column frozen; a `NativeSelect` "Jump to role" scrolls that column into view. **768:** matrix inverted — a role `NativeSelect` at the top, then a single-column list of permission rows with one `Switch` each, grouped by `Accordion`. Comparing roles on a phone is not a real task; editing one is.

---

### A22 · `ActivityFeed`

**Variant axes** — `scope`: `global` | `entity` · `density`: `comfortable` | `compact` · `state`: `default` | `loading` | `empty` · `filter`: `all` | `mine` | `sensitive`
**Properties** — `events[]`, `cursor`, `showCustomerVisibleFlag` (bool)

| Element | Geometry (376 rail / 744 main) | Auto-layout | Tokens |
|---|---|---|---|
| Root | Fill × Hug | Vertical, gap 0, padding 0 | `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1` |
| Header | Fill × 52 | Horizontal, space-between, padding `spacing/3` `spacing/4` | `admin/section` "Activity"; `NativeSelect sm` W 120 (All / Mine / Sensitive only) |
| Day separator | Fill × 32, sticky | Horizontal, padding 0 `spacing/4` | bg `color/bg/subtle`, `admin/overline` `color/text/tertiary` "TODAY · 08 NOV" |
| Event row | Fill × Hug | Horizontal, gap `spacing/3`, padding `spacing/3` `spacing/4` | bottom border 1px `color/border/default` |
| — Actor | 28×28 | — | `Avatar` with initials, bg `color/bg/brand-subtle`, text `color/text/primary`; system events use a 28×28 `radius/md` `color/bg/subtle` tile with a 16px `cog` / `webhook` / `bot` icon |
| — Body | Fill | Vertical, gap `spacing/1` | sentence `admin/body` `color/text/primary` with the actor name in 600 and the entity as an inline link; `admin/caption` `color/text/tertiary` time + source |
| — Change detail | Fill × Hug | Vertical, gap `spacing/1` | for `audit_sensitive` events, a `color/bg/subtle` `radius/sm` block, padding `spacing/2`, `admin/mono` 12: `cost_price: 1,050.0000 → 1,060.0000`. Before/after is shown, not described. |
| — Sensitive marker | Hug × 18 | — | `shield` 12 tinted `color/status/low-stock` + `admin/caption` `color/text/primary` "Audited" |
| — Customer-visible marker | Hug × 18 | — | `eye` 12 tinted `color/feedback/info` + "Customer can see this" — `order_events.is_customer_visible`, so an operator knows whether a note is private |
| Load more | Fill × 48 | centred | `Button ghost md` "Older activity", cursor-paginated |
| `empty` | Fill × 160 | centred | `EmptyState`: `history` 24, "No activity in this period" |

Internal notes are composed in the same card via a 2-row block: `Textarea` H 72 + a row holding a `Switch` "Visible to the customer" (default off, and when switched on the note's border turns to `color/feedback/info` so the choice is unmissable) and `Button primary sm` "Add note".

**Responsive** — **768:** Fill width; the change-detail block wraps before/after onto two lines with "was" / "now" labels.

---

### A23 · `ConfirmDialog`

**Variant axes** — `severity`: `info` | `warning` | `destructive` | `destructive-typed` · `state`: `default` | `locked` | `submitting` | `error`
**Properties** — `title`, `body`, `consequences[]`, `confirmPhrase`, `typedValue`, `primaryLabel`, `cancelLabel`

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Scrim | Fill × Fill | — | `color/text/primary` at 50%; click-outside closes `info`/`warning` only, **never** a `destructive` variant |
| Root | W 520 × Hug | Vertical, gap 0 | `color/bg/surface`, `radius/xl`, `e4`; max-H 80vh with the body scrolling |
| Icon | 44×44, `radius/md` | centred | `info` → bg `color/bg/subtle`, icon `info` tinted `color/feedback/info`; `warning` → `alert-triangle` tinted `color/status/low-stock`; `destructive` → `alert-octagon` tinted `color/feedback/danger` |
| Header | Fill × Hug | Vertical, gap `spacing/2`, padding `spacing/6` `spacing/6` `spacing/4` | title `admin/section` `color/text/primary`; body `admin/body` `color/text/secondary` |
| Consequence list | Fill × Hug | Vertical, gap `spacing/2`, padding `spacing/4` | bg `color/bg/subtle`, `radius/md`, margin 0 `spacing/6`; each line = 14px `dot` + `admin/body` `color/text/primary`. **Consequences are enumerated, not summarised.** |
| **Typed confirmation** | Fill × Hug | Vertical, gap `spacing/2`, padding `spacing/4` `spacing/6` | `destructive-typed` only |
| — Instruction | — | — | `admin/body` `color/text/primary`: "Type **TMB-26-00126** to cancel this order." The phrase is selectable but **not** copyable-by-button — the point of the gesture is that the operator looks at the order number. |
| — Input | Fill × 44 | — | `Input`, `admin/mono`, `autocomplete="off"`, `spellcheck="false"`; mismatch → border stays `color/border/default` and the primary stays disabled with `admin/caption` `color/text/tertiary` "Does not match yet"; match → border 1px `color/feedback/success` and a 16px `check` |
| Footer | Fill × 76 | Horizontal, gap `spacing/3`, justify end, padding `spacing/5` `spacing/6` | top border 1px `color/border/default` |
| Primary `destructive` | H 44 | — | `Button danger md`; **disabled until the phrase matches exactly** (case-sensitive, trimmed) |
| Cancel | H 44 | — | `Button ghost md`, autofocused on open so `Enter` is always the safe answer |

**The typed phrase per action** — order cancellation: the order number. Product deletion: the exact product name. Variant disabling with stock on hand: the SKU. Blacklisting a phone: the 11-digit phone number. Refund approval over ৳2,000: the amount in digits. Role deletion: the role name. Each dialog also states what cannot be undone and what can: "Cancelling releases the 2 reserved units back to stock and reverses coupon `WELCOME100`. It cannot be undone — a cancelled order must be re-created."

Focus is trapped, `Esc` cancels non-destructive variants only, and focus returns to the trigger on close.

**Responsive** — **768:** W Fill − 32, bottom-sheet presentation, `radius/xl` top corners, footer buttons stacked Fill-width with the destructive primary **last** so a thumb reaching the bottom of the screen does not land on it.

---

### A24 · `EmptyState`

**Variant axes** — `reason`: `no-data-yet` | `no-results` | `all-clear` | `no-permission` | `error` | `not-connected` · `size`: `lg` (full page) | `md` (card) | `sm` (table body)
**Properties** — `headline`, `body`, `primaryAction`, `secondaryAction`, `illustrationKey`

| Element | Geometry | Auto-layout | Tokens |
|---|---|---|---|
| Root `lg` | Fill × 420 | Vertical, gap `spacing/4`, centred, padding `spacing/12` | transparent |
| Root `md` | Fill × 320 | gap `spacing/3`, padding `spacing/8` | — |
| Root `sm` | Fill × 200 | gap `spacing/2`, padding `spacing/6` | — |
| Illustration | 160×120 `lg` / 96×72 `md` / none `sm` | — | flat two-tone per §3.4, `color/bg/brand-subtle` + `color/bg/brand`; **only** for `no-data-yet` and `all-clear`. `no-results`, `no-permission`, `error` and `not-connected` use a 32px Lucide icon in `color/text/tertiary` — an illustration next to a failure reads as flippant. |
| Headline | max-W 420 | centred | `admin/section` `color/text/primary` |
| Body | max-W 420 | centred | `admin/body` `color/text/secondary` |
| Actions | Hug | Horizontal, gap `spacing/3` | `Button primary md` + `Button ghost md` |

Copy rules, which are the whole component: **name the reason, then the next action.** `no-results` echoes the active filters and offers to remove the narrowest one ("No orders match *Pending* + *outside Dhaka* + *last 7 days*. Remove the date filter?"). `all-clear` is positive and specific ("No orders are waiting to be booked. The last one went out at 9:05 am."). `no-permission` names the permission and who can grant it. `error` carries the request id and a retry. `not-connected` names the integration and links to its settings screen — it is the variant Panel B of A14 uses, and it must never read as a clean result.

---

### A25 · Media library parts (`MediaTile`, `MediaFilterItem`, `MediaDetailPanel`, `UploadQueueItem`, `UploadQueue`, `MediaDeleteDialog`, `MediaPickerModal`)

Added 2026-11-12 (Figma page `04 Admin Components`, section `09 Media library` `288:1904`). One library holds product photos, category banners, blog and page images, and BSTI certificate PDFs. **Video is deferred past v1** (owner decision, 2026-11-12): no Videos filter, no video tile, no video upload. Three rules carry the whole feature and are enforced by the API, not the UI:

1. **Alt text in English and বাংলা is required on every image.** A product (or page/post) that uses an image missing either alt cannot be published — the same 422 as A8. The library surfaces the gap (`Missing Bangla alt` / `Missing English alt` filters with Danger counts, a Warning chip on the tile) but never blocks the upload itself.
2. **An asset that is used anywhere cannot be deleted.** Delete is disabled with the reason and the usages are listed; the server refuses with 409. Bulk delete skips in-use files and says how many it skipped.
3. **Replace file keeps the URL.** The new file is processed into the same renditions under the same path, so every product, page and post that uses it updates at once. Replace is the answer to "this photo is wrong but used in 3 places".

| Component | Variants / properties | Geometry (1440) | Tokens and notes |
|---|---|---|---|
| `MediaFilterItem` `289:1910` | `State` Default · Active · Warning · props `label`, `icon` (swap) + nested `CountBadge` | 224 × 36, padding `space/2` `space/3`, gap `space/2`, `radius/sm` | Active = `bg/brand-subtle` + `text/brand` icon (same as the sidebar). Warning = `feedback/warning` icon + Danger count — used for the alt-text gaps. |
| `MediaTile` `289:2121` | `State` Default · Selected · Ordered × `Type` Image · Document (Video variants removed 2026-11-12 — video deferred past v1) · props `filename`, `meta` | 160 wide (resizable; thumb is aspect-locked 1:1), padding `space/2`, `radius/sm-plus`, 1px `border/default` | Selected = 2px `border/brand` + `bg/brand-subtle` + checked checkbox. Ordered = numbered `bg/brand` badge (picker multi-select; 1 = primary image). Top-right `Used in N` / `Unused` badge on `bg/surface`. Alt chip = `StatusChip`: Success "Alt EN · বাং", Warning "বাং alt missing" / "Not 1:1 — will crop", Danger "Alt missing", Neutral "No alt needed" (PDF). |
| `MediaDetailPanel` `291:2353` | `State` InUse · Unused | 360 (344 in the 1440 grid layout), `radius/md`, `Elevation/*/e1` | Preview 1:1 · filename + CDN URL (`Admin/mono-sm`) · Copy URL / Replace file / Download · type, dimensions, size (served vs original), uploader + Dhaka time, folder · renditions box (AVIF + WebP 1600/800/400 with sizes; "EXIF and GPS data removed · checked by content") · alt EN + বাংলা (`AdminField`, both required; missing → Error state with the publish consequence) · caption · folder `AdminSelect` · Used-in list (icon, title, context, `arrow-square-out` link) · Delete: InUse = Danger disabled + lock + reason; Unused = Danger enabled. |
| `UploadQueueItem` `292:2169` | `State` Uploading · Processing · Done · Failed · prop `filename` | 400 × ~64, padding `space/3` `space/4` | 4px progress on `bg/subtle` with `bg/brand` fill. Failed thumb `bg/danger-subtle` + `warning-circle`; the reason is `text/primary` caption (feedback colours never carry 12–14px text) and states the real cause: "Not a real image file — the content is not JPG, PNG, WebP or AVIF", "11.4 MB — over the 10 MB limit", "900 × 900 — below the 1200 × 1200 minimum for product images". |
| `UploadQueue` `292:2170` | — | 400 wide floating panel, bottom-right, `radius/md`, `e4` | Header "Uploading 7 files to Products" + per-state counts; collapsible; footer explains content check + EXIF strip + AVIF/WebP. |
| `MediaDeleteDialog` `292:2359` | `State` Blocked · Confirm | 520, `radius/lg`, `e4` (A23 geometry) | Blocked: lock icon on `bg/warning-subtle`, lists each usage with a link, actions Close + Replace file. Confirm (unused files only): consequences box, Cancel + Danger "Delete N files". |
| `MediaPickerModal` `294:2661` | `Tab` Library · Upload | 1000 wide, `radius/lg`, `e4` | Header with the calling context ("Brick Box 500 pcs · gallery has 1 of 10 images"), `AdminTab`s, ratio hint bar for the context (product = 1:1, min 1200 × 1200, 10 MB), folder rail 240 + search/type/sort + 4-up `MediaTile` grid, alt warning bar on `bg/warning-subtle` when a selected image lacks বাংলা alt (insert allowed, publish blocked), footer "3 selected · numbers set the gallery order" + Insert N. Upload tab: dashed dropzone + inline queue; failed files are never inserted. Single-select contexts (blog featured image, category banner) use `State=Selected` tiles instead of numbers. |

**Limits.** Product images: JPG, PNG, WebP or AVIF by content, ≤ 10 MB, ≤ 8000 px a side, square 1:1, min 1200 × 1200 (`StoreProductImageRequest`, `max:10240`). Certificates: PDF by content, ≤ 10 MB, no scripts or attachments. A8 and the `ImageUploader` component (`201:1035`) say 10 MB too (aligned 2026-11-12; they said 5 MB before). No video: deferred past v1 (open question 5, resolved).

---

## Part B — Screen layouts

Every figure below comes from the Part C dataset. Window: **02–08 Nov 2026 (Dhaka)**, the `Last 7 days` preset. Screen clock 08 Nov 2026, 2:30 pm.

### B1 · Dashboard — `/admin`

**1440 · content width 1144**

| Row | H | Contents |
|---|---|---|
| Content header | 72 | `admin/page-title` "Overview" · right cluster: `DateRangePicker` (240) "Last 7 days · 02–08 Nov", gap `spacing/3`, `Button outline md` "Export" |
| R1 · `StatCard` ×4 | 132 | gap `spacing/6`, each W 268 |
| R2 · `StatCard` ×4 | 132 | same |
| R3 · Status strip | 72 | W 1144, `color/bg/surface`, `radius/lg`, 1px `color/border/default`, `e1`; Horizontal, 10 equal cells of 114.4, 1px `color/border/default` dividers between |
| R4 · Sales trend + mix | 360 | `ChartCard` W 744 (line) + gap `spacing/6` + `ChartCard` W 376 (donut) |
| R5 · Best sellers + Low stock | 320 | two `DataTable` cards W 560 each, gap `spacing/6` |
| R6 · Needs attention | Hug | W 744 `ActivityFeed scope: global` + gap `spacing/6` + W 376 exception stack |

**R1 — the four numbers an owner opens the laptop for:**

| Card | Label | Value | Delta | Sparkline (02→08 Nov) | Drill-through |
|---|---|---|---|---|---|
| 1 | GROSS ORDER VALUE | **৳37,820** | +37.8% vs 26 Oct – 1 Nov (৳27,450) | 8,030 · 6,830 · 3,080 · 4,450 · 4,740 · 8,780 · 1,910 | Order board, All tab, same range |
| 2 | ORDERS PLACED | **12** | +33.3% vs 9 | 2 · 2 · 1 · 2 · 2 · 2 · 1 | Order board |
| 3 | NET REVENUE (DELIVERED) | **৳13,910** | +36.6% vs ৳10,180 | 0 · 0 · 8,030 · 0 · 4,550 · 1,330 · 0 | P&L, Block 1 |
| 4 | NET PROFIT | **৳717** | +40.0% vs ৳512 | −255 · −700 · 2,444 · −204 · 249 · 243 · −1,060 | P&L |

Card 3 carries an `info` tooltip: "Revenue is recognised when an order is delivered, not when it is placed. ৳18,000 of placed orders are still open." Card 4's sparkline crosses zero, so it draws the 1px `color/border/default` baseline.

**R2:**

| Card | Label | Value | Delta / footnote |
|---|---|---|---|
| 5 | CUSTOMERS | **184** | +8 new this period (+4.5% total) |
| 6 | AVERAGE ORDER VALUE | **৳3,152** | +3.3% vs ৳3,050 |
| 7 | COD WITH COURIER | **৳787** | footnote, no delta: "৳1,330 collected on 1 order, less ৳543 the courier will deduct on the next statement" — drills to COD receivables |
| 8 | LOW STOCK | **3** | footnote "of 10 active variants · 0 out of stock" — drills to the low-stock list |

**R3 — status strip.** Ten cells, each Vertical gap `spacing/1`, centred: `admin/num-lg` count, `admin/overline` `color/text/secondary` label, and a 14px workflow icon from A12 above the number so the strip reads without colour. Values, left to right: **Pending 2 · Confirmed 0 · Processing 0 · Ready to ship 3 · Shipped 1 · Delivered 3 · Completed 0 · Part. returned 1 · Returned 1 · Cancelled 1**. Sum 12. Each cell is a link to the matching board tab; zero cells render the count in `color/text/tertiary` and are not links. Pending and Ready to ship get a 2px `color/bg/brand` bottom edge because they are the two queues that cost money to leave alone.

**R4a — `ChartCard type: line`, W 744 × 360.** Title "Order value placed per day", subtitle "02–08 Nov 2026 · Dhaka dates". Series 1 solid `color/bg/brand` with area fill: the seven values above. Series 2 dashed `color/text/tertiary`: the previous period (26 Oct–1 Nov) at 3,980 · 4,420 · 2,860 · 5,310 · 3,650 · 4,790 · 2,440 = **৳27,450**. Y axis `৳0 / ৳3k / ৳6k / ৳9k`. Header right: segmented control Value / Orders. Peak tooltip, 07 Nov: "Order value ৳8,780 · 2 orders · TMB-26-00126 ৳6,260, TMB-26-00129 ৳2,520".

**R4b — `ChartCard type: donut`, W 376 × 360.** Title "Where the money came from", subtitle "By category, order value excluding the cancelled order". Centre: **৳34,520** / "ITEM VALUE". Seven slices, `legend: right`:

| Category | Value | Share |
|---|---|---|
| Ride-ons & Outdoor | ৳9,800 | 28.4% |
| Building & Construction | ৳5,550 | 16.1% |
| Dolls & Figures | ৳4,700 | 13.6% |
| Educational & Puzzles | ৳4,670 | 13.5% |
| Baby & Toddler | ৳4,200 | 12.2% |
| Other (Action & Blasters, Vehicles & RC) | ৳3,200 | 9.3% |
| Soft Toys | ৳2,400 | 7.0% |

৳34,520 = ৳37,820 gross order value − ৳1,880 cancelled − ৳1,520 delivery charges + ৳100 coupon discount. Shares are rounded to one decimal and sum to 100.1%; the ring is drawn from values, and a `admin/caption` `color/text/tertiary` footnote says so.

**R5a — Best sellers, W 560.** `DataTable compact`, no toolbar, columns Product (272) · Units (72, right) · Order value (104, right) · Trend (112, 32-tall sparkline):

| Product | Units | Order value |
|---|---|---|
| Kids Tricycle with Push Handle | 2 | ৳9,800 |
| Brick Box 500 | 3 | ৳5,550 |
| Fashion Doll Deluxe Set | 2 | ৳4,700 |
| Baby Activity Gym Mat | 2 | ৳4,200 |
| Plush Teddy Bear 45cm | 2 | ৳2,400 |

Footer: `admin/caption` `color/text/secondary` "23 units across 10 products · ৳34,520" + `Button ghost sm` "Full product performance".

**R5b — Low stock, W 560.** Columns Product (272) · Available (88, right) · Reorder point (96, right) · Action (104):

| Product | Available | Reorder point | Breakdown |
|---|---|---|---|
| Wooden Alphabet Puzzle BN+EN | **6** | 12 | 8 on hand − 2 reserved |
| Fashion Doll Deluxe Set | **5** | 6 | 5 on hand − 0 reserved |
| Kids Tricycle with Push Handle | **3** | 4 | 4 on hand − 1 reserved |

Available renders as a `color/bg/subtle` chip with a 2px `color/status/low-stock` left border, the number, and the word "low"; the reserved breakdown sits beneath in `admin/caption` `color/text/tertiary`. Action column: `Button outline sm` "Create PO". Footer: "0 out of stock" with a `check-circle` 14 tinted `color/feedback/success`.

**R6b — exception stack, W 376.** Three cards, gap `spacing/4`, each H Hug, `color/bg/surface`, `radius/lg`, 1px `color/border/default`, 3px left edge:
1. `color/feedback/danger` edge — "1 order needs phone verification" · TMB-26-00126 · Shahriar Kabir · risk **80 / 100** · ৳6,260 · `Button primary sm` "Open risk screen".
2. `color/status/low-stock` edge — "2 shipments submitted, not confirmed" · 5h 25m since 9:05 am · batch `BULK-2026-1108-01` · `Button outline sm` "Open submitted queue".
3. `color/status/low-stock` edge — "1 refund pending 3 days" · TMB-26-00128 · ৳200 to Tanzila Akter · `Button outline sm` "Approve refund".

**768.** Content header stacks (title, then a Fill-width `DateRangePicker`). `StatCard`s become 2-up `size: sm`, 4 rows. The status strip becomes a horizontally scrollable chip row of 10 chips, Pending first. Charts go full width at `height` 200, the donut's legend moving to `top`. Best sellers and Low stock become card lists of 3 and 3 rows. The exception stack moves **above** the stat cards — on a phone the operator is checking what needs doing, not reading totals.

**1024.** `StatCard`s 3-up × 3 rows (the 9th slot holds the "needs attention" count as a `sm` card). Status strip wraps to 5 + 5. Charts stack full width at 920. Best sellers and Low stock stack full width.

---

### B2 · Order board — `/admin/orders`

**Default tab is `Pending`** (2 rows), per UI-UX-DESIGN-SPEC §6.4, because that is the queue that costs money to leave alone.

**1440 layout**

| Row | H | Contents |
|---|---|---|
| Content header | 72 | `admin/page-title` "Orders" · right: `DateRangePicker` 240, `Button outline md` "Export", `Button primary md` "New order" |
| Tab strip | 48 | W 1144, Horizontal gap `spacing/1`, bottom border 1px `color/border/default`; each tab padding `spacing/2` `spacing/3`, `radius/md` top corners |
| `DataTable` | Hug | W 1144, `comfortable`, sticky header |
| `BulkActionBar` | 64 | `floating`, appears on selection |

**Tabs with live counts** — counts poll every 30 s and animate only the digit:
`Pending 2` · `Confirmed 0` · `Processing 0` · `Ready to ship 3` · `Shipped 1` · `Delivered 3` · `Completed 0` · `Part. returned 1` · `Returned 1` · `Cancelled 1` · `On hold 0` · `All 12` · **`⚠ Anomalies 1`** (pinned right, 2px `color/feedback/warning` left border, always visible).
Active tab: bg `color/bg/brand-subtle`, label 600 `color/text/primary`, 2px `color/bg/brand` bottom edge. Count badge: H 18, `radius/sm`, bg `color/bg/subtle`, `admin/caption`; zero counts in `color/text/tertiary`. At 1024 the strip scrolls horizontally with the active tab scrolled into view.

**Columns (sum 1144):**

| # | Column | W | Align | Content |
|---|---|---|---|---|
| 1 | — | 40 | centre | `Checkbox` |
| 2 | Order | 124 | left | `admin/mono` 13 600 `TMB-26-00126`, link; line 2 `admin/caption` `color/text/tertiary` source (`web` / `admin` / `phone` / `facebook`) |
| 3 | Placed | 112 | left | `admin/num` 13 "07 Nov"; line 2 `admin/caption` "8:08 pm"; under 24 h shows "18h ago" |
| 4 | Customer | 196 | left | `admin/body-strong` name; line 2 `admin/num` 12 masked phone `01712•••318` with a 20×20 `IconButton ghost` `eye` to reveal (logged to `audit_sensitive`); line 3 `admin/caption` `color/text/tertiary` district |
| 5 | Items | 56 | right | `admin/num` count; tooltip lists product names and quantities |
| 6 | Total | 104 | right | `admin/num` `৳6,260`; line 2 `admin/caption` `color/text/tertiary` due if any (`due ৳2,310`) |
| 7 | Payment | 92 | left | method chip: `cod` `banknote` / `bkash` `smartphone` / `sslcommerz` `credit-card`; bg `color/bg/subtle`, label `color/text/primary` |
| 8 | Status | 188 | left | `OrderStatusBadge triad-stacked` (A12) |
| 9 | Risk | 88 | left | risk pill: bg `color/bg/subtle`, 2px left border in the level token, 14px shield icon, label **word + number** (`High 80`) |
| 10 | Courier | 144 | left | provider + state: `Booked DT261107FJ2D9Q`, `Submitted 5h 25m`, `Not booked`, `Returned`; `admin/mono` 12 for the ID with a `copy` button |

**All twelve rows, exactly as they render** (the `All` tab; every row of Part C has a specified rendering):

| Order | Placed | Customer / masked phone / district | Items | Total | Pay | Slot 1 workflow | Slot 2 money | Slot 3 goods | Risk | Courier |
|---|---|---|---|---|---|---|---|---|---|---|
| TMB-26-00118 | 02 Nov 10:42 | Jahangir Alam · 01711•••046 · Dhaka | 1 | ৳5,060 | COD | **Delivered** | *Paid · in bank* | *All items delivered* | Low 5 | Booked `DT261103RQ7K2M` |
| TMB-26-00119 | 02 Nov 15:18 | Farhana Yasmin · 01777•••615 · Dhaka | 3 | ৳2,970 | bKash | **Delivered** | *Paid · in bank* | *All items delivered* | Low 0 | Booked `DT261103YV4B8X` |
| TMB-26-00120 | 03 Nov 11:05 | Md. Rakibul Hasan · 01919•••052 · Rajshahi | 3 | ৳4,550 | COD | **Delivered** | ℹ Part. refunded ৳300 | *All items delivered* | Low 24 | Booked `DT261104KM9T3P` |
| TMB-26-00121 | 03 Nov 19:33 | Nusrat Jahan · 01841•••907 · Cumilla | 1 | ৳2,280 | COD | **Returned** | *Unpaid* | *Returned to us* | Low 23 | Returned `DT261104ZC6H1W` |
| TMB-26-00122 | 04 Nov 09:27 | Tanzila Akter · 01533•••480 · Dhaka (Savar) | 2 | ৳3,080 · due ৳1,750 | COD | **Part. returned** | ℹ Part. paid ৳1,330 · with courier | *Partly sent* | Low 10 | Part. delivered `DT261105NB8L5R` |
| TMB-26-00123 | 05 Nov 14:52 | Imran Hossain · 01311•••203 · Sylhet | 3 | ৳2,570 | COD | **Shipped** | *Unpaid* | *All items delivered* | Low 5 | Booked `DT261107FJ2D9Q` |
| TMB-26-00124 | 06 Nov 12:36 | Sumaiya Islam · 01985•••739 · Dhaka | 1 | ৳1,930 | COD | **Ready to ship** | *Unpaid* | *Not sent* | Low 10 | Submitted 5h 25m |
| TMB-26-00125 | 06 Nov 17:49 | Abdullah Al Mamun · 01625•••174 · Khulna | 2 | ৳2,810 · due ৳2,310 | COD | **Ready to ship** | ℹ Part. paid ৳500 advance | *Not sent* | **Medium 40** | Not booked |
| TMB-26-00126 | 07 Nov 20:08 | Shahriar Kabir · 01712•••318 · Dhaka | 2 | ৳6,260 | COD | **Pending** | *Unpaid* | *Not sent* | **High 80** | Not booked |
| TMB-26-00127 | 08 Nov 10:15 | Rifat Chowdhury · 01876•••912 · Dhaka (Keraniganj) | 3 | ৳1,910 | COD | **Pending** | *Unpaid* | *Not sent* | Low 10 | Not booked |
| TMB-26-00128 | 05 Nov 21:14 | Tanzila Akter · 01533•••480 · Dhaka (Savar) | 1 | ৳1,880 | bKash | **Cancelled** | **⚠ Part. paid ৳200 — refund pending, 3 days** | *Not sent* | Low 0 | Not booked |
| TMB-26-00129 | 07 Nov 13:22 | Mehedi Hasan · 01398•••467 · Dhaka | 2 | ৳2,520 | COD | **Ready to ship** | *Unpaid* | *Not sent* | Low 10 | Submitted 5h 25m |

Reading convention: **bold** = full chip, *italic* = plain `color/text/tertiary` text with no fill (the `expected` severity), ℹ = `notable`, ⚠ = `anomaly`. Eleven of twelve rows carry exactly one chip of visual weight in the Status column. The one that does not is TMB-26-00128, and it is the one that needs a human.

Row 00126 additionally carries a 2px `color/feedback/danger` left border on the whole row because `verification_status = pending` — the only row-level emphasis on the board, reserved for "this cannot be booked until someone makes a phone call".

**Bulk actions.** `BulkActionBar` offers, in order: **Confirm** · **Mark packed** · **Book courier** · **Print invoices** · overflow (Assign to me · Add internal note · Change status · Export selected · **Cancel orders**, as `Button danger`). The context block shows "Total ৳12,580 · 2 outside Dhaka · 1 high risk". Selecting an order with `verification_status = pending` disables **Book courier** with the reason "1 of 4 selected orders needs phone verification first" and a `Button ghost sm` "Drop it from the selection".

**Keyboard shortcuts for the top five actions** — the board is a day-long workspace, so these are first-class and documented in a `?` overlay:

| Key | Action | Confirmation |
|---|---|---|
| `C` | Confirm selected (or the focused row) | inline in `BulkActionBar` |
| `P` | Mark packed → `ready_to_ship` | inline |
| `B` | Book courier → opens `CourierBookingPanel` | the panel is the confirmation |
| `I` | Print invoices for the selection | opens the print dialog |
| `X` | Cancel | `ConfirmDialog destructive-typed`, order number required |

Navigation: `J` / `↓` next row · `K` / `↑` previous · `Space` toggle selection · `Shift+Space` range-select · `Enter` open detail · `A` select all on page · `Esc` clear selection · `1`–`9` and `0` switch tab · `/` or `⌘K` focus search · `D` toggle density · `?` shortcut sheet. Every shortcut is ignored while focus is inside a text field, and the focused row carries the 2px `color/border/focus` inset ring so the keyboard position is always visible.

**768.** Tab strip becomes a horizontally scrolling chip row, Pending active and first, with the Anomalies chip pinned at the left edge after Pending. Rows become cards (A2): line 1 `TMB-26-00126` + `৳6,260`; line 2 "Shahriar Kabir · 01712•••318"; line 3 the workflow chip + risk pill + any `notable`/`anomaly` money chip. Swipe-left on a card reveals Confirm / Book. `BulkActionBar` docks to the bottom.

---

### B3 · Order detail — `/admin/orders/TMB-26-00122`

Chosen because it exercises everything: a partial delivery, a courier return, a split COD payment, a write-down, and a negative net profit.

**1440 · main 744 + gutter 24 + rail 376 = 1144**

| Region | Column | H | Contents |
|---|---|---|---|
| Header | full 1144 | 104 | Breadcrumb "Orders › TMB-26-00122"; `admin/page-title` `TMB-26-00122` + `OrderStatusBadge triad-inline`; line 2 `admin/caption` `color/text/secondary` "Placed 04 Nov 2026, 9:27 am · source web · 2 items · ৳3,080"; right cluster: `Button outline md` "Print invoice", `Button outline md` "Edit order", `IconButton outline` overflow (Duplicate · Add note · Cancel · Activity log) |
| Stepper | full 1144 | Hug | `OrderStatusStepper horizontal`, three tracks |
| Items | main 744 | Hug | items card |
| Payments | main 744 | Hug | payments card |
| Shipment | main 744 | Hug | `ShipmentTrackingTimeline` |
| Notes + activity | main 744 | Hug | `ActivityFeed scope: entity` with the note composer |
| Customer | rail 376 | Hug | customer card |
| Addresses | rail 376 | Hug | shipping + billing |
| Risk | rail 376 | Hug | `RiskScoreCard form: compact` |
| Profit | rail 376 | Hug | order profitability card |

**Stepper, as rendered:**

- **WORKFLOW** (`orders.status`) — Pending ✓ 04 Nov 9:27 am · Confirmed ✓ 04 Nov 10:11 am (Rina Akter) · Processing ✓ 05 Nov 1:48 pm · Ready to ship ✓ 05 Nov 2:05 pm · Shipped ✓ 05 Nov 4:40 pm · ~~Delivered~~ `skipped` · branch → **Part. returned** `current` 07 Nov 11:48 am (Pathao webhook) · Completed `upcoming`
- **MONEY** (`payment_status` + settlement) — Unpaid ✓ · **Collected by courier** `current` ৳1,330 of ৳3,080 on 07 Nov · Settled to us `upcoming`, tooltip "Expected in the next Pathao settlement; the previous one, PTH-STL-2026-45, closed 06 Nov"
- **GOODS** (`fulfillment_status`) — Not sent ✓ · Packed ✓ 05 Nov · Handed to courier ✓ 05 Nov · branch → **Partly delivered** `current` 07 Nov · "Returned to us" appended as a second `current` node, 08 Nov 12:20 pm

**Items card, W 744.** Header `admin/section` "Items (2)" + right `admin/caption` `color/text/tertiary` "Costs visible to accountants and the owner". `DataTable compact`, columns Product (288) · SKU (128) · Unit price (96, right) · Qty (56, right) · Line total (96, right) · Status (80):

| Product | SKU | Unit price | Qty | Line total | Item status |
|---|---|---|---|---|---|
| Plush Teddy Bear 45cm | `TMB-PTB-45` | ৳1,200 | 1 | ৳1,200 | **Delivered** `circle-check` |
| Foam Blaster X2 | `TMB-FBL-X2` | ৳1,750 | 1 | ৳1,750 | **Returned** `undo-2`, sub-line "restocked 08 Nov at ৳790 — written down ৳260" |

Totals block beneath, right-aligned in a 320 column, rows H 32: Subtotal ৳2,950 · Discount ৳0 · Delivery charge ৳130 · **Grand total ৳3,080** (subtotal row styling) · Paid ৳1,330 · **Due ৳1,750** (with a 14px `alert-triangle` tinted `color/status/low-stock` and the sub-line "The returned item's ৳1,750 was never collected. This due will be written off when the return is closed.").

**Payments card, W 744.** One row per `payments` record: method chip `banknote` COD · ৳1,330 · status **Paid** · "Collected by Pathao courier, 07 Nov 11:48 am" · `admin/caption` `color/text/tertiary` "No gateway fee on COD. Reaches our bank at settlement." Footer: `Button outline sm` "Record a payment" · `Button ghost sm` "Issue a refund".

**Customer card, W 376.** `Avatar` 40 + "Tanzila Akter" + `01533 619 480` full (the detail screen reveals it; the board masks it) with `copy` and `phone` buttons. Then: "2 orders · 1 partly delivered · 1 cancelled · lifetime value ৳1,200 · customer since 04 Nov 2026 · prefers Bangla". `Button outline sm` "Open customer record".

**Addresses card, W 376.** Two blocks separated by a 1px `color/border/default` divider. Shipping: "Tanzila Akter · 01533 619 480 · House 11, Rajashon Road, Savar, Dhaka 1340 · **beside Savar Bazar bus stand**" — the landmark rendered `admin/body-strong` because it is what actually gets the parcel delivered. A `color/bg/subtle` chip beneath: "Zone: Dhaka suburb · delivery ৳110 + ৳20 weight". Billing: "Same as shipping". `Button ghost sm` "Copy address" copies the courier-ready single-line form.

**Risk card, W 376.** `RiskScoreCard form: compact`: Panel A gauge **10 / 100**, chip "Low risk · 10 / 100", verdict "First-ever order from this phone when it was placed. No other signals.", breakdown collapsed to one visible row (First-ever order · 10) with "7 signals not triggered". Panel B: **`not-connected`**, full message. A `admin/caption` `color/text/tertiary` note: "The score was 10 at placement. It is recomputed on every order event — this phone now has a returned item on record."

**Profit card, W 376.** `admin/overline` "THIS ORDER'S PROFIT", then rows (label `admin/body`, value `admin/num`): Revenue (delivered line) ৳1,200 · Cost of goods −৳640 · Gross profit ৳560 · Delivery collected +৳130 · Courier charge −৳130 · COD fee −৳13 · Return charge −৳70 · Packaging −৳25 · Return write-down −৳260 · Allocated advertising −৳350 · **Net profit −৳158** in `admin/num-lg` with a 16px `trending-down` tinted `color/feedback/danger`. Footnote `admin/caption` `color/text/secondary`: "A partial return turned a ৳560 gross profit into a ৳158 loss. The two courier charges and the write-down are the whole difference." Gated on `finance.view_order_profit`.

**768.** Single column in this order: header (title + triad stacked) → `OrderStatusStepper vertical` → items (card list) → totals → risk → shipment timeline → payments → customer → addresses → profit → notes. The header actions collapse into one `Button primary` "Actions" bottom sheet. **1024:** main 612 + rail 288; the items table scrolls horizontally with Product frozen.

---

### B4 · Product create / edit — `/admin/products/brick-box-500/edit`

**1440 · form 744 + gutter 24 + rail 376 = 1144.** Seven tabs, URL-addressable (`?tab=pricing`), each tab's unsaved state tracked separately so the save bar reports "3 unsaved changes across 2 tabs".

| Region | H | Contents |
|---|---|---|
| Content header | 104 | Breadcrumb; `admin/page-title` "Brick Box 500" + a `Badge` "Published"; line 2 `admin/caption` "`TMB-BRK-500` · Building & Construction · updated 06 Nov 2026, 4:30 pm by Mominul Islam"; right: `Button ghost md` "View on site", `Button outline md` "Duplicate" |
| Tab strip | 48 | General · Pricing · Variants · Inventory · Images · SEO · Related — each with a 16px status dot: `color/feedback/danger` if the tab has an error, `color/bg/brand` if it has unsaved changes, nothing otherwise |
| Tab body | Hug | `FormSection` stack in the 744 column, gap `spacing/6`; rail cards gap `spacing/6` |
| `FormActions` | 64 | sticky bottom, spans 1144 |

**Rail (all tabs):** a **Live preview** card (W 376, product card rendered at storefront scale with a Mobile/Desktop toggle); a **Status** card (`Switch` Published, visibility `NativeSelect`, publish-at `DateRangePicker` single-date, channel checkboxes); a **At a glance** card — "31 units across 4 variants · stock value ৳39,200 · 3 units sold this week · lowest margin 36.8%".

**General** — `FormSection two-column`: Name EN (`Input`, 120 chars) · Name BN (`Input`, `Hind Siliguri`) · `SlugField` (see below) · Short description (`Textarea` 80, 180 chars, "shown on cards and in search results") · Full description (`RichTextEditor` 320 tall) · Category (`Select`, tree-aware, "Building & Construction") · Brand (`Select`, "Unbranded") · Age range (two `NativeSelect`s, 4–10 years, rendering a live `AgeRangeIndicator` preview) · Tags (`Chip` input) · Safety note (`Textarea`, "small parts — not for under 3s").

The **`SlugField`** is in its `redirect-warning` state: `toymartbd.com/products/` + `classic-brick-box-500`, with the panel "Changing this URL creates a 301 redirect — the old URL `brick-box-500-pcs` has been live since 14 Jul 2026 and is linked from 2 blog posts and the homepage Best sellers block", and the required acknowledgement checkbox.

**Pricing** — `FormSection two-column`: Price `৳1,850` · Compare-at price `৳2,200` with a live `admin/caption` "Shows as −16% on the storefront" · Cost `৳1,120` (permission-gated, writes to `audit_sensitive`) · computed **Margin 39.5%** / **Markup 65.2%** shown as read-only `admin/num` with a 96×6 bar · Tax class (`NativeSelect`, "Standard") · COD advance requirement (`Switch` + `Input ৳`, off) · Minimum / maximum order quantity · a `color/bg/subtle` **Price history** block listing the last 5 changes with actor and timestamp, because a price change is one of the seven audited actions.

**Variants** — `VariantMatrixEditor` (A9). Attributes: **Piece count** (500, 1000) × **Colour mix** (Classic, Pastel) → 4 combinations, generated:

| Enabled | Variant | SKU | Price | Cost | Margin | Stock | Weight |
|---|---|---|---|---|---|---|---|
| on | 500 / Classic | `TMB-BRK-500-CL` | ৳1,850 | ৳1,120 | 39.5% | 21 | 1,400 g |
| on | 500 / Pastel | `TMB-BRK-500-PS` | ৳1,950 | ৳1,180 | 39.5% | 6 | 1,400 g |
| on | 1000 / Classic | `TMB-BRK-1000-CL` | ৳3,400 | ৳2,150 | 36.8% | 4 | 2,600 g |
| **off** | 1000 / Pastel | `TMB-BRK-1000-PS` | ৳3,500 | ৳2,210 | 36.9% | 0 | 2,600 g |

Footer summary: "4 combinations · 3 enabled · total stock 31 · stock value ৳39,200 · lowest margin 36.8%". The disabled row keeps its values and shows `admin/caption` `color/text/tertiary` "Disabled 21 Oct — out of stock, not discontinued".

**Inventory** — per-variant rows, read-only stock with a `Button outline sm` "Adjust" each: On hand · Reserved · Available · Incoming · Reorder point · Reorder quantity · Average cost (4 dp). Then: Track inventory (`Switch`, on) · Allow backorder (`Switch`, off) · Low-stock alert threshold. A `color/bg/subtle` block: "Stock is only ever changed by a movement. The last one was `sale_out −1` on 08 Nov 8:35 am for TMB-26-00124." + `Button ghost sm` "Open this product's ledger".

**Images** — `ImageUploader` (A8), 5 images populated, image 1 `primary`. Two tiles carry the amber "Bangla alt text missing" marker, which is the warning the SEO checklist reports.

**SEO** — `SeoPanel` (A11). Title "Brick Box 500 pcs — Classic Building Blocks | Toy Mart BD" at **318 / 580 px**; description 742 / 920 px; SERP preview with the rich-result row "4.6 ★★★★☆ · 23 reviews · ৳1,850 · In stock"; OG preview on the WhatsApp tab showing the 1:1 crop warning; checklist with the two alt-text warnings.

**Related** — three `FormSection single` blocks, each a searchable `Select` plus a reorderable list of cards (64-tall: thumb 48, name, SKU, price, `grip-vertical`, `x`): **Related products** (manual, max 8, 4 set) · **Frequently bought together** (max 3, 2 set: Magnetic Drawing Board A4, Wooden Alphabet Puzzle) · **Upsell** (max 4, 1 set: Brick Box 1000 / Classic). Each block shows `admin/caption` `color/text/tertiary` "Leave empty to use automatic recommendations by category and age range."

**768.** Tabs become a horizontally scrolling chip strip with the dirty/error dots preserved. All `FormSection`s go `single`. The rail cards move **below** the form, live preview first. `FormActions` is the 72-tall two-row variant. **1024:** form 612 + rail 288; the variant matrix scrolls horizontally with Enabled + Variant frozen.

---

### B5 · Phone lookup / risk screen — `/admin/risk/lookup?phone=01712046318`

**1440 · content width 1144, single column, gap `spacing/6`**

| Region | H | Contents |
|---|---|---|
| Content header | 72 | `admin/page-title` "Phone lookup" · right: `Button outline md` "High-risk queue (1)", `Button ghost md` "Blacklist (0)" |
| Search block | 96 | `CustomerPhoneLookup` (A15) — input holding `01712 046 318`, `Button primary lg` "Look up" |
| Summary | 112 | identity + 5-metric strip + actions |
| **Risk** | Hug | `RiskScoreCard form: full` — Panel A 560, Panel B 560 |
| Manual status strip | 64 | A14 manual strip |
| Order history | Hug | `DataTable compact`, 8 rows |
| Pinned notes | Hug | 1 note |

**Summary, as rendered.** Identity: `Avatar` "SK" · **Shahriar Kabir** · `01712 046 318` with copy · "Customer since 12 Sep 2026 · source: facebook · prefers Bangla". Metric strip: **Orders 8** · **Delivered 1** · **Cancelled 4** · **Returned 2** · **Lifetime value ৳1,310**. Beneath the strip, a single `admin/body` line in `color/text/primary` with a 16px `trending-down` tinted `color/feedback/danger`: **"This phone has cost ৳492 net across 8 orders."** (+৳118 on the one delivered order, −৳310 and −৳300 on the two courier returns.) Actions: `Button primary md` "Call 01712 046 318" (`tel:`), `Button outline md` "Open customer record".

**Panel A — Toy Mart risk score.** Eyebrow `OUR ASSESSMENT`. Provenance: "Computed by us from this phone's order history · 08 Nov 2026, 2:18 pm · recomputed on every order event". Gauge **80**, arc `color/feedback/danger`. Chip: `shield-x` + **"High risk · 80 / 100"**. Verdict: *"Phone before booking. 4 of this phone's 7 previous orders were cancelled and 2 came back from the courier."* Policy line: *"High risk requires phone verification before courier booking (`verification_status = pending`). The score never cancels an order by itself."*

Breakdown (the five triggered signals expanded, three collapsed under "SIGNALS NOT TRIGGERED (3)"):

| Signal | Evidence | Bar | Points |
|---|---|---|---|
| Prior cancellation rate | 4 of 7 previous orders cancelled — 57.1%, cap 30% | full | **25** / 25 |
| Prior courier-return rate | 2 of 7 came back from the courier — 28.6%, cap 20% | full | **25** / 25 |
| Prior failed delivery attempts | 3 attempts across 2 shipments — cap 3 | full | **15** / 15 |
| Order value far above this customer's norm | ৳6,260 against this phone's ৳1,310 average — 4.8× | full | **10** / 10 |
| Address quality | "Block C, Bashundhara R/A" — no house or road number | full | **5** / 5 |
| *First-ever order from this phone* | *No — 7 previous orders* | — | *0* / 10 |
| *Multiple orders from this phone within minutes* | *No* | — | *0* / 5 |
| *Phone fails operator-prefix validation* | *No — 017 is a valid prefix* | — | *0* / 5 |
| **Total** | | | **80 / 100** |

Model note: *"Rate signals are capped: a 30% cancellation rate or a 20% return rate scores the full weight. With fewer than 3 previous orders, rate signals carry half weight. Weights are editable in Risk → Rule configuration."*

**Panel B — Courier-verified delivery record.** State **`not-connected`**, and this is the live state for every phone number in the system. Dashed 1px `color/border/strong`, no shadow, neutral top edge, body `color/bg/subtle`. Centred, 240 tall: 32px `plug-zap-off` in `color/text/tertiary`; `admin/section` **"Provider not connected"**; `admin/body` `color/text/secondary`: *"No courier account is connected, so there is no external delivery record for this phone. This is **not** a clean record — it is an absence of evidence."* Divider, then `admin/caption` `color/text/tertiary`: *"Steadfast's `/fraud_check/{phone}` needs a merchant account. Pathao publishes no fraud-check endpoint. Connect an account in System → Courier settings."* Then `Button outline md` "Open courier settings". **No number, no ratio, no bar, and no zero appears anywhere in this panel.**

A `admin/caption` `color/text/tertiary` line sits between the two panels' footers, spanning both: *"These two panels are never combined. Panel A is our heuristic; Panel B would be a provider's record. There is no overall risk figure on this screen."*

**Manual status strip.** `circle` + **"Normal"** · reason "No manual status set" · `Button outline md` "Change status". Changing to `blacklist` opens `ConfirmDialog destructive-typed` requiring `01712046318` to be typed, and enumerates: "New orders from this phone will be held at `on_hold` automatically · Existing order TMB-26-00126 is not affected · The score in Panel A does not change · This is written to the audit log".

**Order history**, columns Order (124) · Placed (112) · Items (56) · Total (104) · Status triad (188) · Courier (144) · Outcome (248) · Net profit (168):

| Order | Placed | Items | Total | Workflow / money / goods | Courier | Outcome | Net profit |
|---|---|---|---|---|---|---|---|
| TMB-26-00041 | 12 Sep | 1 | ৳1,310 | Delivered / *Paid · in bank* / *All items delivered* | `DT260912HB3N7K` | Delivered 14 Sep | **+৳118** |
| TMB-26-00055 | 24 Sep | 2 | ৳2,480 | Cancelled / *Unpaid* / *Not sent* | — | Cancelled before dispatch — unreachable by phone | ৳0 |
| TMB-26-00063 | 29 Sep | 1 | ৳1,930 | Returned / *Unpaid* / *Returned to us* | `DT260930WQ2L5X` | Refused at door after 2 attempts | **−৳310** |
| TMB-26-00079 | 08 Oct | 3 | ৳3,640 | Cancelled / *Unpaid* / *Not sent* | — | Cancelled — no answer on 3 calls | ৳0 |
| TMB-26-00088 | 14 Oct | 1 | ৳2,100 | Returned / *Unpaid* / *Returned to us* | `DT261014MK8T4R` | Undeliverable after 1 attempt | **−৳300** |
| TMB-26-00097 | 21 Oct | 2 | ৳2,570 | Cancelled / *Unpaid* / *Not sent* | — | Cancelled at customer request | ৳0 |
| TMB-26-00106 | 29 Oct | 1 | ৳1,750 | Cancelled / *Unpaid* / *Not sent* | — | Cancelled — duplicate of TMB-26-00097 | ৳0 |
| TMB-26-00126 | 07 Nov | 2 | ৳6,260 | **Pending** / *Unpaid* / *Not sent* | — | Awaiting phone verification | — |

These seven prior orders fall **outside** the 02–08 Nov window, so none of them appears in the dashboard totals or the P&L. Only TMB-26-00126 does, and it is `pending`, so it contributes ৳6,260 to gross order value and nothing to revenue.

**Pinned note:** `pin` 14 + *"Asked for cash-on-delivery on a ৳6,260 tricycle after four cancellations. Call before booking anything over ৳2,000 to this number."* — Rina Akter, 08 Nov 2026, 9:40 am.

**768.** Input Fill × 52, `inputmode="numeric"`. Metric strip 2-up × 3. `RiskScoreCard` goes `compact`, Panel A then Panel B stacked — Panel B is **not** collapsed. History becomes a card list, Outcome on line 3 and Net profit right-aligned on line 1. **1024:** panels 450 + 450; history scrolls horizontally with Order frozen.

---

### B6 · Courier booking queue — `/admin/fulfilment/booking`

**1440 · content width 1144**

| Region | H | Contents |
|---|---|---|
| Content header | 72 | `admin/page-title` "Courier booking" · right: provider balance chip "Pathao ৳3,240 · checked 1:40 pm", `Button outline md` "Courier settings" |
| Provider trust banner | Hug | shown because one configured driver is untested — see below |
| Tab strip | 48 | `Ready to book 1` · `Submitted — awaiting confirmation 2` · `Failed 0` · `Booked today 0` · `All open 3` |
| `DataTable` | Hug | W 1144, `comfortable` |
| `BulkActionBar` | 52 | `docked-toolbar` (a floating bar would cover the drawer's footer) |
| `CourierBookingPanel` | — | right drawer W 480 on row action |

**Provider trust banner**, Fill × Hug, bg `color/bg/subtle`, 3px `color/status/low-stock` left edge, 1px `color/border/default`, `radius/md`, padding `spacing/4` `spacing/5`, icon `flask-conical` 20 tinted `color/status/low-stock`:
> **"Steadfast driver has never been tested against a live account."** No Steadfast merchant credentials are configured, so no booking, charge calculation or fraud check has ever succeeded through this driver. Field names, charge rounding and the response shape are implemented from the published documentation and are unverified. Book one low-value order through it first and confirm the consignment ID appears. — `Button ghost sm` "Open courier settings"

Pathao carries the `verified` trust chip ("Live · 47 bookings") and is pre-selected everywhere.

**Columns (sum 1144):** — (40) · Order (124) · Customer + district (212) · Zone (112) · Weight (88, right) · COD amount (112, right) · Est. charge (112, right) · Packed (100) · Booking state (244)

**`Ready to book` tab — 1 row:**

| Order | Customer | Zone | Weight | COD amount | Est. charge | Packed | State |
|---|---|---|---|---|---|---|---|
| TMB-26-00125 | Abdullah Al Mamun · Khulna | Outside Dhaka | 920 g | **৳2,310** | **৳163** | 08 Nov 8:50 am | `Not booked` + `Button primary sm` "Book" |

The COD cell shows `admin/num` **৳2,310** with `admin/caption` `color/text/tertiary` "of ৳2,810 — ৳500 advance received", because booking the grand total would mean collecting the advance twice. The Est. charge cell breaks down in its tooltip: delivery ৳140 (outside Dhaka, under 1 kg so no weight surcharge) + COD fee ৳23 (1% of ৳2,310) = ৳163, plus "৳70 more if refused". The risk pill from this order (**Medium 40**) appears as a 16px `shield-alert` prefix on the Order cell with a tooltip, since a medium-risk order being booked is worth a glance.

**`Submitted — awaiting confirmation` tab — 2 rows.** This tab exists because **bulk booking returns no consignment IDs on either provider** — Pathao answers `202` with none, and Steadfast accepts up to 500 orders and answers with none. Both rows came from one bulk submission:

| Order | Customer | Zone | Weight | COD amount | Est. charge | Packed | State |
|---|---|---|---|---|---|---|---|
| TMB-26-00124 | Sumaiya Islam · Dhaka (Mirpur) | Inside Dhaka metro | 1,400 g | ৳1,930 | ৳99 | 08 Nov 8:35 am | **Submitted — awaiting confirmation** · `Not issued yet` · **5h 25m** since 9:05 am |
| TMB-26-00129 | Mehedi Hasan · Dhaka (Mohammadpur) | Inside Dhaka metro | 2,920 g | ৳2,520 | ৳125 | 08 Nov 8:40 am | **Submitted — awaiting confirmation** · `Not issued yet` · **5h 25m** since 9:05 am |

Both state cells use the A16 `submitted` treatment: `clock-arrow-up` tinted `color/status/low-stock`, 2px `color/status/low-stock` left border, 1px dashed border on the remaining three sides, consignment rendered as **`Not issued yet`** in `admin/mono` `color/text/tertiary` — never a dash and never blank. The elapsed timer is `admin/num` and crosses to a `color/feedback/warning` `alert-triangle` with "Overdue — chase Pathao support" past 6 h, which these two rows reach at 3:05 pm. A batch chip above the table reads `BULK-2026-1108-01 · submitted 08 Nov 9:05 am · 2 orders · Pathao` with `Button ghost sm` "View submitted payload". Row actions: `Button outline sm` "Poll for consignment ID" · overflow (View payload · Mark as failed and re-book). **Print label is disabled on both rows**, tooltip "A label cannot be printed before the consignment ID arrives".

Charge check: 1,400 g → ৳60 + ৳20 = ৳80 delivery, COD fee 1% of ৳1,930 = ৳19, total ৳99. 2,920 g → ৳60 + ৳40 = ৳100 delivery, COD fee 1% of ৳2,520 = ৳25, total ৳125. Batch estimate ৳180 delivery on ৳4,450 of COD across 4,320 g.

**`BulkActionBar docked-toolbar`.** On the `Ready to book` tab: "1 order selected · ৳2,310 COD · 1 outside Dhaka" with **Submit to Pathao** · Print packing slips · overflow (Change provider · Unpack). Selecting ≥2 rows switches the primary label to "Submit 2 orders to Pathao" and opens `CourierBookingPanel mode: bulk`, whose required acknowledgement is the sentence about consignment IDs. On the `Submitted` tab: "2 selected" with **Poll for consignment IDs** · Mark as failed and re-book.

**Empty states.** `Ready to book` empty uses `EmptyState reason: all-clear` with the illustration: "No orders are waiting to be booked. The last one went out at 9:05 am." `Failed` empty uses `all-clear` without illustration: "No failed bookings. Failures land here with their payload so they can be retried."

**768.** Rows become cards: line 1 order number + COD amount; line 2 customer + zone; line 3 the booking-state chip; line 4 weight + est. charge. The booking panel becomes a full-screen sheet with the charge preview pinned above the footer. `BulkActionBar` docks to the viewport bottom. **1024:** horizontal scroll with Order frozen; the drawer narrows to 440 and the content area dims behind it.

---

### B7 · P&L statement — `/admin/finance/pnl`

**1440 · statement 736 + gutter 24 + rail 384 = 1144.** `ProfitLossStatement` (A19) with the Part C figures. Header: `admin/page-title` "Profit & loss" · `DateRangePicker` "Last 7 days · 02–08 Nov" · comparison `NativeSelect` "vs previous period" · `Button outline md` "Export".

**The statement, line for line:**

| | Line | Value |
|---|---|---|
| **BLOCK 1 OF 5 — What customers owed us** | *Only delivered orders count. An order still in a courier's van is not revenue yet.* | |
| | Product revenue · 4 orders delivered | ৳13,440 |
| | Delivery charges collected | ৳570 |
| | Gross revenue | ৳14,010 |
| | − Discounts and coupons · 1 use of `WELCOME100` | −৳100 |
| | **= Net revenue** | **৳13,910** |
| **BLOCK 2 OF 5 — What the toys cost us** | *The purchase cost of exactly the items that were delivered, frozen when each order was placed.* | |
| | − Cost of goods sold | −৳8,210 |
| | **= Gross profit** · 41.0% of net revenue | **৳5,700** |
| **BLOCK 3 OF 5 — What it cost to deliver and get paid** | *In cash-on-delivery these are not small. They are the difference between a healthy-looking margin and the real one.* | |
| | − Courier delivery charges · 5 parcels, including the one that came back | −৳750 |
| | − COD collection fees · 1% of ৳10,940 collected, rounded per shipment | −৳110 |
| | − Courier return charges · 2 returns at ৳70 | −৳140 |
| | − Payment gateway fees · bKash 1.85% on ৳3,670 | −৳68 |
| | − Packaging · 5 parcels | −৳175 |
| | **= Profit after delivery** | **৳4,457** |
| **BLOCK 4 OF 5 — What went wrong** | *Money lost to things that did not work out. Watch this block — it is the one you can shrink.* | |
| | − Refunds to customers · ৳300 goodwill on TMB-26-00120 | −৳300 |
| | − Written-down returns · Foam Blaster X2 back from TMB-26-00122 at ৳790 instead of ৳1,050 | −৳260 |
| | − Damaged stock written off · `ADJ-2026-0012`, 2 × Plush Teddy Bear 45cm | −৳1,280 |
| | **= Profit after losses** | **৳2,617** |
| **BLOCK 5 OF 5 — What it cost to run the shop** | *Advertising is spread evenly across delivered orders, so it is an estimate. Everything above it is a recorded fact.* | |
| | − Advertising · Meta, ৳200/day × 7 | −৳1,400 |
| | − Other running costs · SMS credit top-up, 03 Nov | −৳500 |
| | **= NET PROFIT** · 5.2% of net revenue | **৳717** |

Every line in Blocks 1–4 is a `drill` row. "Product revenue" opens the order board filtered to the four delivered orders; "Courier delivery charges" opens the shipment list; "Damaged stock written off" opens `ADJ-2026-0012`.

**Rail card 1 — "Where the money actually is":**

| Row | Value |
|---|---|
| Received in your bank | ৳12,755 |
| Paid out of your bank | −৳2,200 |
| **Net cash movement** | **৳10,555** |
| Still with the courier, net of its charges | ৳787 |
| Owed back to a customer | −৳200 |

With the breakdown in `admin/caption` `color/text/tertiary`: "Received = ৳9,153 Pathao settlement `PTH-STL-2026-45` on 07 Nov (৳9,610 COD less ৳457 of its charges) + ৳3,602 of bKash payments net of fees. Paid out = ৳1,400 advertising + ৳500 SMS + ৳300 refund." And the double-count rule: *"Courier charges never leave your bank as a payment — they are deducted on the settlement statement, so they appear as expenses above and inside the net settlement here. They are counted once."*

**Rail card 2 — waterfall**, 5 bars: ৳13,910 → ৳5,700 → ৳4,457 → ৳2,617 → ৳717, with the drops labelled −৳8,210 · −৳1,243 · −৳1,840 · −৳1,900. The point of the card is the shape: net revenue of ৳13,910 ends as ৳717.

**Rail card 3 — reconciliation:**

| Row | Value |
|---|---|
| Sum of every order's net profit (7 orders with ledger activity) | ৳2,497 |
| − period costs not charged to any order · ৳1,280 write-off + ৳500 SMS | −৳1,780 |
| **= Net profit above** | **৳717** |

`check-circle` tinted `color/feedback/success` + **"Reconciled"**.

**"Not in this statement" footer:**

| Row | Value |
|---|---|
| Open pipeline — placed but not delivered · 1 shipped ৳2,570 + 3 ready to ship ৳7,260 + 2 pending ৳8,170 | ৳18,000 |
| Monthly internet and hosting — paid 01 Nov, outside this window | ৳1,200 |
| Stock on hand at cost — an asset, not a cost | ৳136,741 |

**768.** Statement Fill 736, line rows 44 tall, value column 104. Block headings keep their sub-lines. The waterfall rotates to horizontal bars. Rail cards stack below the statement, cash card first. **1024:** statement Fill 920, rail becomes a 3-up row of 293-wide cards below it.

---

### B8 · Stock movement ledger — `/admin/inventory/movements`

**1440 · content width 1144.** `StockMovementLedger` (A18), `scope: all`, `DateRangePicker` "Last 7 days · 02–08 Nov".

| Region | H | Contents |
|---|---|---|
| Content header | 72 | `admin/page-title` "Stock movements" · `admin/caption` `color/text/secondary` "Append-only. Stock changes only by a movement." · right: `Button outline md` "New adjustment", `Button ghost md` "Export" |
| Filter bar | 64 | date range · product/variant `Select` · type multi-`Select` · reference-type `NativeSelect` · user `Select` · Clear |
| Reconciliation strip | 56 | "Opening 210 units · in +26 · out −20 · closing **216 units**" · "Stock value at cost ৳136,741" · `check-circle` "Ledger balance matches `stock_levels` for all 10 variants" |
| `DataTable` | Hug | 19 rows, `compact` |

**All 19 rows in the window, newest last** (`balance_after` and `avg_cost_after` are read from the table, never recomputed client-side):

| When | Product / SKU | Type | Qty | Unit cost | Value | Balance after | Avg cost after | Reference | By |
|---|---|---|---|---|---|---|---|---|---|
| 03 Nov 9:12 am | Kids Tricycle `TMB-TRC-PH` | Sale out | −1 | ৳3,250 | −৳3,250 | 4 | ৳3,250.00 | TMB-26-00118 | Rina |
| 03 Nov 9:14 am | Fashion Doll Set `TMB-FDS-01` | Sale out | −1 | ৳1,480 | −৳1,480 | 6 | ৳1,480.00 | TMB-26-00119 | Rina |
| 03 Nov 9:14 am | Speed Cube 3×3 `TMB-SPC-33` | Sale out | −2 | ৳145 | −৳290 | 60 | ৳145.00 | TMB-26-00119 | Rina |
| 04 Nov 10:40 am | Brick Box 500 `TMB-BRK-500-CL` | Sale out | −2 | ৳1,120 | −৳2,240 | 22 | ৳1,120.00 | TMB-26-00120 | Rina |
| 04 Nov 10:40 am | Wooden Alphabet Puzzle `TMB-WAP-BN` | Sale out | −1 | ৳310 | −৳310 | 8 | ৳310.00 | TMB-26-00120 | Rina |
| 04 Nov 11:02 am | Baby Activity Gym Mat `TMB-BAG-01` | Sale out | −1 | ৳1,290 | −৳1,290 | 10 | ৳1,290.00 | TMB-26-00121 | Rina |
| 05 Nov 11:20 am | Foam Blaster X2 `TMB-FBL-X2` | **Purchase in** | **+24** | ৳1,060 | +৳25,440 | 27 | **৳1,058.8889** | `PO-2026-0041` | Mominul |
| 05 Nov 2:05 pm | Plush Teddy Bear 45cm `TMB-PTB-45` | Sale out | −1 | ৳640 | −৳640 | 30 | ৳640.00 | TMB-26-00122 | Rina |
| 05 Nov 2:05 pm | Foam Blaster X2 `TMB-FBL-X2` | Sale out | −1 | **৳1,050** | −৳1,050 | 26 | ৳1,058.8889 | TMB-26-00122 | Rina |
| 06 Nov 4:30 pm | Plush Teddy Bear 45cm `TMB-PTB-45` | **Damage out** | **−2** | ৳640 | −৳1,280 | 28 | ৳640.00 | `ADJ-2026-0012` | Mominul |
| 07 Nov 9:50 am | RC Racing Car 1:18 `TMB-RCC-118` | Sale out | −1 | ৳860 | −৳860 | 17 | ৳860.00 | TMB-26-00123 | Rina |
| 07 Nov 9:50 am | Magnetic Drawing Board `TMB-MDB-A4` | Sale out | −2 | ৳215 | −৳430 | 38 | ৳215.00 | TMB-26-00123 | Rina |
| 08 Nov 8:35 am | Brick Box 500 `TMB-BRK-500-CL` | Sale out | −1 | ৳1,120 | −৳1,120 | 21 | ৳1,120.00 | TMB-26-00124 | Rina |
| 08 Nov 8:40 am | Baby Activity Gym Mat `TMB-BAG-01` | Sale out | −1 | ৳1,290 | −৳1,290 | 9 | ৳1,290.00 | TMB-26-00129 | Rina |
| 08 Nov 8:40 am | Speed Cube 3×3 `TMB-SPC-33` | Sale out | −1 | ৳145 | −৳145 | 59 | ৳145.00 | TMB-26-00129 | Rina |
| 08 Nov 8:50 am | Fashion Doll Set `TMB-FDS-01` | Sale out | −1 | ৳1,480 | −৳1,480 | 5 | ৳1,480.00 | TMB-26-00125 | Rina |
| 08 Nov 8:50 am | Speed Cube 3×3 `TMB-SPC-33` | Sale out | −1 | ৳145 | −৳145 | 58 | ৳145.00 | TMB-26-00125 | Rina |
| 08 Nov 12:15 pm | Baby Activity Gym Mat `TMB-BAG-01` | **Courier return in** | **+1** | ৳1,290 | +৳1,290 | 10 | ৳1,290.0000 | TMB-26-00121 | Pathao webhook |
| 08 Nov 12:20 pm | Foam Blaster X2 `TMB-FBL-X2` | **Courier return in** | **+1** | **৳790** | +৳790 | 27 | **৳1,048.9301** | TMB-26-00122 | Rina |

Three rows carry a note that the Note column truncates and the tooltip gives in full:
- `PO-2026-0041` — "Received from Chittagong Toy Imports. Landed unit cost ৳1,060 = ৳1,010 unit + ৳50 freight share (৳1,200 inbound freight ÷ 24 units)."
- `ADJ-2026-0012` — "Water damage in storage, reason `damage`. Approved by Mominul Islam. Written off at ৳640 each."
- TMB-26-00122 return — "Blister pack torn in transit. Restocked as open-box at ৳790, a ৳260 write-down against the ৳1,050 snapshot cost."

**The two rows that justify the whole screen.** On 05 Nov the Foam Blaster's weighted average moved to ৳1,058.8889 when the purchase landed at 11:20 am, yet the 2:05 pm sale on TMB-26-00122 went out at **৳1,050** — the `cost_price_snapshot` taken when the order was *placed* on 04 Nov. Without that snapshot column, receiving a purchase would silently rewrite the profit on an order made the day before. The screen shows both numbers in the same row so the difference is visible rather than mysterious.

**Closing position table**, a second card below (W 1144, `DataTable compact`, columns Product 272 · Opening 88 · In 72 · Out 72 · On hand 88 · Reserved 88 · Available 88 · Reorder point 104 · Avg cost 120 · Value 152):

| Product | Opening | In | Out | On hand | Reserved | Available | Reorder pt | Avg cost | Value |
|---|---|---|---|---|---|---|---|---|---|
| Brick Box 500 / Classic | 24 | 0 | 3 | 21 | 0 | 21 | 10 | ৳1,120.0000 | ৳23,520 |
| RC Racing Car 1:18 | 18 | 0 | 1 | 17 | 0 | 17 | 8 | ৳860.0000 | ৳14,620 |
| Wooden Alphabet Puzzle BN+EN | 9 | 0 | 1 | 8 | 2 | **6** low | 12 | ৳310.0000 | ৳2,480 |
| Plush Teddy Bear 45cm | 31 | 0 | 3 | 28 | 1 | 27 | 10 | ৳640.0000 | ৳17,920 |
| Fashion Doll Deluxe Set | 7 | 0 | 2 | 5 | 0 | **5** low | 6 | ৳1,480.0000 | ৳7,400 |
| Kids Tricycle with Push Handle | 5 | 0 | 1 | 4 | 1 | **3** low | 4 | ৳3,250.0000 | ৳13,000 |
| Magnetic Drawing Board A4 | 40 | 0 | 2 | 38 | 1 | 37 | 15 | ৳215.0000 | ৳8,170 |
| Speed Cube 3×3 | 62 | 0 | 4 | 58 | 0 | 58 | 20 | ৳145.0000 | ৳8,410 |
| Baby Activity Gym Mat | 11 | 1 | 2 | 10 | 0 | 10 | 5 | ৳1,290.0000 | ৳12,900 |
| Foam Blaster X2 | 3 | 25 | 1 | 27 | 0 | 27 | 8 | ৳1,048.9301 | ৳28,321 |
| **Total** | **210** | **26** | **20** | **216** | **5** | **211** | | | **৳136,741** |

Three rows carry the low-stock chip (available ≤ reorder point): Wooden Alphabet Puzzle, Fashion Doll Deluxe Set, Kids Tricycle — matching the dashboard's "Low stock 3". Zero rows are out of stock.

**768.** Filter bar becomes a `Button outline` "Filters (2)" opening a bottom sheet. Ledger rows become cards: line 1 type chip + signed qty + "→ 27"; line 2 product + SKU; line 3 timestamp + reference + actor. The reconciliation strip becomes a 2-row block above the list. The closing-position table becomes a card list sorted low-stock-first. **1024:** both tables scroll horizontally with When/Product and Product frozen respectively.

### B9 · Media library — `/admin/store/media` (route proposed)

Added 2026-11-12. Sidebar **Online store** active; breadcrumb `Store / Online store / Media library`. Figma: page `07`, section `15 · Media library` (`296:46898`, Theme A) and section `14` → `B · Media library` (`303:53182`, Theme B — the one to build from).

**Layout at 1440** — content 1144 = folder rail 240 + gap 24 + grid 512 (3 × 160 tiles, gap 16) + gap 24 + detail panel 344. Without the panel (list view, upload, states) the grid is 880 and holds 5 tiles a row.

| Region | Content |
|---|---|
| `ContentHeader` | "Media library" · "1,262 files · 3.2 GB of 10 GB used · 37 images missing Bangla alt text" · New folder (Outline) · Upload files (Primary) |
| Toolbar | `SearchField` (filename, alt text in both languages, product name) · type `AdminSelect` · sort `AdminSelect` · Grid / List `SegmentedControl` |
| Rail (cards) | Folders: All media, Products, Category banners, Blog, Pages, Unused (+ New folder) · Type: Images (1,198), PDFs & certificates (64) — All media 1,262 = Images + PDFs; video deferred past v1 · Needs attention: Missing Bangla alt, Missing English alt (Warning) · Storage meter (3.2 GB of 10 GB, originals vs renditions) |
| Grid | `MediaTile`s, cursor pagination ("Showing 15 of 846" + Load more — no page numbers) |
| Detail | `MediaDetailPanel` for the selected tile |

**Screens drawn** (Theme A id / Theme B id): grid + detail `296:46905` / `303:53185` · list view with `BulkActionBar` (Move to folder, Download .zip, Add alt text, Delete — "Delete skips files that are in use") and a `DataTable`-style table (file, alt chip, size, used in, uploaded by + Dhaka time) `297:47758` / `303:53979` · drag-and-drop overlay `298:48279` / `303:54740` · upload queue + "3 new images have no alt text" bar `298:49030` / `303:55296` · picker over Product edit `298:49917` / `303:55922` · empty library (`AdminEmptyState` NoDataYet) `300:50828` / `303:57286` · no search results (echoes filters, offers to widen) `300:51309` / `303:57572` · delete blocked dialog `300:51914` / `303:57984` · Admin Dark sample `303:58825` · 1024 `301:52247` (collapsed sidebar, rail 200, 4-up grid, detail becomes an overlay drawer over a scrim) · 768 `301:52924` (`Topbar / Tablet`, folder and type as selects, attention banner, 4 × 172 tiles, bottom selection bar with Copy URL + Details).

---

## Part C — Sample dataset and its arithmetic

One dataset. Every figure in Parts A and B comes from it, and the sums below are stated so they can be checked.

**Window:** 02–08 Nov 2026 inclusive, Asia/Dhaka (`2026-11-02 00:00:00+06` → `2026-11-08 23:59:59+06`) — the `Last 7 days` preset at the 08 Nov 2026 2:30 pm screen clock. **Previous period:** 26 Oct – 01 Nov 2026.

### C1 · Catalogue (the 10 variants transacted in the window)

| Code | Product / variant | SKU | Category | Price | Cost (avg at window open) | Weight |
|---|---|---|---|---|---|---|
| P1 | Brick Box 500 / Classic | `TMB-BRK-500-CL` | Building & Construction | ৳1,850 | ৳1,120 | 1,400 g |
| P2 | RC Racing Car 1:18 | `TMB-RCC-118` | Vehicles & RC | ৳1,450 | ৳860 | 900 g |
| P3 | Wooden Alphabet Puzzle BN+EN | `TMB-WAP-BN` | Educational & Puzzles | ৳650 | ৳310 | 700 g |
| P4 | Plush Teddy Bear 45cm | `TMB-PTB-45` | Soft Toys | ৳1,200 | ৳640 | 500 g |
| P5 | Fashion Doll Deluxe Set | `TMB-FDS-01` | Dolls & Figures | ৳2,350 | ৳1,480 | 800 g |
| P6 | Kids Tricycle with Push Handle | `TMB-TRC-PH` | Ride-ons & Outdoor | ৳4,900 | ৳3,250 | 5,200 g |
| P7 | Magnetic Drawing Board A4 | `TMB-MDB-A4` | Educational & Puzzles | ৳480 | ৳215 | 450 g |
| P8 | Speed Cube 3×3 | `TMB-SPC-33` | Educational & Puzzles | ৳320 | ৳145 | 120 g |
| P9 | Baby Activity Gym Mat | `TMB-BAG-01` | Baby & Toddler | ৳2,100 | ৳1,290 | 2,800 g |
| P10 | Foam Blaster X2 | `TMB-FBL-X2` | Action & Blasters | ৳1,750 | ৳1,050 | 1,100 g |

Brick Box 500 has 4 variants (Part B4); only `500 / Classic` transacted in the window, and the stock figures in C7 are that variant's. Product-level stock and value in B4 (31 units, ৳39,200) therefore exceed the ledger's 21 units for this variant, which is correct, not a mismatch.

### C2 · Delivery-charge rule (Pathao, the live provider)

Base: inside Dhaka metro **৳60** · Dhaka suburb **৳110** · outside Dhaka **৳140**. Included weight 1,000 g; **+৳20 per additional kilogram, rounded up**. COD fee **1%** of the COD amount, rounded to the nearest taka per shipment. Return charge **৳70**. Steadfast is configured as a driver with no credentials: base ৳60/৳100/৳130, +৳20/kg, COD 1%, return ৳65 — all unverified, and the reason for the B6 banner.

### C3 · Customers

| Customer | Phone | Masked | District / zone | Prior orders before 02 Nov | Risk at placement |
|---|---|---|---|---|---|
| Jahangir Alam | `01711258046` | 01711•••046 | Dhaka, Dhanmondi · metro | 5, all delivered | **5** low |
| Farhana Yasmin | `01777902615` | 01777•••615 | Dhaka, Uttara · metro | 3, all delivered | **0** low |
| Md. Rakibul Hasan | `01919774052` | 01919•••052 | Rajshahi, Boalia · outside | 4: 3 delivered, 1 cancelled | **24** low |
| Nusrat Jahan | `01841523907` | 01841•••907 | Cumilla, Kotwali · outside | 2: 1 delivered, 1 cancelled, 1 failed attempt | **23** low |
| Tanzila Akter | `01533619480` | 01533•••480 | Dhaka, Savar · suburb | 0 | **10** low on TMB-26-00122 (first-order signal); **0** on TMB-26-00128, by then not a first order and with no other signal triggered |
| Imran Hossain | `01311487203` | 01311•••203 | Sylhet · outside | 3: 2 delivered, 1 failed attempt then delivered | **5** low |
| Sumaiya Islam | `01985164739` | 01985•••739 | Dhaka, Mirpur 10 · metro | 0 | **10** low (first order) |
| Abdullah Al Mamun | `01625338174` | 01625•••174 | Khulna · outside | 6: 4 delivered, 1 cancelled, 1 returned, 1 failed attempt | **40** medium |
| Shahriar Kabir | `01712046318` | 01712•••318 | Dhaka, Bashundhara · metro | 7: 1 delivered, 4 cancelled, 2 returned, 3 failed attempts | **80** high |
| Rifat Chowdhury | `01876045912` | 01876•••912 | Dhaka, Keraniganj · suburb | 0 | **10** low (first order) |
| Mehedi Hasan | `01398215467` | 01398•••467 | Dhaka, Mohammadpur · metro | 0 | **10** low (first order) |

11 customers, 12 orders (Tanzila Akter placed two). All prefixes are 013–019; `phone_verified_at` is set for all but Mehedi Hasan. 8 of the 11 placed their first-ever order in the window → **new customers 8**. Store total at 08 Nov: **184**, up from 176.

**Risk model as displayed** (§5.2 weights, with the cap and maturity rules the UI states): rate signals score `weight × min(rate ÷ cap, 1)` with cap 30% for cancellations and 20% for returns; failed attempts score `15 × min(attempts ÷ 3, 1)`; with fewer than 3 prior orders rate signals carry half weight; first-ever order is a flat 10; "value far above norm" scores `10 × min(max(ratio − 1.5, 0) ÷ 1.5, 1)` against the phone's own average (store average for a first order); address quality, rapid duplicates and an invalid prefix are flat 5 each. Totals rounded, clamped 0–100, thresholds 0–39 low / 40–69 medium / 70–100 high.

Worked examples: **Shahriar Kabir** 25 + 25 + 15 + 0 + 10 + 5 + 0 + 0 = **80**. **Abdullah Al Mamun** cancellations 1/6 = 16.7% → 25 × 0.5556 = 13.89; returns 1/6 = 16.7% → 25 × 0.8333 = 20.83; failed attempts 1 → 15 × 0.3333 = 5.00; value 1.34× → 0 ⇒ 39.72 → **40**, exactly at the medium threshold, which is why ৳500 was taken in advance. **Nusrat Jahan** cancellations 1/2 = 50% → 25, halved to 12.5 for having 2 priors; failed attempts 1 → 5; address "Kotwali, Cumilla" with no holding or road → 5 ⇒ 22.5 → **23** low — and she refused the parcel anyway. The screen says so: the score is advisory, and it produces false negatives.

**Courier-verified data: none.** `courier_fraud_lookups` is empty for all 11 phones because there is no Steadfast merchant account. Every `RiskScoreCard` Panel B in this dataset is in the `not-connected` state.

### C4 · The twelve orders

| # | Order | Customer | Zone | Items | Subtotal | Discount | Weight | Delivery | Grand total | Paid | Due | `status` | `payment_status` | `fulfillment_status` | Pay method | Risk | Placed (Dhaka) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | TMB-26-00118 | Jahangir Alam | metro | P6 ×1 | ৳4,900 | — | 5,200 g | ৳160 | **৳5,060** | ৳5,060 | ৳0 | `delivered` | `paid` | `fulfilled` | COD | 5 | 02 Nov 10:42 am |
| 2 | TMB-26-00119 | Farhana Yasmin | metro | P5 ×1, P8 ×2 | ৳2,990 | ৳100 | 1,040 g | ৳80 | **৳2,970** | ৳2,970 | ৳0 | `delivered` | `paid` | `fulfilled` | bKash | 0 | 02 Nov 3:18 pm |
| 3 | TMB-26-00120 | Md. Rakibul Hasan | outside | P1 ×2, P3 ×1 | ৳4,350 | — | 3,500 g | ৳200 | **৳4,550** | ৳4,550 | ৳0 | `delivered` | `partially_refunded` | `fulfilled` | COD | 24 | 03 Nov 11:05 am |
| 4 | TMB-26-00121 | Nusrat Jahan | outside | P9 ×1 | ৳2,100 | — | 2,800 g | ৳180 | **৳2,280** | ৳0 | ৳2,280 | `returned` | `unpaid` | `returned` | COD | 23 | 03 Nov 7:33 pm |
| 5 | TMB-26-00122 | Tanzila Akter | suburb | P4 ×1, P10 ×1 | ৳2,950 | — | 1,600 g | ৳130 | **৳3,080** | ৳1,330 | ৳1,750 | `partially_returned` | `partially_paid` | `partially_fulfilled` | COD | 10 | 04 Nov 9:27 am |
| 6 | TMB-26-00123 | Imran Hossain | outside | P2 ×1, P7 ×2 | ৳2,410 | — | 1,800 g | ৳160 | **৳2,570** | ৳0 | ৳2,570 | `shipped` | `unpaid` | `fulfilled` | COD | 5 | 05 Nov 2:52 pm |
| 7 | TMB-26-00124 | Sumaiya Islam | metro | P1 ×1 | ৳1,850 | — | 1,400 g | ৳80 | **৳1,930** | ৳0 | ৳1,930 | `ready_to_ship` | `unpaid` | `unfulfilled` | COD | 10 | 06 Nov 12:36 pm |
| 8 | TMB-26-00125 | Abdullah Al Mamun | outside | P5 ×1, P8 ×1 | ৳2,670 | — | 920 g | ৳140 | **৳2,810** | ৳500 | ৳2,310 | `ready_to_ship` | `partially_paid` | `unfulfilled` | COD + bKash advance | 40 | 06 Nov 5:49 pm |
| 9 | TMB-26-00126 | Shahriar Kabir | metro | P6 ×1, P4 ×1 | ৳6,100 | — | 5,700 g | ৳160 | **৳6,260** | ৳0 | ৳6,260 | `pending` | `unpaid` | `unfulfilled` | COD | **80** | 07 Nov 8:08 pm |
| 10 | TMB-26-00127 | Rifat Chowdhury | suburb | P3 ×2, P7 ×1 | ৳1,780 | — | 1,850 g | ৳130 | **৳1,910** | ৳0 | ৳1,910 | `pending` | `unpaid` | `unfulfilled` | COD | 10 | 08 Nov 10:15 am |
| 11 | TMB-26-00128 | Tanzila Akter | suburb | P10 ×1 | ৳1,750 | — | 1,100 g | ৳130 | **৳1,880** | ৳200 | ৳1,680 | `cancelled` | `partially_paid` | `unfulfilled` | bKash advance | 0 | 05 Nov 9:14 pm |
| 12 | TMB-26-00129 | Mehedi Hasan | metro | P9 ×1, P8 ×1 | ৳2,420 | — | 2,920 g | ৳100 | **৳2,520** | ৳0 | ৳2,520 | `ready_to_ship` | `unpaid` | `unfulfilled` | COD | 10 | 07 Nov 1:22 pm |

**Delivery charges check:** 00118 metro 5,200 g → ৳60 + ⌈4.2⌉ × ৳20 = ৳160. 00119 metro 1,040 g → ৳60 + ৳20 = ৳80. 00120 outside 3,500 g → ৳140 + ৳60 = ৳200. 00121 outside 2,800 g → ৳140 + ৳40 = ৳180. 00122 suburb 1,600 g → ৳110 + ৳20 = ৳130. 00123 outside 1,800 g → ৳140 + ৳20 = ৳160. 00124 metro 1,400 g → ৳60 + ৳20 = ৳80. 00125 outside 920 g → ৳140 + ৳0 = ৳140. 00126 metro 5,700 g → ৳60 + ৳100 = ৳160. 00127 suburb 1,850 g → ৳110 + ৳20 = ৳130. 00128 suburb 1,100 g → ৳110 + ৳20 = ৳130. 00129 metro 2,920 g → ৳60 + ৳40 = ৳100. **Total ৳1,520.**

**Gross order value:** 5,060 + 2,970 + 4,550 + 2,280 + 3,080 + 2,570 + 1,930 + 2,810 + 6,260 + 1,910 + 1,880 + 2,520 = **৳37,820**. AOV = 37,820 ÷ 12 = ৳3,151.67 → **৳3,152**.
**Status counts:** pending 2 · confirmed 0 · processing 0 · ready_to_ship 3 · shipped 1 · delivered 3 · completed 0 · partially_returned 1 · returned 1 · cancelled 1 · on_hold 0 = **12**.
**Item value excluding the cancelled order:** 37,820 − 1,880 + 100 (coupon added back) − 1,520 (delivery) = **৳34,520**, which is the donut total and the sum of the per-category figures in B1.
**Daily order value:** 02 Nov ৳8,030 · 03 Nov ৳6,830 · 04 Nov ৳3,080 · 05 Nov ৳4,450 · 06 Nov ৳4,740 · 07 Nov ৳8,780 · 08 Nov ৳1,910 = **৳37,820**.
**The one anomaly:** TMB-26-00128 is `cancelled` with `paid_total ৳200 > refunded_total ৳0`, three days old. Every other row's three columns match the A12 expected-pairing table.

### C5 · Shipments

| Shipment | Order | Provider | Consignment | Weight | COD | Charge | COD fee | Return charge | Total cost | State |
|---|---|---|---|---|---|---|---|---|---|---|
| SHP-26-00091 | TMB-26-00118 | Pathao | `DT261103RQ7K2M` | 5,200 g | ৳5,060 | ৳160 | ৳51 | — | ৳211 | delivered 04 Nov 1:20 pm |
| SHP-26-00092 | TMB-26-00119 | Pathao | `DT261103YV4B8X` | 1,040 g | ৳0 | ৳80 | ৳0 | — | ৳80 | delivered 04 Nov 4:05 pm |
| SHP-26-00093 | TMB-26-00120 | Pathao | `DT261104KM9T3P` | 3,500 g | ৳4,550 | ৳200 | ৳46 | — | ৳246 | delivered 06 Nov 12:40 pm |
| SHP-26-00094 | TMB-26-00121 | Pathao | `DT261104ZC6H1W` | 2,800 g | ৳2,280 | ৳180 | ৳0 | ৳70 | ৳250 | refused 06 Nov, returned 08 Nov 12:15 pm |
| SHP-26-00095 | TMB-26-00122 | Pathao | `DT261105NB8L5R` | 1,600 g | ৳1,330 | ৳130 | ৳13 | ৳70 | ৳213 | partially delivered 07 Nov 11:48 am |
| SHP-26-00096 | TMB-26-00123 | Pathao | `DT261107FJ2D9Q` | 1,800 g | ৳2,570 | ৳160 | — | — | — | in transit, booked 06 Nov 10:12 am |
| SHP-26-00097 | TMB-26-00124 | Pathao | **not issued yet** | 1,400 g | ৳1,930 | ৳80 est. | ৳19 est. | — | ৳99 est. | **submitted** 08 Nov 9:05 am, batch `BULK-2026-1108-01` |
| SHP-26-00098 | TMB-26-00129 | Pathao | **not issued yet** | 2,920 g | ৳2,520 | ৳100 est. | ৳25 est. | — | ৳125 est. | **submitted** 08 Nov 9:05 am, batch `BULK-2026-1108-01` |

COD fees: 1% of ৳5,060 = ৳50.60 → ৳51 · 1% of ৳4,550 = ৳45.50 → ৳46 · 1% of ৳1,330 = ৳13.30 → ৳13. SHP-26-00096's charges are not recognised yet because the order is not delivered.

### C6 · Payments, refunds, settlement

| Payment | Order | Method | Amount | Gateway fee (1.85%) | Net | When |
|---|---|---|---|---|---|---|
| PAY-0231 | TMB-26-00119 | bKash | ৳2,970 | ৳55 | ৳2,915 | 02 Nov 3:26 pm |
| PAY-0238 | TMB-26-00128 | bKash advance | ৳200 | ৳4 | ৳196 | 05 Nov 9:16 pm |
| PAY-0242 | TMB-26-00125 | bKash advance | ৳500 | ৳9 | ৳491 | 07 Nov 10:04 am |
| PAY-0233 | TMB-26-00118 | COD | ৳5,060 | — | — | collected 04 Nov 1:20 pm |
| PAY-0236 | TMB-26-00120 | COD | ৳4,550 | — | — | collected 06 Nov 12:40 pm |
| PAY-0240 | TMB-26-00122 | COD, partial | ৳1,330 | — | — | collected 07 Nov 11:48 am |

bKash total ৳3,670, fees ৳55 + ৳4 + ৳9 = **৳68**, net **৳3,602**. COD collected ৳5,060 + ৳4,550 + ৳1,330 = **৳10,940**, fees **৳110**.

**Refunds** — REF-0019: ৳300 goodwill on TMB-26-00120 (one Brick Box arrived with a crushed outer box, no restock), approved and processed 08 Nov 11:30 am by bKash. REF-0020: ৳200 on TMB-26-00128, **requested 05 Nov 9:45 pm, still pending approval** — the board's one anomaly.

**Settlement** — `PTH-STL-2026-45`, Pathao, period 02–06 Nov, received 07 Nov 2026: COD collected ৳9,610 (SHP-26-00091 ৳5,060 + SHP-26-00093 ৳4,550) · delivery charges ৳360 · COD fees ৳97 · return charges ৳0 · **net received ৳9,153**. Status `reconciled`, 2 items matched, 0 variances. SHP-26-00095's ৳1,330 and the ৳463 of charges on SHP-26-00092/94/95 fall into the next cycle.

**Expenses** — Meta ads ৳200/day × 7 = ৳1,400 (`marketing`) · SMS credit top-up ৳500 on 03 Nov (`operating`) · internet and hosting ৳1,200 paid 01 Nov, **outside the window** · packaging materials ৳1,400 bought 28 Oct, consumed per order at ৳25 (৳50 oversize).

### C7 · Stock

Opening **210 units** across the 10 variants, closing **216**: in +26 (24 purchase + 2 courier returns), out −20 (18 sales + 2 damaged). `PO-2026-0041` from Chittagong Toy Imports received 05 Nov: 24 × Foam Blaster X2 at ৳1,010 unit + ৳1,200 inbound freight ⇒ landed ৳1,060 each. `ADJ-2026-0012` on 06 Nov: 2 × Plush Teddy Bear 45cm `damage_out` at ৳640 = **৳1,280** written off. The 19 movements, balances and running average costs are in B8, and the closing position table there totals **216 on hand, 5 reserved, 211 available, ৳136,741 at cost**. Low stock (available ≤ reorder point): **3**. Out of stock: **0**.

### C8 · The P&L, with every term shown

Revenue is recognised on delivery (§4.3), so only TMB-26-00118, 00119, 00120 and the delivered line of 00122 produce revenue in this window.

```
Product revenue       4,900 + 2,990 + 4,350 + 1,200                    =  13,440
Delivery collected      160 +    80 +   200 +   130                    =     570
Gross revenue                                                             14,010
Discounts             WELCOME100 on TMB-26-00119                       =     100
NET REVENUE                                                               13,910

COGS                  3,250 + (1,480 + 2×145) + (2×1,120 + 310) + 640
                    = 3,250 +  1,770          +  2,550          + 640  =   8,210
GROSS PROFIT          13,910 − 8,210                                   =   5,700   (41.0%)

Courier charges       160 + 80 + 200 + 130 + 180 (the refused parcel)  =     750
COD fees               51 +  0 +  46 +  13                             =     110
Return charges         70 (TMB-26-00121) + 70 (TMB-26-00122)           =     140
Gateway fees           55 +  4 +   9                                   =      68
Packaging              50 + 25 +  25 +  25 +  50                       =     175
                                                                           1,243
PROFIT AFTER DELIVERY 5,700 − 1,243                                    =   4,457

Refunds               300 (TMB-26-00120)                               =     300
Return write-down     1,050 − 790 on the Foam Blaster X2               =     260
Stock write-off       2 × 640 (ADJ-2026-0012)                          =   1,280
                                                                           1,840
PROFIT AFTER LOSSES   4,457 − 1,840                                    =   2,617

Advertising           200 × 7                                          =   1,400
Other running costs   SMS top-up                                       =     500
                                                                           1,900
NET PROFIT            2,617 − 1,900                                    =     717   (5.2%)
```

**Per-order net profit** (§5.4, with ৳1,400 of advertising split evenly across the 4 orders that delivered something = **৳350** each):

| Order | Revenue | − Disc. | − COGS | + Delivery | − Courier | − COD fee | − Return | − Gateway | − Packaging | − Refund | − Write-down | − Ads | **Net** |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 00118 | 4,900 | — | 3,250 | 160 | 160 | 51 | — | — | 50 | — | — | 350 | **+1,199** |
| 00119 | 2,990 | 100 | 1,770 | 80 | 80 | — | — | 55 | 25 | — | — | 350 | **+690** |
| 00120 | 4,350 | — | 2,550 | 200 | 200 | 46 | — | — | 25 | 300 | — | 350 | **+1,079** |
| 00121 | — | — | — | — | 180 | — | 70 | — | 50 | — | — | — | **−300** |
| 00122 | 1,200 | — | 640 | 130 | 130 | 13 | 70 | — | 25 | — | 260 | 350 | **−158** |
| 00125 | — | — | — | — | — | — | — | 9 | — | — | — | — | **−9** |
| 00128 | — | — | — | — | — | — | — | 4 | — | — | — | — | **−4** |
| | | | | | | | | | | | | **Σ** | **+2,497** |

**Reconciliation.** ৳2,497 − ৳1,780 of period costs charged to no order (৳1,280 write-off + ৳500 SMS) = **৳717**, the statement's net profit. ✓

**Cash.** In: ৳9,153 settlement + ৳2,915 + ৳491 + ৳196 bKash = **৳12,755**. Out: ৳1,400 ads + ৳500 SMS + ৳300 refund = **৳2,200**. **Net cash movement +৳10,555.**
Reconciling cash to the ৳717 accrual profit: + ৳8,210 COGS (goods paid for in earlier periods) + ৳1,280 write-off (no cash) + ৳175 packaging (materials bought 28 Oct) + ৳260 write-down (no cash) + ৳543 of courier charges recognised but not yet netted by a settlement (৳80 on SHP-26-00092, ৳250 on 00094, ৳213 on 00095) − ৳1,330 COD collected by the courier and not remitted + ৳500 + ৳200 customer advances on orders that produced no revenue = **৳10,555**. ✓
**Still with the courier:** ৳1,330 collected less ৳543 of charges it will net = **৳787**. **Owed back to a customer:** ৳200.

**Open pipeline not in the statement:** shipped ৳2,570 + ready to ship (৳1,930 + ৳2,810 + ৳2,520 = ৳7,260) + pending (৳6,260 + ৳1,910 = ৳8,170) = **৳18,000**, and ৳37,820 − ৳15,660 (the four orders that delivered) − ৳2,280 (returned) − ৳1,880 (cancelled) = ৳18,000. ✓

### C9 · Previous period, for the deltas

26 Oct – 01 Nov 2026: **9 orders · gross order value ৳27,450 · AOV ৳3,050 · net revenue ৳10,180 · net profit ৳512 · 5 new customers · 3 delivered, 2 cancelled, 1 returned · 17 units**. Daily order value ৳3,980 · ৳4,420 · ৳2,860 · ৳5,310 · ৳3,650 · ৳4,790 · ৳2,440 = ৳27,450. Deltas: orders +33.3% · order value +37.8% · net revenue +36.6% · net profit +40.0% · new customers +60.0% · AOV +3.3% · customer total +4.5%.

---

## Open questions for the lead

1. **`spacing/7` does not exist** in the token set (the scale skips 7, 9, 11, 13–15, 17–19, 21–23). Every geometry above uses only `spacing/1`–`spacing/6`, `spacing/8` and `spacing/12`. If Figma variables are created for the missing steps, nothing here needs to change; if not, do not interpolate.
2. **`color/feedback/*` have no `-subtle` companions.** Every status treatment above therefore fills with `color/bg/subtle` and tints only icons and 2px edges. Adding `color/feedback/success-subtle` etc. would let chips carry tinted fills and would simplify A12; it is a token-set decision, not a component one.
3. **The admin type tokens in Part 0 §5 are new** (`admin/page-title` … `admin/mono`). They are overrides of §3.2, not replacements. They need to exist as Figma text styles bound to the same families before A1 is built.
4. **Steadfast credentials.** Everything in A14 Panel B and the B6 banner is written for the state the business is actually in. When a merchant account exists, Panel B's `fresh` / `stale` / `scoring-disabled` states activate and the B6 banner disappears on its own; no redesign is needed.
5. ~~**Media library video limits**~~ — **Resolved 2026-11-12 (owner): no video in v1.** Video is deferred past v1; the Videos filter, the `MediaTile` Video variants and the sample video tile were removed from Figma. Revisit formats, size and duration caps when video is scheduled.
5a. ~~**Upload size limit (A8 said 5 MB, the API allows 10 MB)**~~ — **Resolved 2026-11-12 (owner): 10 MB everywhere**, matching `StoreProductImageRequest` `max:10240`. A8, the `ImageUploader` component and its screen instances now say 10 MB.
6. **Media library folders** (2026-11-12) are drawn as flat, user-creatable folders plus smart filters (Unused, Missing alt). Whether an asset may live in more than one folder, and whether folders can nest, is not decided.
