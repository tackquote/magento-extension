#!/usr/bin/env bash
#
# Build the installable Magento 2 artifact, reproducibly: dist/tack-magento2.zip.
#
# Split out of the hub repository's scripts/package-all.sh
# (https://github.com/tackquote/tack-ecommerce-extensions) when this module moved to
# its own repository. The published v1.1.0 asset once had a `magento2/` top-level
# directory instead of `Vendor/Module/`, so Magento could never discover the
# module. The manual-install path is app/code/<Vendor>/<Module>/, so the artifact
# carries the Vendor/Module nesting itself and the user cannot lose it:
#   unzip tack-magento2.zip -d <magento-root>/app/code
#
# Usage: scripts/package.sh [outdir]     (default: dist)

set -Eeuo pipefail

OUT="${1:-dist}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
rm -rf "$OUT" && mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Excluded from every artifact. `-x` patterns are matched by zip against the
# paths as it stores them, so they are relative to the staged tree.
COMMON_EX=( -x '*/.git/*' -x '*/.gitignore' -x '*/.DS_Store' -x '*/__MACOSX/*' -x '*/._*' )

say() { printf '  %s
' "$*"; }

# stage_repo <dest> -- copy this repository's working tree to $STAGE/<dest>,
# minus the repository scaffolding that was never part of the extension when it
# lived in the hub monorepo (git metadata, build output, this script, the CI
# workflows, the repo-level LICENSE and .gitignore). Keeps the
# artifact to the same file set the monorepo's package-all.sh shipped.
stage_repo() {
  local d="$STAGE/$1"
  rm -rf "$d"; mkdir -p "$(dirname "$d")"
  cp -R "$ROOT" "$d"
  rm -rf "$d/.git" "$d/dist" "$d/scripts" "$d/.github/workflows" "$d/LICENSE" "$d/.gitignore" "$d/vendor"
  rmdir "$d/.github" 2>/dev/null || true
  find "$d" -name '.DS_Store' -delete 2>/dev/null || true
}

pack() { # pack <zipname> <top-level-dir> [extra zip -x args...]
  local name="$1" top="$2"; shift 2
  ( cd "$STAGE" && zip -q -r -X "$OUT/$name" "$top" "${COMMON_EX[@]}" "$@" )
  say "$name  $(wc -c < "$OUT/$name" | tr -d ' ') bytes"
}

say "magento2"
stage_repo TackQuote_m2/TackQuote/Quotes
( cd "$STAGE/TackQuote_m2" && zip -q -r -X "$OUT/tack-magento2.zip" TackQuote \
    -x 'TackQuote/Quotes/Test/*' -x 'TackQuote/Quotes/phpunit*' -x '*/.DS_Store' )
say "tack-magento2.zip  $(wc -c < "$OUT/tack-magento2.zip" | tr -d ' ') bytes"

echo
echo "artifacts in $OUT:"
ls -1 "$OUT"
