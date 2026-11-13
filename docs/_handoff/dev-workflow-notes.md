---
name: dev-workflow
description: "How the owner wants development run on toymartbd — parallel agents, PRs merged by Claude after CI passes"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 4024073c-c3fa-4cb2-bfb4-4484c9bff667
  modified: 2026-11-12T05:39:28.847Z
---

On 2026-11-11 the owner asked: "work with 3 agents, and create a git PR and merge it yourself."

**Why:** they want throughput and don't want to babysit PR merges.

**How to apply:**
- Split each batch across ~3 background agents. Give each one a strict file scope and no git commands; the lead does all git.
- Open a PR per phase branch with `gh` (gh is logged in as the owner's account).
- Merge it yourself with a merge commit, but only after CI on the PR passes. Read the frontend job log, because local Node is broken and CI is the only real JS build.
- Local PHP is `/usr/local/opt/php/bin/php` (8.5); PATH `php` is 8.3 and too old for the lockfile.
- Homebrew node is broken. Workaround without touching the system: download the official Node 24 tarball, verify its SHA256 from nodejs.org, extract it into the session scratchpad and prepend its bin/ to PATH. The scratchpad is per-session, so redo this each session.
- Also run the real app, not just tests (`migrate:fresh --seed`, curl the cookie flow, browser via chrome-devtools). In batch 2 this caught a seeding bug the tests missed. Click with JS `element.click()` in evaluate_script: the devtools click tool can land on the wrong element after a re-render.

- CI: don't use `playwright install --with-deps`. Its apt step failed twice on 2026-11-11 when Ubuntu mirror DNS was unreachable from the runners; the browser-only install works.
- PHPStan "Internal error"/stub-not-found = stale result cache from the old repo path; delete storage/framework/cache/phpstan.
- Admin E2E CI job has no Redis and copies .env.example (CACHE_STORE=redis): anything a spec runs outside serve.sh (e.g. tinker seeding) must set CACHE_STORE=file.
- Local MySQL (mysql@9.7) is broken: protobuf upgrade + an interrupted `brew reinstall` (no bottle, compiles from source). The demo stack runs on SQLite via a scratchpad demo-env.sh instead.
- The system disk is nearly full (~4 GB free); use TMPDIR on /Volumes/Development.
- If phpunit runs out of memory, check that `memory_limit` 512M is still set in phpunit.xml (Scramble's OpenAPI generation needs it).

- If a PR shows "no checks reported" for minutes, it has a merge conflict (CI does not start). Merge main into the branch and push.
- To fix a PR branch while agents keep editing the main tree, use a `git worktree` in the scratchpad, with symlinked vendor/ and node_modules/. PHP tests can't run there, because Pest rejects the uuid in the path.
- Admin E2E uses port 8124 and a file cache. Parallel agents running it at the same time, or repeated local runs (login throttle), cause flaky failures.
- Batches so far: PR #7 = P4 batch 3, PR #8 = batch 4 (cart/pricing, orders core, inventory admin, search/home), PR #9 = batch 5 (checkout, storefront cart/checkout, admin orders, product-form gaps), merged 2026-11-12. PR #10 = Media Library + product-form/inventory Figma fixes, merged 2026-11-12. PR #11 (remove Aa button) merged 2026-11-12.
- Batch 6 (2026-11-12, branch phase-4/batch-6, owner asked for 5 agents): 1 customer account+OTP (P4-STO-11), 2 notifications SMS/email/WhatsApp (NOT-01..03, I18N-03), 3 manual/edit/bulk orders (ORD-06..08), 4 import/export + reviews (CAT-10/12), 5 i18n lint + Bangla layout + golden path (I18N-01/02). Shared brief: scratchpad brief-common.md. PAY-02/03 blocked on gateway decision. Merged as PR #12 on 2026-11-12.
- Phase 5 batch 1 (2026-11-12, branch phase-5/batch-1, 5 agents, brief scratchpad brief-common-p5.md): 1 purchasing/suppliers/landed cost/payables, 2 inventory ops (approvals, transfers, reconciliation, alerts), 3 ledger + expenses + daily summaries (owns Finance Ledger contract), 4 P&L/profitability/valuation reports, 5 returns & refunds. Phase 4 left: PAY-02/03/04 (gateway), wishlist hearts, account pages in Bangla layout spec; Gate 4 UAT not yet held. Owner then asked to close Phase 4 gaps too: agent 6 = SSLCommerz driver/IPN/expiry (research §1.7 recommends it; built unverified with fixtures, owner hasn't confirmed), agent 7 = wishlist hearts, account pages in Bangla spec, TrustProxies, rule-16 400s, recommendation_source stamping, cart line images, StorefrontOrder schema, Figma checks.
- Owner decisions 2026-11-12 for the P5 batch-1 plan write-up: report weeks start Saturday (Sat–Fri); refund approval threshold ৳2,000 (no longer placeholder). Return window 7 vs 10 days still open (legal). 2026-11-13: allow closing a PO short; third-party freight/customs recorded as an expense (cash-only, capitalised, not P&L — avoids double count with landed cost). COD received = gross amount collected; courier fee recorded separately (confirmed 2026-11-13).
- PR #13 (Phase 5 batch 1 + Phase 4 gaps) merged 2026-11-13. CI gotcha: CI copies .env.example, so a test that putenv()s a var listed there is ignored by Laravel's immutable env — test the parser directly. Local admin E2E is very slow (48 min, sign-in timeouts); trust CI for it.
- Sign-off prep (2026-11-13, branch phase-5/signoff-prep, brief scratchpad brief-signoff.md): A Figma pass admin orders/automations/import/reviews (port 8311), B Figma pass finance/purchasing/inventory/returns (8312), C storefront account/wishlist/payment + Bangla vs Figma (mock API 4811/3811), D staging runbook + UAT-GATE-4 script + toymart:finance:order-breakdown + 10-order rehearsal. Screenshots via Playwright Chromium since chrome-devtools MCP is down; admin build guarded by mkdir lock /Volumes/Development/tmp/admin-build.lock.
- PR #14 (sign-off prep: Figma pass, Horizon config fix, Gate 4 UAT kit in docs/ops, toymart:finance:order-breakdown) merged 2026-11-13. Still owed by the owner: Gate 4 UAT on staging, ten real-order profit hand check, SMS provider, staging host, SSLCommerz fee booking rule.
- SMS: owner's provider is REVE SMS at https://smpp.ajuratech.com:7790 (PR #15, ReveSmsGateway). Owner wants SMS credentials entered in admin settings (encrypted DB `settings`), NOT env — env only as fallback. WHATSAPP must stay disabled until a WhatsApp account exists, else SMS fallback never fires.
- Phase 6 batch 1 (2026-11-13, brief scratchpad brief-p6.md, 7 agents incl. #7 messaging settings): 1 courier core/shipments (owns CourierGateway contract), 2 Pathao sandbox + courier_locations, 3 Steadfast fixtures + fraud-lookup 'not connected', 4 courier settings/booking UI/labels, 5 webhooks/15-min poll/tracking/perf report, 6 risk module + COD settlement. Started on branch phase-5/sms-reve before PR #15 merged — move to phase-6/batch-1 off main after merge.
- PR #16 (Phase 6 batch 1: couriers, tracking, risk, COD settlement, SMS settings in admin) merged 2026-11-13. Courier verify = `toymart:couriers:provider {code} --verify` (console only, owner decision).
- Owner decisions 2026-11-13 (Phase 6): keep risk thresholds/weights and ৳3,000 first-order hold; watch-list holds every order; verifying a held order → CONFIRMED; keep courier status mappings; out-for-delivery SMS shows the RIDER's phone (staff-entered on the shipment, never hotline; omit line if unknown); managers get settings.manage_integrations; REVE sender is a non-masking number; SMS ৳0.26 per segment.
- Phase 7 batch 1 (branch phase-7/batch-1, brief scratchpad brief-p7.md): 1 CMS blocks+homepage builder+preview, 2 pages/menus/banners/testimonials, 3 blog+guides, 4 SEO plumbing (owns metadata/JSON-LD/sitemaps/redirects), 5 promotions/flash sales/abandoned cart, 6 the owner decisions above. Server-side tracking (GA4/CAPI) left for batch 2.
- Migration timestamps: agents' "N-block" dates (2026_11_12_6Nxxxx) can sort before tables they FK to (customers/orders are 2026_11_15+). SQLite doesn't care, CI's MySQL job fails with 1824. Tell agents to date migrations after their FK targets.
- CI Pest runs serially; mPDF invoice tests pushed it past 512M, so phpunit.xml memory_limit is now 1G.

- Since 2026-11-12 the git repo is the project root `/Volumes/Development/Sites/toymartbd` (it used to be `.../toymartbd/toymartbd`). Old briefs and transcripts use the old path.
- If a session dies mid-batch, background agents die with it, but their transcripts survive in `~/.claude/projects/<proj>/<session>/subagents/agent-*.jsonl` and their files stay on disk. Resume by re-sending the original brief plus "continue from what's on disk", pointing at the transcript.

See [[figma-build-progress]] for design status.
