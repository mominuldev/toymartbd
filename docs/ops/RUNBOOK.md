# Toy Mart BD runbook — running the shop after launch

**For:** the owner, the person on call, and the developer you call.

**Written:** 2026-11-14, before production exists.

- No server, domain or provider account exists yet.
- Words in `<angle brackets>` are filled in once they do. See "Needs owner" at the end.

**Deploys and rollback:** `DEPLOYMENT.md`. This runbook links to it and never repeats it.

**Monitoring and alerts:** `observability.md`.

**Backups:** `backup-and-restore.md`.

**Launch checks:** `LAUNCH-CHECKLIST.md`.

---

## 0. Before anything goes wrong

**Keep these in the password manager.** Owner and one trusted person only:

| Entry | Why you need it |
|---|---|
| Server SSH login (deploy user) and the hosting control panel | Every fix below starts here |
| `APP_KEY` | Without it, saved courier, SMS and payment keys cannot be read after a restore |
| `BACKUP_ARCHIVE_PASSWORD` (and every older one, if it was ever changed) | A backup cannot be opened without the password it was made with |
| Backup bucket login | The backups live there, not on the server |
| Database root password | Restores |
| Domain registrar and DNS login | Site moves, email records |
| REVE SMS panel, Pathao or Steadfast merchant panel, SSLCommerz merchant panel | Balances, keys, support tickets |
| Sentry, the uptime monitor, Google (Search Console, GA4), Meta Business | Seeing problems; analytics |

**Phone numbers to keep on paper.** The internet may be the thing that is down. `<fill in>` each one:

- the developer;
- the hosting provider's support;
- Pathao merchant support;
- Steadfast merchant support;
- REVE SMS support;
- SSLCommerz merchant support.

**Ten-second health check.** Do it any time something feels wrong:

1. Open `https://<api-domain>/api/v1/health`. Every line should say `ok`. The first line that does not is where the problem is.
2. Open the shop `https://<shop-domain>/` and the admin `https://<api-domain>/admin`.
3. Ask the developer to run `php artisan toymart:launch:check --only=queue --only=schedule --only=backups`.

**Times are Dhaka time** throughout.

- Nightly jobs run between 01:00 and 04:30.
- The backup runs at 02:00.
- Do not deploy between 10:00 and 22:00 on busy days (Eid weeks, 11.11, Pohela Boishakh), unless it is a fix.

---

## 1. The site is down

**What you see:** the shop or the admin does not load, or the uptime monitor alerts you.

**First, work out which part is down. Each part has a different fix:**

| The shop `<shop-domain>` | The admin / API `<api-domain>` | Most likely |
|---|---|---|
| down | up | Storefront hosting (Vercel). Check its status page and the last storefront deploy. |
| up but shows "Something broke on our side" everywhere | down | The API server. Go to step 2. |
| down | down | DNS, the domain, or the whole server. Check the domain has not expired and the hosting panel shows the server running. |

**If the API server is down:**

1. Hosting panel: is the server running? If not, start it. Power or network trouble at the data centre happens. The provider's status page will say so.
2. SSH in and run `df -h`. Is a disk at 100%? If yes, see §7.
3. `sudo systemctl status nginx php8.4-fpm mysql redis-server`. Restart any that are not "active (running)", one at a time, with `sudo systemctl restart <name>`.
4. If it broke right after a deploy, **roll back** (`DEPLOYMENT.md`). Rolling back is always safe. Find the cause later.
5. Check `/api/v1/health` again. Then place a test order, COD, to your own phone.

**Planned downtime:** `php artisan down` shows the branded "Back in a few minutes" page (HTTP 503) on the API host. Bring the site back with `php artisan up`.

> **Not built yet:** a "maintenance" page for the **shop** during planned work. The page exists at `/maintenance` (`toymartbd-app/storefront/app/[locale]/maintenance/page.tsx`), but nothing routes visitors to it. Until it is wired in, the shop shows its 500 page while the API is down. Customers can still phone the hotline on that page.

## 2. Checkout is failing

**What you see:**

- customers call to say "order did not go through";
- orders stop coming in (business alert: "no orders in N business hours");
- the 500 page shows on checkout.

1. **Place a COD order yourself** on your phone, in both languages. If it works, the problem is narrower. Ask the customer which payment method they used and which area they deliver to.
2. **Online payment only failing?** Turn the online gateway off with `php artisan toymart:payments:gateway sslcommerz --deactivate`. Checkout then offers only COD, which always works. Then see §5.
3. **"Price changed" or "out of stock" messages** are correct behaviour. The cart changed under the customer.
4. **Everything failing:**
   - check `/api/v1/health`;
   - check Sentry for the newest error;
   - **roll back** if it started after a deploy.
   Give the developer the **reference / request id** shown on the error page or in Sentry.
5. **Many "out of stock" errors on items you have:** stock drift. Ask the developer to run `php artisan toymart:inventory:reconcile`. It reports drift and never "fixes" it silently.

While checkout is broken, put the hotline at the top of the Facebook page and take orders by phone. Admin → Orders → **New order** (`/admin/orders/new`) places a phone order through the same checkout rules.

## 3. Courier booking is failing

**What you see:**

- orders stuck as "not booked";
- **Admin → Shipments → Failed bookings** (`/admin/shipments/failed`) is filling up;
- the business alert "failed bookings spike".

1. Open **Failed bookings**. The reason column says what happened:

   | Reason | What it means and what to do |
   |---|---|
   | **Area not mapped** | The customer's area has no courier zone. **Admin → Shipments → Courier locations** (`/admin/shipments/locations`): map it by hand, then retry. Never guess a zone. A wrong zone sends the parcel to the wrong hub (rule 32). |
   | **Provider not verified** | Steadfast is blocked in production until its live test passes (rule 33a). Use the verified courier, or ship by hand: **Mark shipped**. |
   | **Insufficient balance** | Top up in the courier's merchant panel. |
   | **Provider unavailable / circuit open** | The courier's API is down. The system stops calling it for a while. Check the courier's status page or phone support. Retry later, or book in their merchant panel and record the consignment by hand. |

2. **Bulk booking shows "submitted", not "booked".** This is normal (rule 24d). Neither courier returns consignment ids for bulk orders. They appear when the courier's webhook arrives or the 15-minute poll runs. Worry only if they are still "submitted" after an hour. Then ask the developer to run `php artisan toymart:couriers:poll`.
3. **Never book the same order twice by hand** while one is "submitted". Two riders may come for one parcel. Check the courier's merchant panel first.

## 4. SMS is not sending

**What you see:**

- customers do not get the OTP or order SMS;
- the business alert "SMS failures";
- **Admin → Automations → Message log** (`/admin/automations/log`) shows Failed.

1. **REVE balance.** Log in to the REVE panel. An empty balance is the most common cause. Top up.
2. **Send a test:** Admin → Integrations → Messaging → **Send test SMS** to your own number, once in English and once in Bangla.
3. **Test fails with "rejected":**
   - Check the API key, secret key and sender ID on that screen. The sender is your non-masking number, digits only.
   - If REVE changed your keys, enter the new ones. They take effect on the next SMS. No restart is needed.
4. **Test works but customers say nothing arrives:** some operators delay bulk SMS. Ask REVE support with the time and the masked number from the message log.
5. **Failed messages** retry 3 times, then wait in the log. After fixing the cause, use **Retry** on each one.
6. **OTP never goes by WhatsApp** (rule 32b). If SMS is down, customers cannot sign in. Guest checkout still works without OTP.

**WhatsApp must stay OFF** (Admin → Integrations → Messaging) until a real WhatsApp Business account is connected. With it on and no account, order updates are silently dropped.

## 5. Online payment stuck (IPN not arriving or "payment pending")

**What you see:**

- a customer paid by bKash, Nagad or card through SSLCommerz, but the order says "payment pending";
- or the business alert "webhook silence".

1. Open the order in the admin and press **Re-check with gateway**. This asks SSLCommerz directly for the true amount and status. The system never trusts the IPN message alone (rule 19).
2. Unpaid online orders are re-checked and then expired automatically after the payment window: 30 minutes, a placeholder value. Expiry releases the stock.
3. **Paid but the order was cancelled by expiry:** the money arrived after the window. The order is flagged for review. Refund it from the order screen, or call the customer and re-place the order as paid. Do not edit the ledger by hand.
4. **Many orders stuck at once:**
   - check the SSLCommerz merchant panel and status;
   - ask the developer to check that Horizon is running (§6);
   - check that the IPN URL in the SSLCommerz panel is still `https://<api-domain>/api/v1/webhooks/payments/sslcommerz/ipn`;
   - if needed, turn online payment off (§2 step 2) until it is sorted.

> Online payments are not live yet: the gateway is seeded **inactive**, and the owner has not chosen it (Gate 1 decision 5). This section applies once it is.

## 6. Queue backed up (things happen late or never)

**What you see:**

- SMS, emails, courier bookings or search updates come late or never;
- `/api/v1/health` shows the queue not `ok`;
- the business alert "jobs failing".

1. The developer runs `php artisan horizon:status`.
   - **Inactive:** start Horizon under its process supervisor (`DEPLOYMENT.md`).
   - **Paused:** run `php artisan horizon:continue`.
2. `php artisan horizon:supervisors`: there should be 7, one per queue (critical, courier, notify, tracking, index, reports, default).
3. **Jobs not running at all, including nightly ones?** The scheduler stopped. `php artisan toymart:launch:check --only=schedule` shows how old its last heartbeat is. Re-add the cron line (`DEPLOYMENT.md`).
4. **A flood of one kind of job** (for example after a big catalog import): it drains on its own. Watch `/horizon` if the developer has enabled it, or the health endpoint.
5. **Failed jobs:** `php artisan queue:failed`. Fix the cause before `php artisan queue:retry all`. Retrying a courier booking that failed for a real reason only fails again.

## 7. Disk full

**What you see:** errors everywhere, uploads failing, MySQL stopping. `df -h` shows 100%.

1. Find what grew: `sudo du -xh / --max-depth=2 2>/dev/null | sort -h | tail -20`. The usual suspects:
   - **old releases:** the deploy keeps the last few; remove older ones per `DEPLOYMENT.md`;
   - **logs** (`storage/logs`, `/var/log`): `sudo journalctl --vacuum-time=7d`, rotate or compress old app logs;
   - **leftover backup temp files** (`storage/app/backup-temp`): safe to delete when no backup is running;
   - **MySQL binary logs:** never delete them by hand; ask the developer (`PURGE BINARY LOGS`).
2. **Never delete** `storage/app/private`, `storage/app/public` (product photos and invoices), or the database files.
3. Restart MySQL and PHP-FPM if they stopped. Then check `/api/v1/health`.
4. Afterwards, ask the hosting provider for a bigger disk, or move media to object storage.

## 8. Restore from backup

Use this when data is lost or corrupted (a bad import, a mistaken bulk change, a dead server). **Decide first whether you need it.** A restore loses every order placed since the backup. One mistaken change is usually better fixed by hand.

1. **Stop new writes:** `php artisan down`. Take orders by phone and write them down.
2. **Take a backup of the current state anyway:** `php artisan toymart:backup`. You may need something from it later.
3. Follow `backup-and-restore.md`:
   - "Restore rehearsal" proves the archive is good;
   - then "Manual restore" puts it back.
   You need `BACKUP_ARCHIVE_PASSWORD` and the **same** `APP_KEY` as when the backup was taken.
4. Run `php artisan migrate --force` and `php artisan optimize`. Then restart Horizon (`DEPLOYMENT.md`).
5. Re-enter the phone orders taken while the site was down. Check the courier panels for parcels booked after the backup time.
6. Run `php artisan toymart:finance:verify` and `php artisan toymart:inventory:reconcile`. Both must pass.
7. Run `php artisan up`.

**Target:** working again within 4 hours (RTO). Data loss is up to the last backup, 24 hours, until hourly binary-log shipping is set up (`backup-and-restore.md` "Point-in-time recovery", not built yet).

**Monthly drill:** `php artisan toymart:backup:restore-rehearsal --against=manifest`. `toymart:launch:check` fails if the last passing drill is more than 35 days old.

## 9. A password or key leaked — rotate it

**Treat it as leaked if:** it was pasted in a chat, emailed, shown on a screen-share, committed to code, or a staff member who knew it left.

| Leaked | Do this | Then |
|---|---|---|
| A staff member's password | Make them sign in and change it, or deactivate them (§10) | Their active sessions end when deactivated |
| SMS (REVE) keys | Generate new keys in the REVE panel. Enter them at Admin → Integrations → Messaging. | Send a test SMS |
| Courier keys (Pathao, Steadfast) | New keys in the merchant panel. Enter them at Admin → Integrations → Couriers → Credentials. | Book one test parcel |
| SSLCommerz store password | Change it in the merchant panel, then in the server env (`SSLCOMMERZ_*`) | `php artisan config:cache` |
| Storefront server token | `php artisan toymart:storefront-token --rotate`. Put the new value in the storefront's `STOREFRONT_API_TOKEN` and redeploy the storefront. | The shop shows errors until the new token is in place. Do it in one sitting. |
| `STOREFRONT_REVALIDATE_SECRET` | New value (`openssl rand -hex 32`) in **both** the API env and the storefront env | `php artisan config:cache`, redeploy the storefront |
| Database or Redis password | Change it in MySQL or Redis, then in the env | `php artisan config:cache`, restart PHP-FPM and Horizon |
| `BACKUP_ARCHIVE_PASSWORD` | New value in the env. **Keep the old one in the password manager**: older backups still need it. | Next backup uses the new one |
| `APP_KEY` | **Call the developer. Never just change it.** Every saved courier, SMS and payment key, and every session, depends on it. A rotation means re-entering all integration keys afterwards. | — |
| Server SSH key | Remove the key from `~/.ssh/authorized_keys` on the server, add a new one | Check the hosting panel's login history |

After any rotation, run `php artisan toymart:launch:check`. It must not show new FAILs.

## 10. Add or remove a staff member

Use **Admin → Settings → Staff & roles** (`/admin/staff`). Only an owner (`super_admin`) can add or change staff; a manager can see the list but not change it. Every role change, deactivation and password reset is recorded in the audit log.

**Roles, narrowest that fits** (the **Roles & permissions** tab shows exactly what each one can do):

| Role | Who |
|---|---|
| `manager` | Runs the day, everything except staff and roles |
| `order_operator` | Confirms orders, calls customers, books couriers |
| `inventory_staff` | Receives stock, counts, adjusts |
| `accountant` | Finance and reports |
| `content_editor` | Products, pages, blog |
| `viewer` | Looks, changes nothing |
| `super_admin` | The owner only |

**Add.** Press **Add staff**, enter the name, email, role and a temporary password (at least 12 characters). Give the password in person or by phone, never in the same message as the email. Ask them to change it after their first sign-in.

**Remove** (someone leaves). Do it the same day: open their row (**…**) → **Deactivate** → confirm.

- An inactive account cannot sign in. Its current admin session is refused on the next request, and its API tokens are revoked.
- Staff are never deleted: orders and the audit log keep their name. **Reactivate** brings the account back.
- If they knew any shared key (§9), rotate it.

**Change a role or reset a password:** open their row (**…**). A new temporary password signs their other devices out of the API.

**What the screen refuses, on purpose:** changing your own role, status or password, and anything that would leave the shop with no active owner. Make a second owner first. If the last owner is locked out, the developer can run, on the server:

```bash
php artisan tinker --execute '
  $u = App\Models\User::where("email", "EMAIL")->firstOrFail();
  $u->forceFill(["is_active" => true, "password" => "A-LONG-TEMPORARY-PASSWORD"])->save(); $u->syncRoles(["super_admin"]);'
```

Not built yet: two-factor sign-in, per-staff branches, a list of active sessions, shifts, a staff self-service "change my password", and editing what a role can do (roles are defined in code; a change goes through the developer).

---|---|
| `manager` | Runs the day, everything except staff and roles |
| `order_operator` | Confirms orders, calls customers, books couriers |
| `inventory_staff` | Receives stock, counts, adjusts |
| `accountant` | Finance and reports |
| `content_editor` | Products, pages, blog |
| `viewer` | Looks, changes nothing |
| `super_admin` | The owner only |

**Add.** On the server, from the current release:

```bash
php artisan tinker --execute '
  $u = App\Models\User::create(["name" => "FULL NAME", "email" => "EMAIL", "password" => bcrypt("A-LONG-TEMPORARY-PASSWORD")]);
  $u->assignRole("order_operator");'
```

Give the temporary password in person or by phone, never in the same message as the email. Ask them to change it on first sign-in.

**Remove** (someone leaves). Do it the same day:

```bash
php artisan tinker --execute '
  $u = App\Models\User::where("email", "EMAIL")->firstOrFail();
  $u->forceFill(["is_active" => false])->save(); $u->tokens()->delete();'
```

- An inactive account cannot sign in. Its current admin session is refused on the next request.
- Do not delete the user: orders and the audit log keep their name.
- If they knew any shared key (§9), rotate it.

**Change a role:** `$u->syncRoles(["accountant"]);` in the same way.

---

## Needs owner

Fill these in before launch:

- hosting provider and support number;
- the API and shop domains;
- the backup bucket;
- the password-manager entries in §0;
- the provider support numbers;
- who is on call, and who holds the second copy of the passwords;
- the busy days with no deploys (Eid dates, sale days);
- whether Horizon's dashboard should be reachable in production (audit S-14);
- storefront token rotation schedule (S-13).
