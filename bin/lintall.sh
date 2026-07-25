#!/usr/bin/env bash
# Parse-check every PHP file and print the real error message.
# php -l writes its detail to stdout but exits non-zero; some shells and
# wrappers swallow it, so this captures both explicitly.
cd "$(dirname "$0")/.."
fail=0
for f in src/*.php src/lib/*.php src/routes/*.php bin/*.php public_html/api/*.php; do
    [ -f "$f" ] || continue
    if ! out=$(php -l "$f" 2>&1); then
        printf '%s\n%s\n\n' "FAIL $f" "$out"
        fail=1
    fi
done
[ "$fail" -eq 0 ] && echo "all files parse cleanly"
exit "$fail"
