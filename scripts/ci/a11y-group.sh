#!/bin/sh
# The admin a11y suite split into four CI groups of roughly equal time (~3–4 min each), so the
# "Admin a11y" job runs on four runners at once instead of ~16 minutes on one.
#
# Playwright's own --shard splits by test COUNT; four 2-minute "main screens" sweeps would land in
# one shard and leave it as slow as before. The groups are cut by measured time instead:
#
#   A  axe.spec.ts — the English "main screens" sweeps        (2 × ~2 min)
#   B  axe.spec.ts — every other "main screens" sweep (Bangla) (2 × ~2 min)
#   C  axe.spec.ts — everything that is not a "main screens" sweep (~3 min)
#   D  every other spec file, listed at run time so a new file is included automatically (~4 min)
#
# A–C are disjoint by construction (grep / grep-invert) and D excludes axe.spec.ts. `plan` proves
# the four groups together list exactly the whole suite, so no test is ever skipped silently.
#
#   sh scripts/ci/a11y-group.sh A [playwright args…]   run one group
#   sh scripts/ci/a11y-group.sh plan                   check the partition (no browser needed)
set -e
cd "$(dirname "$0")/../.."

CONFIG=e2e/admin-a11y/playwright.config.ts
AXE=e2e/admin-a11y/axe.spec.ts
MAIN='main screens'
EN_MAIN='en · admin-[a-z]+: main screens'

others() {
    for f in e2e/admin-a11y/*.spec.ts; do
        [ "$f" = "$AXE" ] || printf '%s\n' "$f"
    done
}

run_group() {
    group=$1
    shift
    case "$group" in
        A) npx playwright test --config "$CONFIG" "$AXE" --grep "$EN_MAIN" "$@" ;;
        B) npx playwright test --config "$CONFIG" "$AXE" --grep "$MAIN" --grep-invert "$EN_MAIN" "$@" ;;
        C) npx playwright test --config "$CONFIG" "$AXE" --grep-invert "$MAIN" "$@" ;;
        # shellcheck disable=SC2046 # one argument per spec file is intended
        D) npx playwright test --config "$CONFIG" $(others) "$@" ;;
        *) echo "unknown group '$group' (A, B, C, D or plan)" >&2; exit 2 ;;
    esac
}

count() {
    # "Total: 43 tests in 6 files" -> 43 ("Total: 1 test in 1 file" too). No tests -> 0.
    "$@" --list 2>/dev/null | sed -n 's/^Total: \([0-9][0-9]*\) tests\{0,1\} in .*/\1/p' | tail -1 | grep . || echo 0
}

if [ "$1" = plan ]; then
    total=$(count npx playwright test --config "$CONFIG")
    sum=0
    for g in A B C D; do
        n=$(count run_group "$g")
        echo "group $g: $n tests"
        [ "$n" -gt 0 ] || { echo "group $g lists no tests — a title or file name changed; fix scripts/ci/a11y-group.sh" >&2; exit 1; }
        sum=$((sum + n))
    done
    echo "groups: $sum tests, whole suite: $total tests"
    [ "$sum" -eq "$total" ] || { echo "the four groups do not cover the suite exactly once — fix scripts/ci/a11y-group.sh" >&2; exit 1; }
    exit 0
fi

run_group "$@"
