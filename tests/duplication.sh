#!/usr/bin/env bash
# Development-only audit; this never loads Dolibarr or a database.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

cdav_cpd_mode="${1:-check}"
case "$cdav_cpd_mode" in
    check|report|tests|update-baseline) ;;
    *) printf '%s\n' 'Usage: bash tests/duplication.sh [check|report|tests|update-baseline]' >&2; exit 2 ;;
esac

if [[ -n "${CDAV_JSCPD_BIN:-}" ]]; then
    cdav_cpd_command=("$CDAV_JSCPD_BIN")
elif command -v jscpd > /dev/null 2>&1; then
    cdav_cpd_command=(jscpd)
elif command -v npx > /dev/null 2>&1; then
    cdav_cpd_command=(npx --yes --package=jscpd@5.4.3 jscpd)
else
    printf '%s\n' 'Install jscpd 5.4.3 or provide CDAV_JSCPD_BIN; npm/npx can install this development tool.' >&2
    exit 2
fi
if [[ "$("${cdav_cpd_command[@]}" --version)" != 'jscpd 5.4.3' ]]; then
    printf '%s\n' 'This audit requires jscpd 5.4.3 for reproducible fingerprints.' >&2
    exit 2
fi

cdav_cpd_options=(--config .jscpd.json --workers 2 --no-colors --no-tips --fail-on-empty)
case "$cdav_cpd_mode" in
    check) cdav_cpd_options+=(--baseline .jscpd-baseline.json --fail-on-new-clones 0 --threshold 0) ;;
    report) ;;
    tests) cdav_cpd_options=(--config tests/jscpd-tests.json --workers 2 --no-colors --no-tips --fail-on-empty) ;;
    update-baseline) cdav_cpd_options+=(--baseline .jscpd-baseline.json --update-baseline) ;;
esac
"${cdav_cpd_command[@]}" "${cdav_cpd_options[@]}"
