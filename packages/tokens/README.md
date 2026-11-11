# @toymart/tokens

Design tokens, exported from the Figma library and consumed by both frontends.

**Figma is the source of truth.** `src/figma-variables.json` is a snapshot of the variable
collections, text styles, effect styles and paint styles in
`https://www.figma.com/design/PWZp5VzEgEEf5N2Y38lGIh/Toy-Mart`. Do not hand-edit a value to fix a
visual bug: change it in Figma, re-export, run `npm run tokens:build`, and commit the diff.

## Outputs (committed; CI runs `npm run check --workspace=@toymart/tokens` to catch stale files)

| File | What |
|---|---|
| `dist/tokens.css` | Runtime custom properties `--tm-*`: palette, spacing, border widths, radius, fonts, motion, gradients, effects, then one block per theme |
| `dist/tailwind.css` | Tailwind v4 `@theme` that maps utilities onto the runtime properties, a `dark:` variant keyed on `data-theme`, and the `t-*` type utilities (`@utility`, one per Figma text style) |
| `dist/tokens.json` | Every colour resolved to hex, per theme — for tests and charts |
| `src/generated.ts` | `ColorToken` union and resolved values, `radiusTokens`, `effectTokens`, `typeUtilities`; `src/index.ts` adds `themes` and `cssVar()` |

## Themes

The Figma `Color` collection has four modes; each is a `data-theme` value. Themes nest, because every
block sets its values directly.

| Figma mode | `data-theme` | Used by |
|---|---|---|
| Light | `light` (also `:root`) | Storefront |
| Dark | `dark` | Storefront dark |
| Admin Light | `admin-light` | Admin (Theme B, slate & indigo) |
| Admin Dark | `admin-dark` | Admin dark, and the admin sidebar in both admin themes |

## Utility names

| Figma group | Tailwind | Example |
|---|---|---|
| `color/bg/*` | `bg-*` (also usable as any colour) | `bg-surface`, `bg-brand`, `bg-danger-subtle` |
| `color/text/*` | `*-fg-*` | `text-fg-primary`, `text-fg-on-brand`, `text-fg-link` |
| `color/border/*` | `*-line-*` | `border-line-default`, `ring-line-focus` |
| `color/feedback/*`, `color/status/*` | `*-feedback-*`, `*-status-*` | `text-feedback-danger`, `text-status-low-stock` |
| `color/text/ink-*` | `text-fg-ink-{pink,butter,mint,sky,coral}` | label inks on pastel tiles (Light only passes AA today — see the contrast note below) |
| `color/swatch/*`, `color/bg/image` | `bg-swatch-teal`, `bg-image` | product colour swatches; the white well behind photography |
| `color/deco/*` | `*-deco-*` | `bg-deco-coral`, `fill-deco-sun`, `bg-deco-blob-mint` — decorative inks only, never text on a background |
| Elevation | `shadow-e1`…`shadow-e4`, `shadow-focus` | per theme |
| Effect styles | `shadow-gloss-button`, `shadow-glow-violet`, `shadow-glow-tile-{pink,butter,mint,sky,lavender}`, `shadow-up-nav`, `shadow-up-footer` | Figma `Gloss/*`, `Glow/*`, `Shadow/*`; theme-independent |
| Radius | `rounded-none`, `-3xs` 3, `-2xs` 4, `-xs` 6, `-xs-plus` 8, `-sm` 10, `-sm-plus` 12, `-md-minus` 14, `-md` 16, `-md-plus` 18, `-lg` 20, `-xl-minus` 22, `-lg-plus` 24, `-xl` 28, `-xl-plus` 32, `-2xl` 40, `-3xl` 48, `-full` | Figma `radius/*` |
| Border width | `border-(length:--tm-border-width-{hairline,control,strong,rim})` | 1 / 1.5 / 2 / 3 |
| Spacing | the Tailwind scale (4px step = Figma `space/*`) plus `gutter-*`, `section-*`, `control-*`, `touch-min`, `icon-*` | `h-control-md`, `px-gutter-desktop` |
| Fonts | `font-sans`, `font-display-latin`, `font-bangla`, `font-display-bangla`, `font-mono` | |

Tailwind's default palette is removed (`--color-*: initial`), so `bg-white` or `text-neutral-900`
does not compile — a component can only use semantic tokens (CLAUDE.md rule 26).

## Typography

Use the `t-*` utilities, not ad-hoc sizes. They are generated from the Figma text styles, one per
style: `Latin/Mobile/X` + `Latin/Desktop/X` become `t-X` (mobile size, desktop size from `lg`),
`Latin/<Group>/<Name>` becomes `t-<group>-<name>` (`t-badge`, `t-chip`, `t-price-current-card`;
`t-price--was` where a component style would shadow a scale style), and `Admin/X` becomes
`t-admin-X` (`Admin/currency/num` → `t-admin-currency-num`, the ৳ sign set in Fredoka). A Bangla
style with no Latin counterpart is its own utility: `Bangla/Inline/label` → `t-bn-inline-label`,
`Admin/bn/amount` → `t-admin-bn-amount`. Each has its Bangla twin (`Bangla/…`, `Admin/bn/…`) under `:lang(bn)` or `.lang-bn`,
with its own family and ~1.15× line-height (rule 38). Numeric and mono styles have no twin:
numerals stay Western in both locales (rule 36).

They are Tailwind `@utility` rules, so variants work. Where Figma switches style per breakpoint,
say so in the class list: `t-display-2 lg:t-display-1`, `t-body lg:t-body-lg`. A single-property
utility next to one (`t-body-sm font-semibold`) still wins, because the Bangla twin sits in `:where()`.

## Gates in the build

1. Every listed text-on-background pair meets WCAG 2.2 AA (4.5:1 text, 3:1 for strong borders and
   the focus ring) in all four themes. A failure stops the build. Currently 120 pairs pass.
2. `color/text/ink-*` are **not** in the gate yet. They alias the same 700 primitives in every mode, which
   is what the approved frames render, so in Dark they sit on dark tiles at ~1.5–2.6:1 and
   `ink-mint` on `tile-mint` is 4.44:1 in Light. Giving them Dark values (the `*/300` steps, as
   `color/feedback/*` does) is an owner decision; until then use them only in Light-only surfaces.
3. `color/bg/accent` is surface-only and must never be paired with a text token other than
   `color/text/on-accent` (review rule; not yet linted).

## Not in Figma

Motion and breakpoints cannot be Figma variables, so `motion` and `breakpoints` in the snapshot come
from `docs/UI-UX-DESIGN-SPEC.md` §3.3 and §6.
