# toymartbd-app

The **customer-facing storefront** for Toy Mart BD.

```
toymartbd-app/
└── storefront/          Next.js 16 App Router — the shop
```

## What is NOT here

- **The admin dashboard** lives inside Laravel at `../resources/js/admin/`, served from `/admin`
  (owner decision, 2026-11-08). It is a private tool behind a login that shares Laravel's session
  for auth, so hosting it in Laravel removes a deployment, a CORS boundary and a token-handling
  problem.
- **Shared packages** are at `../packages/` — `@toymart/tokens` and `@toymart/api-types` — because
  both frontends consume them, and one npm workspaces root at the repository root covers everything.
- **The Laravel 13 API** is at the repository root.

## Why the storefront stays separate

It has the opposite requirements to the admin. It must serve cached HTML from a CDN edge to
mid-range Android phones on 4G, because beating slow WooCommerce competitors on Core Web Vitals is
the whole organic-search thesis — argued in full, with its costs, in
`../docs/SYSTEM-ARCHITECTURE.md` §3. A Laravel-hosted Vite bundle cannot do that; Next.js ISR can.

## Non-negotiables

1. **No business logic here.** Prices, discounts, delivery charges, stock decisions and profit
   figures are the API's answers. If a component calculates money, that is a bug.
2. **Only semantic design tokens.** A hardcoded hex or px spacing value is a defect — it means a
   token is missing, so add it to `../packages/tokens`.
3. **No hardcoded user-facing strings.** Bangla and English both ship at launch: English at `/`,
   Bangla at `/bn/`. A lint rule enforces this. The full string set is in
   `../docs/_design/05-content-and-assets-spec.md`.
4. **Server-only API access.** The storefront calls the API from Server Components and Route
   Handlers with a server-side token. No API credential ever reaches the browser.
5. **`../packages/api-types` is generated, never hand-edited.**
6. Performance and accessibility budgets in `../docs/UI-UX-DESIGN-SPEC.md` §7–8 are CI gates, not
   targets.

## Setup

Run everything from the **repository root** — that is the npm workspaces root.

```bash
npm install
npm run dev:storefront      # http://localhost:3000
```

Not scaffolded yet: that is Phase 3 (`../docs/IMPLEMENTATION-PLAN.md`, tasks `P3-STO-*`), and
`packages/tokens` is the first output of the Figma library currently being built.
