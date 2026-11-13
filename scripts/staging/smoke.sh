#!/usr/bin/env bash
# Staging smoke check — docs/ops/STAGING.md §8.
#
# Run on the staging host, from the Laravel root, as the deploy user, after every deploy
# and before every UAT session:
#
#   APP_URL=https://staging-api.example.test \
#   SITE_URL=https://staging.example.test \
#   STOREFRONT_API_TOKEN=tmbd_… \
#   scripts/staging/smoke.sh
#
# Checks, in order (each prints PASS / WARN / FAIL):
#   1. GET /up                         Laravel boots
#   2. GET /api/v1/health              database, cache (Redis), queue, Meilisearch all "ok"
#   3. Horizon running, and a running supervisor for every queue (skip: SMOKE_SKIP_HORIZON=1)
#   4. Scheduler                       every expected toymart:* task is registered; the
#                                      finance rollup ran for yesterday (WARN on a new install)
#   5. Meilisearch index lag           `artisan toymart:search:lag-check`
#   6. Storefront token                GET /api/v1/storefront/districts with the bearer token
#                                      (an authenticated read; /ping is public and proves nothing)
#   7. Storefront site                 GET SITE_URL/ and SITE_URL/bn/ (skip when SITE_URL unset)
#   8. Invariant: stock                `artisan toymart:inventory:reconcile`
#   9. Invariant: finance              `artisan toymart:finance:verify`
#
# Exit 0 when nothing FAILed (WARNs allowed), 1 otherwise. Read-only except for what the
# commands themselves record: reconcile logs a run and raises an alert on drift; nothing
# is ever corrected. Never prints the token, a phone number or an address.

set -uo pipefail

PHP_BIN="${PHP_BIN:-php}"
ARTISAN="${ARTISAN:-artisan}"
APP_URL="${APP_URL:-}"
SITE_URL="${SITE_URL:-}"
STOREFRONT_API_TOKEN="${STOREFRONT_API_TOKEN:-}"
CURL_TIMEOUT="${CURL_TIMEOUT:-10}"

fails=0
warns=0

pass() { printf '  PASS  %s\n' "$1"; }
warn() { printf '  WARN  %s\n' "$1"; warns=$((warns + 1)); }
fail() { printf '  FAIL  %s\n' "$1"; fails=$((fails + 1)); }

artisan() { "$PHP_BIN" "$ARTISAN" "$@" 2>&1; }

# Reads APP_URL from the app's own config when not given, so the script needs no .env parsing.
if [[ -z "$APP_URL" ]]; then
    APP_URL="$(artisan tinker --execute 'echo config("app.url");' | tail -n 1)"
fi
APP_URL="${APP_URL%/}"
SITE_URL="${SITE_URL%/}"

http_code() { curl -s -o /dev/null -m "$CURL_TIMEOUT" -w '%{http_code}' "$@"; }

echo "Toy Mart BD staging smoke — API ${APP_URL}${SITE_URL:+, storefront ${SITE_URL}}"

# 1. Laravel boots.
code="$(http_code "$APP_URL/up")"
[[ "$code" == "200" ]] && pass "GET /up → 200" || fail "GET /up → ${code:-no answer}"

# 2. Dependencies, through the public health endpoint (no secrets in it).
health="$(curl -s -m "$CURL_TIMEOUT" -H 'Accept: application/json' "$APP_URL/api/v1/health")"
if [[ -z "$health" ]]; then
    fail "GET /api/v1/health — no answer"
else
    summary="$(printf '%s' "$health" | "$PHP_BIN" -r '
        $j = json_decode(stream_get_contents(STDIN), true);
        if (! is_array($j) || ! isset($j["checks"])) { echo "unparseable"; exit; }
        $out = [];
        foreach ($j["checks"] as $name => $c) {
            $out[] = $name."=".($c["status"] ?? "?").(isset($c["size"]) ? "(queue ".$c["size"].")" : "");
        }
        echo ($j["status"] ?? "?")." ".implode(" ", $out);
    ')"
    case "$summary" in
        ok\ *)
            pass "health: $summary" ;;
        *)
            # "skipped" Meilisearch means SCOUT_DRIVER is not meilisearch — wrong for staging.
            fail "health: $summary" ;;
    esac
    [[ "$summary" == *"meilisearch=skipped"* ]] && fail "Meilisearch check skipped — set SCOUT_DRIVER=meilisearch on staging"
fi

# 3. Queue workers (Horizon supervises critical,courier,notify,index,reports).
if [[ "${SMOKE_SKIP_HORIZON:-0}" == "1" ]]; then
    warn "Horizon check skipped (SMOKE_SKIP_HORIZON=1)"
else
    status="$(artisan horizon:status)"
    if [[ "$status" == *"is running"* ]]; then
        pass "Horizon: running"
    else
        fail "Horizon: ${status//$'\n'/ }"
    fi
    # Every queue the app dispatches to needs a running supervisor (config/horizon.php).
    supervisors="$(artisan horizon:supervisors)"
    for queue in critical courier notify tracking index reports default; do
        if printf '%s\n' "$supervisors" | grep -E "running" | grep -q "redis:${queue} "; then
            pass "Horizon works the ${queue} queue"
        else
            fail "no running Horizon supervisor for the ${queue} queue"
        fi
    done
fi

# 4. Scheduler: every task registered (the cron entry itself is host config — STAGING.md §6).
list="$(artisan schedule:list)"
for task in toymart:backup toymart:inventory:reconcile toymart:inventory:digest \
            toymart:finance:recurring-expenses toymart:finance:rollup toymart:finance:verify \
            toymart:payments:expire toymart:couriers:poll \
            toymart:content:revalidate-scheduled toymart:promotions:boundaries \
            toymart:carts:recover toymart:blog:publish-due; do
    if [[ "$list" == *"$task"* ]]; then
        pass "schedule: $task registered"
    else
        fail "schedule: $task missing from schedule:list"
    fi
done
# Scheduled content is ONE sweep (blocks, banners, pages); the retired blocks-only command
# must not come back as a second every-minute task.
if grep -Eq 'toymart:content:revalidate-schedule([^d]|$)' <<<"$list"; then
    fail "schedule: retired toymart:content:revalidate-schedule is still registered"
else
    pass "schedule: one scheduled-content sweep"
fi
# Evidence the cron actually fires: yesterday's (Dhaka) rollup row, written at 01:00 Dhaka.
rolled="$(artisan tinker --execute '
    $d = Carbon\CarbonImmutable::now("Asia/Dhaka")->subDay()->toDateString();
    echo Illuminate\Support\Facades\DB::table("daily_financial_summaries")->whereDate("summary_date", $d)->exists() ? "yes" : "no";
' | tail -n 1)"
if [[ "$rolled" == "yes" ]]; then
    pass "scheduler fired: yesterday's finance rollup exists"
else
    warn "no finance rollup for yesterday — expected on day one; on any later day the cron is not running"
fi

# 5. Search index freshness.
lag="$(artisan toymart:search:lag-check)"; rc=$?
[[ $rc -eq 0 ]] && pass "search index lag: ${lag//$'\n'/ }" || fail "search index lag: ${lag//$'\n'/ }"

# 6. Storefront server token (the token value is never printed).
if [[ -z "$STOREFRONT_API_TOKEN" ]]; then
    warn "STOREFRONT_API_TOKEN not set — storefront token not checked (mint: php artisan toymart:storefront-token)"
else
    code="$(http_code -H "Authorization: Bearer $STOREFRONT_API_TOKEN" -H 'Accept: application/json' "$APP_URL/api/v1/storefront/districts")"
    [[ "$code" == "200" ]] && pass "storefront token accepted (GET /api/v1/storefront/districts → 200)" \
        || fail "storefront token refused (GET /api/v1/storefront/districts → ${code:-no answer})"
fi

# 7. The storefront itself, both locales.
if [[ -z "$SITE_URL" ]]; then
    warn "SITE_URL not set — storefront pages not checked"
else
    for path in "/" "/bn/"; do
        code="$(http_code -L "$SITE_URL$path")"
        [[ "$code" == "200" ]] && pass "storefront GET $path → 200" || fail "storefront GET $path → ${code:-no answer}"
    done
fi

# 8–9. The two correctness invariants (CLAUDE.md working practice). Never auto-corrected.
out="$(artisan toymart:inventory:reconcile)"; rc=$?
[[ $rc -eq 0 ]] && pass "invariant: stock movements sum to stock levels" || fail "invariant: stock drift — ${out//$'\n'/ }"
out="$(artisan toymart:finance:verify)"; rc=$?
[[ $rc -eq 0 ]] && pass "invariant: order financials equal the ledger" || fail "invariant: finance drift — ${out//$'\n'/ }"

echo "Done: ${fails} fail(s), ${warns} warning(s)."
[[ $fails -eq 0 ]]
