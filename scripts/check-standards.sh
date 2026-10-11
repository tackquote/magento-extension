#!/usr/bin/env bash
#
# The Magento coding-standard ratchet: zero errors AND zero warnings, at every severity.
#
# Runs PHP_CodeSniffer with magento/magento-coding-standard's `Magento2` ruleset (through
# ./phpcs.xml) over the whole module and fails on ANY finding. Adobe Commerce Marketplace only
# blocks on errors; this module also refuses warnings, so the standard can only get stricter.
# Policy and the full list of checks: docs/CODING_STANDARDS.md.
#
# Usage:
#   scripts/check-standards.sh [path/to/phpcs]
#
# The phpcs binary is, in order: the first argument, $PHPCS, this module's own vendor/bin/phpcs
# (`composer install` here), or the Magento installation's vendor/bin/phpcs when the module sits
# at app/code/TackQuote/Quotes. Whichever is used must have the Magento2 standard installed
# (magento/magento-coding-standard registers it in phpcs's installed_paths).
#
# Exit status: 0 only when phpcs read the module and reported nothing. Anything else is a
# failure, including a phpcs that could not run, could not find the standard, or scanned
# suspiciously few files (a wrong path would otherwise pass as "no findings").

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MIN_FILES=150

PHPCS="${1:-${PHPCS:-}}"
if [ -z "$PHPCS" ]; then
    for candidate in "$ROOT/vendor/bin/phpcs" "$ROOT/../../../../vendor/bin/phpcs"; do
        if [ -x "$candidate" ]; then
            PHPCS="$candidate"
            break
        fi
    done
fi
if [ -z "$PHPCS" ] || [ ! -x "$PHPCS" ]; then
    echo "check-standards: no phpcs found. Pass its path, set PHPCS, or run composer install." >&2
    exit 2
fi

REPORT="$(mktemp)"
trap 'rm -f "$REPORT"' EXIT

set +e
"$PHPCS" \
    --standard="$ROOT/phpcs.xml" \
    --severity=1 \
    -q \
    -s \
    --report=full \
    --report-json="$REPORT" \
    "$ROOT"
status=$?
set -e

if [ ! -s "$REPORT" ]; then
    echo "check-standards: phpcs wrote no report (exit $status); the standard did not run." >&2
    exit 2
fi

# Totals and the number of files phpcs actually read, from its own JSON report.
read -r errors warnings files < <(php -r '
    $r = json_decode((string) file_get_contents($argv[1]), true);
    if (!is_array($r) || !isset($r["totals"], $r["files"])) { echo "x x x\n"; exit(0); }
    echo (int) $r["totals"]["errors"], " ", (int) $r["totals"]["warnings"], " ", count($r["files"]), "\n";
' "$REPORT")

if [ "$errors" = "x" ]; then
    echo "check-standards: the phpcs report could not be read." >&2
    exit 2
fi
if [ "$files" -lt "$MIN_FILES" ]; then
    echo "check-standards: phpcs read only $files files (expected at least $MIN_FILES); wrong path?" >&2
    exit 2
fi

echo "check-standards: $files files, $errors errors, $warnings warnings (phpcs exit $status)."
if [ "$status" -ne 0 ] || [ "$errors" -ne 0 ] || [ "$warnings" -ne 0 ]; then
    echo "check-standards: FAILED. The module is held to zero errors and zero warnings." >&2
    exit 1
fi
echo "check-standards: OK."
