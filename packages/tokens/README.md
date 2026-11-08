# @toymart/tokens

Design tokens, exported from the Figma library and consumed by both frontends.

**Figma is the source of truth.** These files are generated from the variable collections in
`https://www.figma.com/design/PWZp5VzEgEEf5N2Y38lGIh/Toy-Mart` — do not hand-edit a value here to
fix a visual bug. Change it in Figma, re-export, and commit the diff.

Outputs:
- `dist/tailwind-preset.js` — the Tailwind v4 preset both apps extend
- `dist/tokens.css` — CSS custom properties on `:root`, with the dark-mode overrides
- `dist/tokens.json` — the raw token tree, for tests and for the contrast-check CI job

Collections mirror the Figma structure: `Primitives` (1 mode) · `Color` (Light/Dark) · `Spacing` ·
`Radius` · `Typography`.

Two rules the CI job enforces:
1. Every text-on-background pair meets WCAG 2.2 AA — 4.5:1 for body, 3:1 at 24px and above.
2. `color/bg/accent` is surface-only and must never be paired with a text token.
