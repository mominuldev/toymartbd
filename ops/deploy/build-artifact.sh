#!/usr/bin/env bash
# Package the API + admin release artifact (docs/ops/DEPLOYMENT.md §4).
#
#   ops/deploy/build-artifact.sh <sha> <out.tar.gz> [source-dir]
#
# Runs in CI after `npm ci && npm run build`: the admin bundle (public/build) is built there and
# shipped inside the artifact — the server never runs Node. vendor/ is NOT included; the server
# runs `composer install --no-dev` against composer.lock, so the artifact stays small and the
# PHP extensions are checked on the machine that will load them.
#
# Never packed: .env (secrets live only in shared/.env on the host), storage/ (shared between
# releases), tests, docs, node_modules, the storefront (Vercel), local SQLite files.

set -Eeuo pipefail

sha=${1:-}
out=${2:-}
src=${3:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}

[[ $sha =~ ^[0-9a-f]{7,40}$ ]] || { echo "usage: $0 <sha> <out.tar.gz> [source-dir]" >&2; exit 2; }
[[ -n $out ]] || { echo "usage: $0 <sha> <out.tar.gz> [source-dir]" >&2; exit 2; }
[[ -f $src/artisan ]] || { echo "not a Laravel root: $src" >&2; exit 1; }
[[ -f $src/public/build/manifest.json ]] || { echo "public/build/manifest.json missing — run 'npm run build' first" >&2; exit 1; }
[[ -f $src/packages/tokens/dist/tokens.json ]] || { echo "packages/tokens/dist/tokens.json missing (MailTheme reads it)" >&2; exit 1; }

# Everything the running app reads. Add a path here when the app starts reading a new one.
paths=(
    app artisan bootstrap composer.json composer.lock config database lang public routes
    resources/views resources/fonts
    packages/tokens/dist
    ops scripts/staging
)
for p in "${paths[@]}"; do
    [[ -e $src/$p ]] || { echo "missing $p in $src" >&2; exit 1; }
done

meta=$(mktemp -d)
trap 'rm -rf "$meta"' EXIT
printf '%s\n' "$sha" > "$meta/REVISION"

mkdir -p "$(dirname "$out")"
# COPYFILE_DISABLE: no macOS ._ metadata files when this runs on a Mac (local rehearsal).
COPYFILE_DISABLE=1 tar -czf "$out" \
    --exclude='.DS_Store' \
    --exclude='*.log' \
    --exclude='node_modules' \
    --exclude='public/hot' \
    --exclude='public/storage' \
    --exclude='public/fonts-manifest.dev.json' \
    --exclude='bootstrap/cache/*.php' \
    --exclude='database/*.sqlite' \
    --exclude='database/*.sqlite-*' \
    -C "$src" "${paths[@]}" \
    -C "$meta" REVISION

echo "artifact: $out ($(du -h "$out" | cut -f1), revision $sha)"
