#!/bin/sh
# Run every PHP suite and print one summary line each.
#
# The verdict must never be better than the evidence. An earlier version grepped for the
# first line matching "passed|failed", which on test-plans.php matched a PROGRESS heading
# ("5. live generation — User #1") rather than a summary, found no "N failed" in it, and
# printed ALL GREEN over a run that had been killed by a timeout. So: a suite counts as
# passing only if it prints an explicit "N passed, 0 failed" AND exits 0. Anything else is
# reported by name, including the case where it said nothing useful at all.
cd "$(dirname "$0")/.." || exit 1

fail=0
for f in bin/test-*.php; do
  name=${f#bin/test-}
  name=${name%.php}

  out=$(php "$f" 2>&1)
  code=$?

  # The LAST line of the form "N passed, M failed". Last, not first: the suites print
  # section headers as they go and only the tail is the verdict.
  summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed$' | tail -1)

  if [ -z "$summary" ]; then
    printf '%-14s NO SUMMARY (exit %d) — last line: %s\n' \
      "$name" "$code" "$(printf '%s\n' "$out" | tail -1)"
    fail=1
    continue
  fi

  printf '%-14s %s%s\n' "$name" "$summary" \
    "$([ "$code" -ne 0 ] && printf ' (exit %d)' "$code")"

  case "$summary" in
    *', 0 failed') [ "$code" -eq 0 ] || fail=1 ;;
    *)             fail=1 ;;
  esac
done

echo "---"
[ "$fail" -eq 0 ] && echo "ALL GREEN" || echo "SOME FAILED"
