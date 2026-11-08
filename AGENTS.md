# AGENTS.md — Toy Mart BD

Working protocol for AI coding agents on this repository.

**All project rules, stack decisions and conventions live in [`CLAUDE.md`](./CLAUDE.md). Read it first — it is the authority.** This file covers only how multiple agents divide work without colliding.

---

## 1. Ground rules

1. **One agent owns one domain at a time.** Ownership is by directory, listed in §3. Never edit a file outside your assigned domain; if you need a change there, state the requirement and let the owning agent make it.
2. **Never edit a shared file concurrently.** `composer.json`, `toymartbd-app/package.json`, `routes/*`, `config/*`, `.env.example`, `docs/*` and the OpenAPI document are shared. Changes to them are serialised through the lead.
2a. **Two frontends, two homes.** The admin SPA is inside Laravel at `resources/js/admin/`; the Next.js storefront is at `toymartbd-app/storefront/`; shared packages are at `packages/`. The repo root is both the Laravel app and the single npm workspaces root.
2b. **Figma writes are never parallel.** The design-system tooling requires strictly sequential mutations — two agents calling `use_figma` at once can corrupt the file. Parallelise the specification work in `docs/_design/`; one agent at a time executes the Figma construction.
3. **Migrations are append-only across agents.** Never modify another agent's migration. Add a new one.
4. **Contract before code.** If your work changes an API shape, update the OpenAPI document and regenerate `packages/api-types` in the same PR. Clients must break at typecheck time, not at runtime.
5. **Tests before "done".** Run the relevant suite and report what passed. A task is not complete because the code was written.
6. **Say what you did not verify.** Unverified assumptions must be stated in the PR body, not silently absorbed.

---

## 2. Planning-phase assignments (complete)

| Agent | Responsibility | Output |
|---|---|---|
| 1 | Competitor research — 5 primary BD toy sites, empirically verified | `docs/_research/agent1-competitors.md` |
| 2 | Competitor research — remaining sites + marketplace baseline | `docs/_research/agent2-competitors.md` |
| 3 | AppByte benchmarking + Pathao/Steadfast API reference | `docs/_research/agent3-appbyte-and-couriers.md` |
| 4 | BD market: payments, COD economics, SMS/WhatsApp, address data, legal | `docs/_research/agent4-bd-market-and-payments.md` |
| Lead | Architecture, database, UI/UX spec, synthesis, roadmap | `docs/SYSTEM-ARCHITECTURE.md`, `docs/DATABASE-DESIGN.md`, `docs/UI-UX-DESIGN-SPEC.md`, and the synthesis documents |

Research-phase discipline: findings had to be verified from the actual source, with "verified absent" kept distinct from "not verified", and inferences labelled as inferences. The same discipline applies to any later research task.

---

## 3. Implementation ownership map

Assign at most one agent per row at any time. Rows in the same group can run in parallel; rows depending on an earlier group must wait.

| Domain | Owns | Depends on |
|---|---|---|
| **Foundation** | `app/Support/`, `config/`, base migrations, roles/permissions, auth, API skeleton, CI | — |
| **Catalog** | `app/Domain/Catalog/`, catalog migrations, catalog endpoints, Meilisearch sync | Foundation |
| **Inventory** | `app/Domain/Inventory/`, stock + purchase + supplier migrations and endpoints | Foundation, Catalog |
| **Cart & Checkout** | `app/Domain/Cart/`, pricing pipeline, delivery-charge resolver | Catalog, Inventory |
| **Ordering** | `app/Domain/Ordering/`, order state machine, order endpoints | Cart, Inventory |
| **Payment** | `app/Domain/Payment/`, gateway drivers, webhooks | Ordering |
| **Fulfilment** | `app/Domain/Fulfilment/`, courier drivers, shipment endpoints, location sync | Ordering |
| **Risk** | `app/Domain/Risk/`, scoring, phone lookup | Ordering, Fulfilment |
| **Finance** | `app/Domain/Finance/`, ledger, P&L, settlements, invoices | Ordering, Fulfilment, Inventory |
| **Marketing** | `app/Domain/Marketing/`, coupons, promotions, campaigns | Cart, Ordering |
| **Content** | `app/Domain/Content/`, CMS, blog, media | Foundation |
| **SEO** | `app/Domain/Seo/`, slug history, redirects, sitemap, schema | Catalog, Content |
| **Tracking** | `app/Domain/Tracking/`, GA4 MP, Meta CAPI, dedup | Ordering |
| **Admin UI** | `resources/js/admin/features/<feature>/` — one agent per feature folder | the matching API domain |
| **Storefront** | `toymartbd-app/storefront/app/<route-group>/` — one agent per route group | Catalog, Cart, Ordering |
| **Design system** | `packages/tokens/`, `resources/js/admin/components/ui/`, `toymartbd-app/storefront/components/ui/` | Figma library built |
| **API types** | `packages/api-types/` — **generated, never hand-edited** | the OpenAPI document |

### Hard rules for this map
- `Finance` and `Risk` are **read-mostly**. They listen to events and write their own tables. No other domain writes to them, and they write to no other domain.
- `Fulfilment` never writes stock. It emits `ShipmentDispatched`; `Inventory` listens.
- `Tracking` never blocks a request. Every dispatch is queued.
- The design-system agent must finish Tier 1 primitives before any Admin UI or Storefront agent starts, or the same button gets built five times.

---

## Figma build rules

These are mechanical and non-obvious; each one cost a rework pass.

1. **Clear the default fill on every structural container.** `figma.createFrame()` and `figma.createAutoLayout()` both default to an **opaque white fill**. A layout container that keeps it shows as a white block on the canvas and, worse, silently breaks dark mode — a raw white fill does not follow the Color collection. Set `fills = []` on every header, row, strip, grid and section wrapper. Only these carry a background: the page root (`color/bg/canvas`), cards and panels (`color/bg/surface`), and the specimen swatches themselves.
2. **No raw colour, anywhere, including strokes.** A hairline on a swatch is still a colour. Bind it to `color/border/default`. A hardcoded hex is a defect; it means a token is missing.
3. **Nothing overlaps.** Top-level frames on a page must not intersect. Lay pages out on a single horizontal or vertical run with a clear gap, and audit it — compute bounding boxes and assert no intersection rather than trusting the eye.
4. **Mutations are strictly sequential.** Never run two `use_figma` calls at once; parallel writes can corrupt the file. Parallelise specification work in `docs/_design/`, not Figma writes.
5. **Font loads do not persist across calls.** Reload every face in every script that writes text, or the write fails with "unloaded font".
6. **Figma rejects `.` in a variable name.** The spacing half-step is `space/0-5`; its CSS custom property stays `--space-0-5`.
7. **`lineHeight` cannot be variable-bound.** A bound FLOAT is read as **pixels**, so a 160 meant as a percentage renders as a 160px line-height. Set `lineHeight` explicitly as a PERCENT. `fontSize`, `letterSpacing` and `fontFamily` bind correctly.
8. **Elevation and motion cannot be variables.** Elevation is effect styles (`Elevation/Light/e1`–`e4`, `Elevation/Dark/e1`–`e4`, `Focus/ring`); motion is documentation plus the code token file.
9. **Screenshot a section, not the whole page.** A 9,000px page renders to an unreadable thumbnail and proves nothing.

## 4. Git workflow

```
main                          always deployable
└── phase-N/<feature>         one branch per feature, one agent per branch
```

- Branch from current `main`. Rebase on `main` before opening a PR; never merge `main` into a feature branch.
- Commits: `feat(orders): add bulk courier booking [P6]`
- PR body must state: what changed · which docs section it implements · tests run and their result · anything left unverified · any API contract change.
- CI must be green: Pint, PHPStan, deptrac, Pest, ESLint, tsc, Vitest, Playwright smoke, Lighthouse budget, axe.
- Never force-push a branch another agent is reading.

---

## 5. Conflict protocol

| Situation | Action |
|---|---|
| You need a change in another agent's domain | Do not edit it. Record the requirement in your PR body and notify the lead. |
| Two agents need the same new shared utility | It belongs in `app/Support/` or `src/shared/` and is owned by the Foundation/design-system agent. Request it. |
| Your migration conflicts with another's | Rebase, renumber yours later, re-run `migrate:fresh --seed`, verify. Never edit theirs. |
| An API shape must change after clients consumed it | Additive only. A breaking change needs lead approval and a `v2` route. |
| A doc contradicts the code | The doc wins until the lead agrees otherwise. Raise it; do not silently diverge. |
| You cannot verify something external (courier behaviour, gateway terms, regulation) | Say so explicitly. Do not guess. |

---

## 6. Definition of done

A task is done when all of the following are true:

- [ ] Behaviour matches the referenced `docs/` section
- [ ] Domain logic is in a service or action, not a controller
- [ ] Every write is validated by a FormRequest and authorised by a Policy
- [ ] Money is `DECIMAL`, dates bucket by Dhaka time, enums are PHP enums
- [ ] Any stock or financial write is inside a transaction with correct locking
- [ ] No external HTTP call inside a transaction
- [ ] Tests written and passing, including the failure cases
- [ ] The two invariants still hold (stock ledger = stock levels; order financials = ledger)
- [ ] OpenAPI updated and types regenerated if the contract changed
- [ ] Bangla and English both exercised where text is user-facing
- [ ] Accessibility and performance budgets still pass for UI work
- [ ] `docs/` updated if a decision changed
