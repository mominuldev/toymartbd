# @toymart/tokens

Design tokens, exported from the Figma library and consumed by both frontends.

**Figma is the source of truth.** `src/figma-variables.json` is a snapshot of the variable
collections, effect styles and admin text styles in
`https://www.figma.com/design/PWZp5VzEgEEf5N2Y38lGIh/Toy-Mart`. Do not hand-edit a value to fix a
visual bug: change it in Figma, re-export, run `npm run tokens:build`, and commit the diff.

## Outputs (committed; CI runs `npm run check --workspace=@toymart/tokens` to catch stale files)

| File | What |
|---|---|
| `dist/tokens.css` | Runtime custom properties `--tm-*`: palette, spacing, radius, fonts, motion, then one block per theme, plus the `.t-*` type classes |
| `dist/tailwind.css` | Tailwind v4 `@theme` that maps utilities onto the runtime properties, and a `dark:` variant keyed on `data-theme` |
| `dist/tokens.json` | Every colour resolved to hex, per theme — for tests and charts |
| `src/generated.ts` | `ColorToken` union and resolved values; `src/index.ts` adds `themes` and `cssVar()` |

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
| Elevation | `shadow-e1`…`shadow-e4`, `shadow-focus` | per theme |
| Radius | `rounded-xs`…`rounded-2xl`, `rounded-full` | Figma `radius/*` |
| Spacing | the Tailwind scale (4px step = Figma `space/*`) plus `gutter-*`, `section-*`, `control-*`, `touch-min`, `icon-*` | `h-control-md`, `px-gutter-desktop` |
| Fonts | `font-sans`, `font-display-latin`, `font-bangla`, `font-display-bangla`, `font-mono` | |

Tailwind's default palette is removed (`--color-*: initial`), so `bg-white` or `text-neutral-900`
does not compile — a component can only use semantic tokens (CLAUDE.md rule 26).

## Typography

Use the `.t-*` classes, not ad-hoc sizes: `.t-admin-page-title`, `.t-admin-body`, `.t-admin-num`,
`.t-admin-mono` … for the admin; `.t-h1`, `.t-body`, `.t-price` … for the storefront (mobile size,
switching to desktop at `lg`). Each has a Bangla twin that applies under `:lang(bn)` or `.lang-bn`
with its own family and ~1.15× line-height (rule 38). Numeric and mono styles have no twin: numerals
stay Western in both locales (rule 36).

## Gates in the build

1. Every listed text-on-background pair meets WCAG 2.2 AA (4.5:1 text, 3:1 for strong borders and
   the focus ring) in all four themes. A failure stops the build. Currently 120 pairs pass.
2. `color/bg/accent` is surface-only and must never be paired with a text token other than
   `color/text/on-accent` (review rule; not yet linted).

## Not in Figma

Motion and breakpoints cannot be Figma variables, so `motion` and `breakpoints` in the snapshot come
from `docs/UI-UX-DESIGN-SPEC.md` §3.3 and §6.
