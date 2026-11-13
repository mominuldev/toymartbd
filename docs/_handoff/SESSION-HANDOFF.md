# Session handoff — continue on another machine / account

**Written:** 2026-11-13 (lead session). **Branch:** `phase-7/batch-1` — batch complete, PR opened; merge after CI. Next: Phase 7 batch 2 (server-side tracking) or Phase 8.
The owner moves to a different PC and Claude account; nothing outside the git repo travels. Read this, then
`CLAUDE.md`, then `docs/IMPLEMENTATION-PLAN.md` (progress sections are the source of truth for what is done).

## How the owner wants work run
- Several parallel background agents per batch (5–7), each with a strict file scope and **no git**; the lead does all git.
- One PR per batch with `gh`; the lead merges it **after all 4 CI jobs pass** (PHP, Frontend, Storefront, Admin E2E).
- Owner requires Figma fidelity (file `PWZp5VzEgEEf5N2Y38lGIh`, admin Theme B section `241:29517`).
- Owner decisions go into `docs/IMPLEMENTATION-PLAN.md` and the relevant config/docs when given.
- Workflow notes and CI gotchas: `docs/_handoff/dev-workflow-notes.md`, `docs/_handoff/figma-fidelity-notes.md`.
- Shared agent brief for this batch: `docs/_handoff/briefs/brief-p7-common.md`.

## Merged so far (main)
PR #1–#16: Phases 2–6 batch 1 (catalog, checkout, orders, inventory, finance, reports, returns, SSLCommerz (inactive),
REVE SMS driver + Admin → Integrations → Messaging, couriers (Pathao sandbox-verified, Steadfast fixture-only),
tracking, risk, COD settlement). See IMPLEMENTATION-PLAN progress sections.

## In progress on this branch — Phase 7 batch 1 (agents were running when this was written)
Each item below lists its scope; check the code on disk to see how far it got, then finish it.
| # | Scope | State at handoff |
|---|---|---|
| 1 | CMS typed blocks + homepage builder + signed live preview + scheduling (app/Domain/Content, admin features/content/home, storefront components/content/blocks) | **done** |
| 2 | Static pages, menu builder (header/footer/mobile, falls back to category tree), banners, testimonials | **done** |
| 3 | Blog: posts/categories/tags/authors, buying guides with live product picks, related posts | **done** (shared `App\Support\PreviewToken`; SEO type `blog_post`) |
| 4 | SEO plumbing: per-entity seo_meta + SeoPanel, metadata builder with hreflang + x-default, JSON-LD builders + validator tests, slug-history 301s + redirect manager, locale-partitioned sitemaps, robots.txt editor | **done** — rows 4–7 except Search Console metrics/site audit (`docs/api/seo.md`) |
| 5 | Promotions engine (priority/stacking), coupon builder UI, flash sales (allotment never oversold), abandoned-cart SMS recovery | **done** — see open items below |
| 6 | Owner decisions 2026-11-13 (risk confirmed, verify→confirmed, rider phone on shipment + no-rider SMS variant, managers get integrations, non-masking numeric sender, ৳0.26/segment) | **done** |
| 7 | Message templates library screen (`/automations/templates`): list, editor with EN/BN + server preview/segments, versions, custom marketing/manual templates | **done** (no Figma frame — owner to review screens) |
| — | Phase 7 server-side tracking (GA4 MP, Meta CAPI, dedup, consent, diagnostics) | not started — next batch |

Known failing at handoff (other agents mid-edit): OpenApiSpecTest (NotificationTemplateController being edited),
some catalog/storefront tests, PHPStan errors in Content/Marketing/CartRecovery files, admin typecheck in content/home and
notifications tests. **Finish the batch, then run everything:**
```
php artisan test --parallel && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse && ./vendor/bin/deptrac
composer openapi:export && npm run api-types:generate
npm run typecheck && npm run typecheck:storefront && npm run lint:i18n && npx vitest run && npm run test:storefront
```
Then write the Phase 7 batch 1 progress section in IMPLEMENTATION-PLAN, open the PR, merge after CI.

## Integration state (latest)
All 7 batch-1 agents finished. Full checks: 2889 PHP passed, PHPStan/deptrac clean, Pint fixed, typecheck clean, i18n clean, 389 storefront unit, admin 330/332 (2 product-form tests are a known load flake; pass alone). An integration-fix agent was started for: risk deadlock retry, global lock order PlaceOrder/EditOrder, page SEO cache tags, merging the two per-minute content revalidation commands, About page metadata, cart-recovery retry routing, storefront Playwright `/pages/privacy` mock. If that work is not on disk, do it, then write the Phase 7 batch 1 progress section in IMPLEMENTATION-PLAN, open the PR and merge after CI.

## Follow-ups found by agent 5 (fix before merging the batch)
- `2026_11_28_700100_create_notification_template_versions_table.php` (agent 7): auto unique-index name too long for MySQL (1059) — give it a short explicit name. Agent 7 was told.
- deptrac: 2 violations in `app/Domain/Notification/DTOs/CustomTemplateData.php` (agent 7).
- Risk `RefreshProfileOnOrderChange` (after-commit insert into `customer_risk_profiles`) can deadlock under concurrent checkouts and is not retried — add a retry / upsert.
- EditOrder locks promotions before stock while PlaceOrder locks stock first — make the lock order consistent.
- Cart-recovery log rows: admin "Retry" goes through the order notification job and fails (`notifiable_missing`).
- Flash-item variant picker uses an endpoint needing `orders.create`; scope chips saved earlier show as `#id`.
- Storefront `/pages/privacy` 404 Playwright check (agent 2's pages work).

- SEO follow-ups: title separator mismatch (server " | " vs storefront template " · "); Content↔Seo deptrac cycle to review; blog/CMS actions write seo_meta/slug_history themselves alongside generic SaveSeoMeta/RecordSlugChange — fold into one path; blog_category/tag/author have no SEO provider (not in panel/sitemaps); `next.config.ts` `htmlLimitedBots: /.*/` makes metadata fetches count against TTFB.

- Pages follow-ups: page saves purge `content:page:{slug}` but the SEO fetch is tagged `seo` + `page:{slug}` — align tags so a page SEO save revalidates; check `app/[locale]/pages/about/page.tsx` still uses the lib/seo metadata helpers (agent 2's fork rewrote it); two scheduled revalidation commands (`toymart:content:revalidate-schedule` from agent 1 and `toymart:content:revalidate-scheduled` from agent 2) — merge into one; shared sanitiser strips relative links in page bodies; storefront Playwright `/pages/privacy` 404 test needs the mock API endpoint; untraced failures last seen: MediaRoleMatrixTest ×1, DemoCatalogSeederTest ×2, Arch BoundariesTest "Path not found" ×2.

## Owner decisions still open
- Managers can now change courier/SMS credentials (decision 6) — confirm the security impact is understood.
- Bangla no-rider out-for-delivery SMS drops "অর্ডার" to stay 1 segment — keep or full copy (2 segments)?
- Meta approval for WhatsApp `order_out_for_delivery_no_rider`; design sign-off for the shipment rider row (no Figma frame).
- Flash sales default `allows_coupons=false`? Where do guests give phone + consent for cart reminders (never on checkout)? All `config/cart_recovery.php` values are placeholders. Promotion budgets exclude delivery saved by free-delivery promotions.
- Static page copy incl. policies is the owner's (seeded drafts are placeholders); should `/store` open on Pages instead of Media; per-page header colour needed?; title separator " | " vs " · ".
- Earlier open lists: IMPLEMENTATION-PLAN "Owner decisions open" lines in every progress section.
- Still owed by the owner: Gate 4 UAT on staging (`docs/ops/UAT-GATE-4.md`), ten real-order profit hand check
  (`docs/ops/PROFIT-RECONCILIATION.md`), staging host, SSLCommerz merchant account, Steadfast merchant account,
  REVE credentials entered at Admin → Integrations → Messaging (then `php artisan toymart:sms:test 01XXXXXXXXX` and `--bn`).

## Deploy notes added this phase
- After deploying roles changes: re-run `RolesAndPermissionsSeeder` (managers gain `settings.manage_integrations`).
- `WHATSAPP_ENABLED` stays false until a WhatsApp account exists (else the SMS fallback never fires).
- Courier verification is console-only: `php artisan toymart:couriers:provider {pathao|steadfast} --verify`.
