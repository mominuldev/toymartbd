# Toy Mart BD

E-commerce platform for a kids' toy and educational-products business in Bangladesh.

**Status: planning phase — no application code yet.** Research and the proposed plan are in `docs/`. Implementation starts once the roadmap is approved.

## What it is

Two applications over one Laravel API:

- **Storefront** — the shop. Optimised for organic search and for mid-range Android on 4G.
- **Admin** — orders, inventory, purchases, courier booking, order-risk screening, and real profit-and-loss for a COD-dominated market.

## Documents

| Document | Contents |
|---|---|
| [`docs/EXECUTIVE-SUMMARY.md`](docs/EXECUTIVE-SUMMARY.md) | The whole plan in a few pages — start here |
| [`docs/COMPETITOR-ANALYSIS.md`](docs/COMPETITOR-ANALYSIS.md) | What the competition actually does, verified site by site |
| [`docs/FEATURE-MATRIX.md`](docs/FEATURE-MATRIX.md) | Feature-by-feature comparison and gap analysis |
| [`docs/PRODUCT-REQUIREMENTS.md`](docs/PRODUCT-REQUIREMENTS.md) | Scope, MVP boundary, functional requirements |
| [`docs/SYSTEM-ARCHITECTURE.md`](docs/SYSTEM-ARCHITECTURE.md) | Topology, rendering decision, integrations, security, testing |
| [`docs/DATABASE-DESIGN.md`](docs/DATABASE-DESIGN.md) | Schema, transaction boundaries, business logic, invariants |
| [`docs/UI-UX-DESIGN-SPEC.md`](docs/UI-UX-DESIGN-SPEC.md) | Design tokens, components, screen inventory, budgets |
| [`docs/DEVELOPMENT-ROADMAP.md`](docs/DEVELOPMENT-ROADMAP.md) | Ten phases with estimates, dependencies and gates |
| [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md) | Task-level breakdown per phase |
| [`docs/_research/`](docs/_research/) | Raw research with sources, including courier and payment API references |

Working rules for contributors and AI agents: [`CLAUDE.md`](CLAUDE.md) and [`AGENTS.md`](AGENTS.md).

## Layout

```
app/ config/ routes/ database/     Laravel 13 at the repo root — all business logic
resources/js/admin/                React 19 + Vite admin SPA, served by Laravel at /admin
resources/views/admin.blade.php    the admin SPA shell
toymartbd-app/storefront/          Next.js 16 — the customer-facing shop
packages/tokens/                   design tokens exported from Figma
packages/api-types/                TypeScript types generated from the OpenAPI document
docs/                              research, design specs, roadmap
e2e/                               Playwright
```

The repo root is both the Laravel application and the single npm workspaces root, so one `composer install` and one `npm install` set up the whole project.

**Why the admin sits inside Laravel but the storefront does not.** The admin is a private tool behind a login, never indexed, and it shares Laravel's session for auth — hosting it in Laravel removes a deployment, a CORS boundary and a token-handling problem. The storefront has the opposite needs: it must serve cached HTML from a CDN edge to mid-range Android phones on 4G, which is what Next.js ISR does and a Laravel-hosted bundle cannot.

## Planned stack

Laravel 13 · PHP 8.4 · MySQL 8.4 · Redis · Horizon · Meilisearch · React 19 + TypeScript + Vite (admin) · Next.js 16 (storefront) · Tailwind v4

## Bangladesh-specific scope

BDT · Bangla and English · COD as a first-class payment method with proper receivables tracking · Pathao and Steadfast courier integration behind one abstraction · district/upazila addressing with landmark capture · order-risk screening by phone history · SMS and WhatsApp notifications · profit and loss that accounts for courier charges, returns and refunds rather than just price minus cost.
