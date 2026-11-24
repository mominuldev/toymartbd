# Deployment — API + admin on a VPS, storefront on Vercel

**Architecture:** `SYSTEM-ARCHITECTURE.md` §12 (one VPS for API + admin, zero-downtime release-directory swap, `php artisan down` only for destructive migrations, storefront on Vercel with preview URLs per PR). **Related:** [`RUNBOOK.md`](RUNBOOK.md) (day-to-day operations and incidents), [`LAUNCH-CHECKLIST.md`](LAUNCH-CHECKLIST.md) (go-live gate, `php artisan toymart:launch:check`), [`STAGING.md`](STAGING.md) (every env variable explained; staging uses the same scripts), [`backup-and-restore.md`](backup-and-restore.md), [`observability.md`](observability.md).

> **Status, 2026-11-14.** No server, domain, Vercel project or Sentry project exists yet. Everything here has been proven locally (§9): shellcheck, actionlint, the provisioning templates rendered and validated with `nginx -t` / `php-fpm -t`, and the release + rollback script driven against a fake server layout — including a failing health check that rolled itself back, through real nginx and PHP-FPM. **Not yet run on a real Ubuntu 24.04 host.** The first real run is the owner's (§10); the lines marked *unverified* are the ones most likely to need a fix on that run.

| File | What it is |
|---|---|
| `ops/provision/provision.sh` | Idempotent server setup for Ubuntu 24.04 (run as root, re-run any time) |
| `ops/provision/provision.env.example` | The owner's settings for it → `/etc/toymartbd/provision.env` |
| `ops/provision/templates/` | Every config file the script installs (nginx, PHP-FPM, MySQL, Redis, Meilisearch, Supervisor, cron, logrotate, fail2ban, UFW, unattended upgrades, sudoers, SSH, the production `.env` template) |
| `ops/deploy/deploy.sh` | Release / rollback / list / health on the server (installed as `/var/www/toymartbd/bin/toymart-deploy`) |
| `ops/deploy/build-artifact.sh` | Packs the release tarball in CI |
| `ops/deploy/rehearse-local.sh` | The local proof (§9) |
| `.github/workflows/ci.yml` | Tests on every push and pull request; on `main` its last job deploys to Hostinger (§11). The VPS deploy workflow (`deploy.yml`) was removed on 2026-11-18 — restore it from git history (`backup/main-2026-11-18`) before using §§2–9's GitHub steps |
| `toymartbd-storefront/vercel.json` | Vercel build settings for the storefront |

---

## 1. What runs where

```
                     ┌───────────────── VPS (Ubuntu 24.04) ─────────────────┐
 browser ── https ──▶│ nginx ─▶ PHP-FPM 8.4 (pool "toymartbd", user deploy)   │
  /admin, /api/v1    │            └─ /var/www/toymartbd/current/public      │
                     │ Supervisor ─▶ php artisan horizon   (7 queues)        │
                     │ cron ─▶ php artisan schedule:run    (every minute)    │
                     │ MySQL 8.4 · Redis 7 · Meilisearch — all on 127.0.0.1  │
                     └───────────────────────────────────────────────────────┘
 shopper ── https ──▶ Vercel (Next.js storefront) ── server-side fetch ──▶ API
```

Only ports 22, 80 and 443 are open (UFW). MySQL, Redis and Meilisearch listen on localhost only and have passwords anyway.

**Layout on the server** (`APP_ROOT=/var/www/toymartbd`, owned by `deploy`):

```
releases/<sha>/        one per release: code, vendor/, cached config/routes/views/events
shared/.env            the secrets (0600) — the only copy, never in git or an artifact
shared/storage/        uploads, sessions, logs, the maintenance flag — survives releases
current -> releases/<sha>     what nginx, PHP-FPM, Horizon and cron use
releases.log           history: deployed / failed / rolled-back-to, with UTC times
deploy.env             non-secret deploy settings (health URL, reload command, releases kept)
bin/toymart-deploy     the deploy script, refreshed by every deploy
artifacts/             incoming tarballs (deleted after a successful release)
```

Logs: `/var/log/toymartbd/` — `laravel.json` (the app, one JSON object per line, from web, Horizon and cron alike), `horizon.log`, `scheduler.log`, `php-fpm-error.log`, `php-fpm-slow.log`; nginx in `/var/log/nginx/toymartbd.*.log` (JSON, no query strings — they can carry a tracking phone). Rotated daily, 14 days kept.

## 2. First-time server setup

You need: a VPS with **Ubuntu 24.04 LTS** (2 GB RAM minimum, 4 GB comfortable; we have not compared providers — pick on price and Dhaka latency, `STAGING.md` §1), root SSH access with **your own SSH key**, and a domain.

1. **Point DNS first** (§4), so the certificate can be issued in step 4.
2. **Make a deploy key** on your own computer (it is only for GitHub Actions):
   ```bash
   ssh-keygen -t ed25519 -N '' -C toymartbd-deploy -f toymartbd-deploy
   ```
   `toymartbd-deploy.pub` goes to the server (step 3); `toymartbd-deploy` (private) becomes the `DEPLOY_SSH_KEY` GitHub secret (§6). Then delete both files from your computer.
3. **Copy the repo's `ops/` folder to the server and fill the settings:**
   ```bash
   scp -r ops root@SERVER_IP:/root/toymartbd-ops
   ssh root@SERVER_IP
   install -d -m 700 /etc/toymartbd
   cp /root/toymartbd-ops/provision/provision.env.example /etc/toymartbd/provision.env
   chmod 600 /etc/toymartbd/provision.env
   nano /etc/toymartbd/provision.env      # SERVER_NAMES, CERTBOT_EMAIL, DEPLOY_SSH_PUBKEY
   ```
4. **Run it:**
   ```bash
   bash /root/toymartbd-ops/provision/provision.sh
   ```
   It takes about 5–10 minutes and prints a warnings list at the end. It is safe to run again at any time — after changing a setting, after DNS starts resolving (to get the certificate), or to repair drift.
5. **Fill the app's secrets** (§3): `nano /var/www/toymartbd/shared/.env`, replace every `CHANGE_ME`.
6. **Escrow** `APP_KEY` and `BACKUP_ARCHIVE_PASSWORD` from `/etc/toymartbd/generated.env` in your password manager now.
7. **Add the GitHub secrets** (§6) and deploy (§5). After the **first** deploy only:
   ```bash
   sudo -iu deploy
   cd /var/www/toymartbd/current
   php artisan db:seed --force                           # reference data only in production (STAGING.md §4)
   php artisan toymart:search:configure && php artisan toymart:search:reindex
   php artisan toymart:notifications:sync-templates
   php artisan toymart:storefront-token                  # copy it into Vercel (§7), once
   ```
   Create your own admin login: `php artisan toymart:owner:create --email=you@example.com --name="Your Name"` (password prompted, hidden; `STAGING.md` §4). Then run `php artisan toymart:launch:check` (`LAUNCH-CHECKLIST.md`).

**What the script installs and why**

| Piece | Version / source | Notes |
|---|---|---|
| PHP | 8.4 from `ppa:ondrej/php` (Ubuntu 24.04 ships 8.3) | Same extensions as CI, plus `pcntl` (Horizon), `redis`, `zip`. The script **refuses to continue** unless GD can encode WebP **and** AVIF — the CI gate, repeated on the real host. |
| PHP-FPM | pool `toymartbd`, runs as `deploy` | `pm.max_children = (RAM − 1 GB) / 64 MB`, clamped 4–32 (2 GB → 16). `opcache.validate_timestamps=0`: each deploy reloads FPM, which empties opcache. Upload limits 12 MB / 16 MB (largest API upload is 10 MB). |
| MySQL | 8.4 LTS from `repo.mysql.com` (Ubuntu ships 8.0) | `utf8mb4_0900_ai_ci`, UTC, localhost only, InnoDB pool = 25 % RAM, binlogs kept 7 days (point-in-time recovery, `backup-and-restore.md`), slow log > 1 s. Root uses socket auth (`sudo mysql`); the app user is `toymartbd`. *Unverified: the debconf answers for the MySQL repo package.* |
| Redis | 7 (Ubuntu) | Password, localhost only, `maxmemory-policy noeviction` — queues and sessions must never be evicted. |
| Meilisearch | `v1.15.2`, pinned (matches `docker-compose.yml`'s v1.15) | systemd service, own user, localhost only, master key in `/etc/meilisearch.env` (root 0600). The first install prints the binary's SHA-256: put it in `MEILI_SHA256` to pin it. |
| nginx | Ubuntu | HTTP → HTTPS (except the ACME challenge). Only `index.php` executes. `/build/` cached a year (hashed names), `/storage/` 30 days. `X-Request-Id` passed through or generated at the edge. |
| TLS | certbot (webroot), auto-renew timer | Until the certificate exists, the site answers 503 on HTTP. |
| Horizon | Supervisor program `toymartbd-horizon` | `stopwaitsecs=660` so a 600 s report job can finish. |
| Scheduler | `/etc/cron.d/toymartbd` | `schedule:run` every minute as `deploy`. |
| Firewall | UFW: 22 (rate-limited), 80, 443 | |
| fail2ban | `sshd` + `recidive` jails | |
| SSH | password login off, root key-only | **Only applied once a key login exists** for root or a sudo user, so you are never locked out. |
| Updates | unattended-upgrades, **Ubuntu security only**, no automatic reboot | PHP, MySQL and Meilisearch are upgraded by hand (§8). |
| Swap | 2 GB file if none | `vm.swappiness=10` |
| `deploy` user | no password, SSH key only | `sudo` for exactly: reload PHP-FPM, Supervisor status/start/restart of Horizon. |

**Security headers.** HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` and the CSPs are set by the app (`App\Http\Middleware\SecurityHeaders`) on every response Laravel serves — HSTS whenever the request is HTTPS. nginx deliberately does **not** set them again: two different `Strict-Transport-Security` headers on one response is a defect (verified: exactly one, §9). The static `/build/` and `/storage/` locations, which never reach PHP, add `nosniff` themselves.

## 3. Secrets

**Server (`/var/www/toymartbd/shared/.env`).** Written once by `provision.sh` from `ops/provision/templates/env.production.example` and never overwritten. Every variable is explained in `STAGING.md` §3 — same names, production values.

| Filled by | Variables |
|---|---|
| `provision.sh` (generated, kept in `/etc/toymartbd/generated.env`, root only) | `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MEILISEARCH_KEY`, `STOREFRONT_REVALIDATE_SECRET`, `BACKUP_ARCHIVE_PASSWORD` |
| `provision.sh` (from your settings) | `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, database name/user |
| **You** — marked `CHANGE_ME`; a deploy refuses to run while any remain | `STOREFRONT_URL`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` |
| **You**, when you have them | `BACKUP_DISK=backups_s3` + `BACKUP_S3_*` (needs `composer require league/flysystem-aws-s3-v3` first — until then backups stay on this server, which is **not** a backup), `SENTRY_LARAVEL_DSN`, payment / courier credentials (rule 33a), GA4 / Meta |
| **Never set by hand** | `SENTRY_RELEASE` — each deploy bakes the git sha into the cached config |

After editing `.env` on a live server: `sudo -iu deploy`, `cd /var/www/toymartbd/current && php artisan config:cache && sudo systemctl reload php8.4-fpm && php artisan horizon:terminate` (or simply deploy again).

**GitHub** (Settings → Secrets and variables → Actions; put them on the `production` environment) — §6. **Vercel** — §7.

## 4. DNS and TLS

| Record | Points at | Serves |
|---|---|---|
| `A` (+ `AAAA` if the VPS has IPv6) `api.<domain>` | the VPS | API and the admin (`https://api.<domain>/admin`) |
| storefront domain (`www.<domain>` / apex) | Vercel (the records Vercel shows when you add the domain) | the shop |

The names are your choice; `SERVER_NAMES` in `provision.env` must list the server's. A separate `admin.<domain>` pointing at the same VPS also works — add it to `SERVER_NAMES` (it then gets the certificate and `SANCTUM_STATEFUL_DOMAINS`). Once DNS resolves, re-run `provision.sh`; it requests the certificate and switches nginx to the HTTPS site. Renewal is automatic (`certbot.timer`, reloads nginx).

## 5. Deploying

**Normally: merge to `main`.** CI runs; when all four jobs pass, the **Deploy** workflow starts (`workflow_run`). It:

1. checks the commit is on `main` and CI passed for it;
2. builds the admin bundle (`npm ci && npm run build`) — on GitHub, never on the server;
3. packs `ops/deploy/build-artifact.sh <sha>`: the app without `vendor/`, `.env`, `storage/`, tests, docs or the storefront, plus a `REVISION` file;
4. copies it and the current `deploy.sh` to the server over SSH (host key pinned);
5. runs `toymart-deploy release <artifact> <sha>`;
6. tags the Sentry release (if configured);
7. deploys the storefront to Vercel — **after** the API, so a storefront never goes live before the API change it relies on.

**What `release` does on the server** (all as `deploy`, under a lock so two deploys never overlap):

| # | Step | If it fails |
|---|---|---|
| 1 | Refuses if `shared/.env` is missing, has no `APP_KEY`, or still has a `CHANGE_ME`; refuses below 1 GB free disk | nothing changed |
| 2 | Unpacks into `releases/<sha>`; links `shared/.env` and `shared/storage`; checks the admin bundle is inside | release dir deleted; old release keeps serving |
| 3 | `composer install --no-dev --optimize-autoloader --classmap-authoritative` | same |
| 4 | `storage:link`, `config:cache` (with `SENTRY_RELEASE=<sha>`), `route:cache`, `view:cache`, `event:cache` | same |
| 5 | Looks at **pending** migrations; if any carries the destructive marker → `php artisan down --retry=60` | — |
| 6 | `migrate --force` — **while the old code is still serving** | old release keeps serving; see "migration failed" below |
| 7 | Atomic switch of `current` (a `rename(2)`, never a delete-then-create), reload PHP-FPM | rolls back |
| 8 | `php artisan up` if it went down | rolls back |
| 9 | Health check: `GET https://<api>/api/v1/health`, resolved to `127.0.0.1`, must answer 200 (up to 10 tries, 3 s apart) | **automatic rollback** to the previous release, PHP-FPM reloaded, Horizon restarted; the step fails red in GitHub |
| 10 | Start Horizon if it is not running (first deploy); `horizon:terminate` — running jobs finish, Supervisor restarts Horizon on the new code | warning only |
| 11 | Record `deployed <sha>`; delete the artifact; keep the newest 5 releases (never the live one or the rollback target) | — |

**By hand** (needs the removed `deploy.yml`, see the table above): GitHub → Actions → **Deploy** → *Run workflow* (branch `main`), action `deploy`, optional `sha` (a commit on `main` whose CI passed; empty = head of `main`). Or on the server with an artifact you already have: `/var/www/toymartbd/bin/toymart-deploy release <file.tar.gz> <sha>`.

**Migration policy.** Every schema change is expand-and-contract (`SYSTEM-ARCHITECTURE.md` §12; `CLAUDE.md` conventions): migrations run before the swap, and the previous release must keep working against the new schema. That is what makes zero-downtime deploys and code-only rollbacks safe.

A migration is **destructive** when the *old* code, still serving during the migration, would break or corrupt data while it runs — dropping or renaming a column or table the live release still reads, changing a column type in place, or a long table-locking `ALTER` on `orders`/`order_items` (which must also not run in business hours). Mark it with a comment anywhere in the migration file:

```php
// @deploy:destructive — drops orders.legacy_status (unused since release abc1234)
```

When a pending migration carries `@deploy:destructive`, the deploy puts the app in maintenance mode (503 with `Retry-After: 60`; the storefront's cached pages keep serving) for the migration window only. To force a window for one deploy without a marker, run it on the server with `DEPLOY_FORCE_MAINTENANCE=1`. A contract-phase drop of a column the live code no longer touches is **not** destructive and needs no marker.

**What shoppers see during a maintenance window.** The API answers every call with a 503 problem of type `maintenance` plus `Retry-After` (`php artisan down --retry=60`; `ProblemType::Maintenance`, distinct from a feature-level `service-unavailable` such as "no courier rate card"), and the admin shows Laravel's maintenance page. **The storefront switches itself** (Gate 5, `toymartbd-storefront/lib/maintenance.ts`), without probing the API — it reacts to the 503s its real calls get:

- the proxy's redirect-map refresh (`GET /storefront/redirects`, once a minute, every 10 s while failing) and every page or route-handler call through `lib/api.ts` arm a short **downtime signal** (`Retry-After`, bounded to 5–60 s); a fresh 2xx clears it;
- while the signal is live, `proxy.ts` answers **every page** — cached or not — with the designed maintenance page in the visitor's language as **503 + the API's `Retry-After`** and `Cache-Control: no-store`, without calling the API;
- a page whose render hits the 503 before the proxy knows shows the maintenance look from its error boundary (`app/[locale]/error.tsx`) — not the 500 page — but with status **500**: the App Router cannot set a 503 from a render. The error boundary is drawn in the browser, so a visitor without JavaScript (or a crawler) gets an empty 500 page for that one request; the next request is already served by the proxy;
- a checkout POST in the window gets the "can't take orders right now" notice and lands on the maintenance page;
- nothing from the window is cached: Next caches only 200 fetches and successful renders, a failed ISR revalidation keeps the last good page, and the proxy's 503 is `no-store`;
- after `php artisan up`, the shop returns by itself within the signal window (≤ 60 s).

**Where the signal lives (unverified on Vercel).** The signal is held on `globalThis`. Under `next start` the proxy and the pages share one Node process, so the first page that sees the 503 switches the proxy for every later request (proven by `e2e/api-maintenance.spec.ts`). On Vercel the proxy may run in a separate function instance from the pages; it then learns from its own redirect-map refresh, i.e. up to ~60 s after `artisan down`, and meanwhile shoppers get the error-boundary maintenance look (status 500; an empty page without JavaScript). This has not been tested on a Vercel deployment yet — check it on staging.

**Storefront maintenance switch (manual, longer work).** Set `STOREFRONT_MAINTENANCE=1` in the Vercel Production environment and redeploy (`vercel redeploy` or re-run the workflow's storefront job, about a minute). Every storefront page then answers **503 + `Retry-After: 600`** with the maintenance page, whatever the API says. Remove the variable (or set it to anything but `1`) and redeploy to end it. Use it when the shop must be closed for longer than a deploy's migration window, or ahead of a destructive migration if you want no shopper at all to see the brief error-boundary window:

1. switch the storefront on (above) a few minutes before;
2. run the deploy (the API goes into `php artisan down` for the migration only);
3. switch the storefront off once the deploy is green.

Still schedule destructive migrations for the quietest hour (02:00–06:00 Dhaka, after the 02:00 backup has finished), keep them short, and tell staff in advance; the admin is down for the same minutes.

**If a migration fails**, the old release keeps serving, and the deploy stops red. MySQL DDL is not transactional, so a migration with several statements may be **half applied**: check `php artisan migrate:status`, fix forward with a new commit. If the failed migration was destructive, maintenance mode is **left on on purpose**; after checking the schema, `cd /var/www/toymartbd/current && php artisan up`.

## 6. GitHub configuration

| Secret | Value | Required |
|---|---|---|
| `DEPLOY_HOST` | the VPS IP or `api.<domain>` | yes |
| `DEPLOY_USER` | `deploy` | yes |
| `DEPLOY_SSH_KEY` | the private deploy key (§2 step 2), whole file | yes |
| `DEPLOY_KNOWN_HOSTS` | output of `ssh-keyscan -t ed25519 <DEPLOY_HOST>` — **check its fingerprint against `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server** | yes |
| `DEPLOY_PORT` | only if SSH is not on 22 | no |
| `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID` | §7 | for the storefront job |
| `SENTRY_AUTH_TOKEN` (+ `SENTRY_ORG`, `SENTRY_PROJECTS` as secrets or variables; `SENTRY_PROJECTS` comma-separated, e.g. `toymartbd-api,toymartbd-admin`) | a Sentry token with release write access | no |

Optional **variables**: `DEPLOY_APP_ROOT` (default `/var/www/toymartbd`), `SENTRY_URL` (self-hosted Sentry). Put the secrets on a GitHub **environment** named `production`; add a required reviewer there if you want to approve each deploy.

**With none of these set, the Deploy workflow still runs and succeeds**: each job prints "deploy skipped — not configured" and does nothing. That is the state until the owner provisions a server.

## 7. Storefront on Vercel

1. Vercel → Add New → Project → import **github.com/mominuldev/toymartbd-storefront** (its own repository since 2026-11-17). **Root Directory: the repository root**, framework Next.js. The shared tokens and API types are committed copies in its `shared/` folder, so nothing outside the repo is needed. Build settings come from its `vercel.json`. Deploys run from that repo's `.github/workflows/deploy.yml` (secrets `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID` on that repo); deploy a storefront change that needs a new API endpoint only after the API release is live.
2. **Environment variables** (Settings → Environment Variables). All are server-only; never add a `NEXT_PUBLIC_` copy. `next build` **fails** unless the first five are set — absolute `http(s)` URLs, secrets at least 16 characters (`toymartbd-storefront/lib/env.ts`); the deploy workflow checks for them right after `vercel pull` and names any that are missing.

   | Variable | Production | Preview |
   |---|---|---|
   | `API_URL` | `https://api.<domain>` | the **staging** API — never production |
   | `STOREFRONT_API_TOKEN` | `php artisan toymart:storefront-token` on production | a token minted on staging |
   | `STOREFRONT_REVALIDATE_SECRET` | `grep REVALIDATE_SECRET /etc/toymartbd/generated.env` on the server (same value as Laravel's) | staging's |
   | `SITE_URL` | `https://www.<domain>` (canonical + hreflang) | the staging storefront URL |
   | `ADMIN_URL` | `https://api.<domain>` — the admin's origin, the only one allowed to frame the storefront (homepage builder live canvas) | the staging API |
   | `MEDIA_ORIGINS` (optional) | comma-separated extra image origins, only once media moves to a CDN (`MEDIA_CDN_URL`) | same |

   They are read at **build** time too (security headers are computed in `next.config.ts`), so any change needs a redeploy.
3. **Production deploys come from the Deploy workflow**, after the API (§5, step 7): `vercel.json` sets `git.deploymentEnabled.main = false`, so Vercel's Git integration does **not** also deploy `main` on its own. Create a token (Account → Tokens) for `VERCEL_TOKEN`; `VERCEL_ORG_ID` and `VERCEL_PROJECT_ID` are in the project's Settings → General (or `.vercel/project.json` after `vercel link`).
4. **Preview deploys per PR** come from the Git integration as usual. `ignoreCommand` skips a preview when a PR does not touch the storefront, `packages/` or the npm lockfile. Without a staging API, previews have nothing to talk to — either provide staging, or disable preview deployments until there is one.
5. In Laravel's `.env`, `STOREFRONT_URL` = the production storefront origin.
6. Storefront rollback: Vercel → Deployments → pick the previous production deployment → *Instant Rollback*. (The API rollback in §8 does not touch Vercel.)

**Rotating the storefront token** without a gap:

```bash
sudo -iu deploy; cd /var/www/toymartbd/current
php artisan toymart:storefront-token          # a NEW token; the old one still works
```
Put it in Vercel (`STOREFRONT_API_TOKEN`, Production), redeploy the storefront (Deployments → ⋯ → Redeploy), confirm the site works, then revoke the older ones:
```bash
php artisan tinker --execute '
  $a = App\Models\User::where("email", App\Console\Commands\IssueStorefrontToken::SERVICE_EMAIL)->firstOrFail();
  $t = $a->tokens()->where("name", App\Console\Commands\IssueStorefrontToken::TOKEN_NAME);
  $keep = (clone $t)->max("id"); echo (clone $t)->where("id", "<", $keep)->delete(), " revoked\n";'
```
(`--rotate` revokes every token **before** issuing the new one — fine in an emergency, e.g. a leaked token, but uncached storefront pages fail until Vercel is redeployed with the new value.)

**Rotating the revalidate secret:** change `STOREFRONT_REVALIDATE_SECRET` in both places, `php artisan config:cache`, reload PHP-FPM, redeploy the storefront. Revalidations sent in between are refused (pages refresh at their normal ISR interval).

## 8. Rollback and maintenance

**Roll back the API + admin** — one command on the server:

```bash
ssh deploy@api.<domain> /var/www/toymartbd/bin/toymart-deploy rollback          # to the previous release
ssh deploy@api.<domain> /var/www/toymartbd/bin/toymart-deploy rollback <sha>    # to a specific one still on disk
ssh deploy@api.<domain> /var/www/toymartbd/bin/toymart-deploy list              # what is on disk, what is live, history
```
or, with `deploy.yml` restored, GitHub → Actions → Deploy → Run workflow → action `rollback`. It re-caches the target's config against the current `.env`, switches `current`, reloads PHP-FPM, restarts Horizon, runs the health check, and records `rolled-back-to`. Running it again goes one release further back.

**The database schema is NOT rolled back.** Migrations from the newer release stay applied. That is safe because of expand-and-contract: the previous release never needs a column or table that a later release removed. If the release you are leaving ran a `@deploy:destructive` migration, check the data before trusting the older code. There is no `migrate:rollback` in this flow — to undo a schema change, write a new forward migration.

A release whose health check failed is recorded as `failed` and is never chosen as a rollback target.

**Monthly maintenance (owner, ~15 minutes, a quiet hour):**

```bash
sudo apt update && sudo apt upgrade        # PHP (ondrej PPA) and MySQL patch releases are NOT automatic
sudo systemctl restart php8.4-fpm && sudo supervisorctl restart toymartbd-horizon
[ -f /var/run/reboot-required ] && sudo reboot
```
Then `curl -s https://api.<domain>/api/v1/health`.

**Upgrading Meilisearch:** the search index is derived from MySQL, so the simplest upgrade is a rebuild: set `MEILI_VERSION` in `provision.env`, then `sudo systemctl stop meilisearch && sudo mv /var/lib/meilisearch/data /var/lib/meilisearch/data.old && sudo rm /usr/local/bin/meilisearch && sudo bash provision.sh`, then as `deploy`: `php artisan toymart:search:configure && php artisan toymart:search:reindex`. Delete `data.old` once search works. Keep `docker-compose.yml` on the same minor version. (The script refuses to switch versions on existing data by itself.)

## 9. How this was proven (local, 2026-11-14)

- **shellcheck 0.10.0**: `ops/deploy/*.sh`, `ops/provision/provision.sh` — clean. Also a new CI step in the PHP job (`Shellcheck — deploy and provisioning scripts`; the runner image ships shellcheck).
- **actionlint 1.7.7** (with shellcheck on the `run:` scripts): `deploy.yml` and `ci.yml` — clean. The "secrets absent" gate was run both ways.
- **Templates**: `provision.sh --render <dir>` renders every config with placeholder secrets and fails on any unresolved `{{…}}`. The nginx site passed `nginx -t` (nginx 1.27) in both the pre-certificate and the HTTPS form (1.27 warns that `listen … http2` is deprecated; it is kept because Ubuntu 24.04's nginx 1.24 has no `http2 on;`). The PHP-FPM pool passed `php-fpm -t`.
- **Real nginx + PHP-FPM** (rendered configs, self-signed certificate) in front of a deployed release: HTTP → 301 to HTTPS; `/api/v1/health` 200 with **exactly one** `Strict-Transport-Security` and the API CSP; `/admin` carries the nonce CSP; a `/build/` asset gets `immutable` + `nosniff`; `/.env` 403; any other `.php` 404; an incoming `X-Request-Id` is echoed back; the access log is one JSON line per request. Then a release whose health endpoint answers 503 was deployed **through nginx and PHP-FPM**: FPM served the new code (503 ×3), the script switched back, reloaded FPM, and the old code answered 200 — proving `$realpath_root` + reload picks up a swap.
- **`ops/deploy/rehearse-local.sh <dir> 8351`** — a fake `APP_ROOT`, its own SQLite DB, PHP's built-in server as the "PHP-FPM" that `RELOAD_CMD` restarts, artifacts built by `build-artifact.sh` from this checkout. Result: **17 passed, 0 failed**:
  0. `.env` with a `CHANGE_ME` → refused, naming the variable, nothing live;
  1. first release live, health 200, shared `.env`/`storage` linked, cached config carries `sentry.release = <sha>`;
  2. a release with a `@deploy:destructive` migration → maintenance window opened, migration applied, app up again;
  3. a release whose health check fails → exit 1, **automatic rollback**, previous release healthy, recorded `failed`;
  4. a release whose migration throws → exit 1, the swap never happens, the live release keeps answering 200;
  5. `rollback` → previous release live and healthy, **schema unchanged** (the newer table is still there);
  6. redeploying a non-live release; `list` output; 7. pruning to `KEEP_RELEASES`.

**Not proven here:** `provision.sh` itself on a real Ubuntu 24.04 host (package names, the MySQL repo's debconf answers, ondrej's GD having AVIF — the script checks this and stops if not), certbot against real DNS, Supervisor/Horizon restarts, the GitHub → SSH path, and Vercel. Those are the owner's first run (§10).

## 10. Needs the owner

1. **A VPS** (Ubuntu 24.04, ≥ 2 GB RAM) and root SSH with your key — then §2. *Decide the provider; there is no hosting comparison in `docs/_research/`.*
2. **A domain** and the DNS records in §4.
3. **The GitHub secrets** in §6 (deploy key, host, known_hosts) and the `production` environment.
4. **The `CHANGE_ME` values** in `shared/.env`: storefront URL, SMTP account.
5. **Escrow** `APP_KEY` and `BACKUP_ARCHIVE_PASSWORD`.
6. **Off-host backups:** an S3-compatible bucket, then `composer require league/flysystem-aws-s3-v3` (a code change through a PR) and `BACKUP_DISK=backups_s3` (`backup-and-restore.md`).
7. **A Vercel project** (§7): env vars for Production (and Preview, if there is a staging API), the three `VERCEL_*` secrets, and the region nearest the VPS.
8. **Sentry** (optional): DSN in `.env`, `SENTRY_AUTH_TOKEN` / `SENTRY_ORG` / `SENTRY_PROJECTS` in GitHub.
9. **The first real run** — watch the warnings list `provision.sh` prints, and report anything that failed so the scripts can be corrected. Record `MEILI_SHA256` from that run.
10. **Monthly**: the maintenance in §8 and the restore rehearsal (`backup-and-restore.md`).
11. **Storefront maintenance switch** (§5): add `STOREFRONT_MAINTENANCE` to the Vercel project only when needed; it is off when absent.

## 11. Hostinger (the `deploy` job in `.github/workflows/ci.yml`)

An alternative to the VPS release swap above, for a Hostinger plan with SSH (added 2026-11-17, modelled on the uptms-ad project's deploy). Since 2026-11-18 it is the last job of the single test-and-deploy workflow (`ci.yml`, "CI and deploy"): it runs on `main` only, after every test job passed — on a push, or by hand (Actions → CI and deploy → Run workflow, from `main`; the `reset_database` input lives there). Pull requests and other branches run the tests and never deploy. With no `HOSTINGER_*` secrets set it skips with a notice and succeeds. It is now the only deploy target: the VPS workflow was removed.

What it does: builds `vendor/` (`--no-dev`) and `public/build` on the runner; rsyncs the tree with `--delete`, never sending or deleting `.env`, `storage/`, `public/storage`, the host's `.htaccess` or `.well-known/` (and leaving out `tests/`, `e2e/`, `docs/`); then on the host runs `migrate --force`, `RolesAndPermissionsSeeder` (every deploy — it never touches what the owner edited, rule 20b), the full `db:seed` only while there are no users (reference data only: demo data and accounts are seeded in local/testing alone), `storage:link`, the config/route/view/event caches and `queue:restart`. Never interrupt a run on `main`: between the rsync and the migrations the host serves new code against an old schema.

**Full demo data, first time only** (owner request 2026-11-18): over SSH on the host, `cd <deploy path> && /opt/alt/php84/usr/bin/php artisan toymart:demo:seed`. It runs the same `DatabaseSeeder` as a developer machine — reference data, the owner `mominulfed@gmail.com` and six `@toymart.test` staff logins, catalog with photos, stock, inbox, and ~60 days of customers, orders, payments, shipments, returns, purchasing, finance, marketing, reviews, content and SEO. The demo logins get the password typed at its hidden prompt, never the factory one. Refused once any order exists. Every `settings` row is written to `storage/app/backups/settings-before-demo-seed-<time>.json` first and restored exactly afterwards. `--no-images` skips the product photos if the host's GD cannot encode WebP/AVIF. The deploy never runs it.

Preferred layout — the framework outside the web root, only `public/` published into it (the deploy rewrites the published `index.php` to the absolute app path):

```
~/domains/<domain>/laravel/      HOSTINGER_DEPLOY_PATH  (artisan, .env, storage/)
~/domains/<domain>/public_html/  HOSTINGER_PUBLIC_PATH  (document root)
```

Without `HOSTINGER_PUBLIC_PATH` the project root is assumed to be served directly; the deploy then restores `deploy/hostinger/document-root.htaccess` when the root `.htaccess` is missing.

| Secret / variable | Value |
|---|---|
| `HOSTINGER_SSH_HOST` (secret) | Host or IP; a pasted `ssh -p 65002 u123@1.2.3.4` is parsed too |
| `HOSTINGER_SSH_USER` (secret) | SSH user (`u123…`) |
| `HOSTINGER_SSH_PORT` (secret) | Defaults to `65002` |
| `HOSTINGER_SSH_KEY` (secret) | Private key whose public half is in hPanel → SSH Access |
| `HOSTINGER_DEPLOY_PATH` (secret) | Absolute Laravel root on the host |
| `HOSTINGER_PUBLIC_PATH` (secret or variable) | Absolute document root, for the split layout |
| `HOSTINGER_PHP_BIN` (variable) | CLI PHP 8.4 when not under `/opt/alt/php84/usr/bin/php` |
| `HOSTINGER_APP_URL` (variable) | API origin; enables the `GET /up` smoke test |

First deploy:

1. hPanel: set the domain's PHP to **8.4**, create the MySQL database, enable SSH and add the deploy key's public half.
2. Create `HOSTINGER_DEPLOY_PATH/.env` by hand from `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, a fresh `APP_KEY` (`php artisan key:generate --show` — **never change it**: it decrypts gateway, courier and AI credentials), `APP_URL` (https), `DB_*`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS`, the storefront URL and revalidation secret, `MAIL_*`. **Shared hosting has no Redis or Meilisearch:** set `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` to `database`. For search, either point `MEILISEARCH_HOST`/`MEILISEARCH_KEY` at a hosted Meilisearch (recommended) or set `SCOUT_DRIVER=null`: product search then runs on the app's database engine, which works but loses the Banglish misspelling tolerance (`toymart:launch:check` B4 reports it). The deploy refuses to run without `.env`, or with `APP_ENV` set to `local` or `testing` (checked before any file is sent: `DatabaseSeeder` would try to create the demo login, and the production guards would be off), and warns on debug on, a non-https `APP_URL`, Redis drivers or Meilisearch.
3. Push to `main` (or run the workflow). The first run seeds the reference data.
4. Over SSH, create the owner's super admin account as in `STAGING.md` §4, then `php artisan toymart:launch:check`.
5. hPanel → Cron Jobs, both every minute, with the 8.4 binary the deploy log printed (Horizon needs Redis and a long-running process, so on shared hosting the queue runs from cron):
   `cd <deploy path> && php artisan schedule:run` and
   `cd <deploy path> && php artisan queue:work --queue=critical,courier,notify,index,reports --stop-when-empty --max-time=55`.

`reset_database` (manual run only) drops and re-migrates every table for first-deploy recovery; it refuses once any order, customer, product or staff user exists (rule 12: orders are never deleted).

`replace_home` (manual run only, added 2026-11-24) replaces the live homepage with the seeded one (`CmsHomeBlocksSeeder` with `HOME_SEED_REPLACE=1`): the draft blocks are deleted and the seeded home is published as the **next** version, with a storefront revalidation. Earlier versions are kept, so Pages › Home › Versions restores the replaced homepage. Off a developer machine it carries the design copy only, unless `sample_home` is ticked too (owner decision 2026-11-24): then it also seeds the design's sample figures, the AI placeholder photos, the sample reels and the category tile art and short lines (blank fields only, existing category slugs only). Those figures are not the business's — the owner replaces them in Pages › Home.
