#!/usr/bin/env bash
set -Eeuo pipefail

root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/auth"
printf '{}\n' > "$tmp/auth/config.json"
export RELEASE_VERSION=0123456789abcdef0123456789abcdef01234567
export DEPLOY_DOCKER_CONFIG="$tmp/auth"
export MOCK_LOG="$tmp/docker.log"
export MOCK_TIMEOUT_LOG="$tmp/timeout.log"
unset DEPLOY_PULL_TIMEOUT_SECONDS DEPLOY_PULL_BUDGET_SECONDS
export PATH="$tmp/bin:$PATH"

printf '#!%s\n' "$BASH" > "$tmp/bin/timeout"
cat >> "$tmp/bin/timeout" <<'SH'
printf '%s\n' "$2" >> "$MOCK_TIMEOUT_LOG"
shift 2
exec "$@"
SH
printf '#!%s\n' "$BASH" > "$tmp/bin/docker"
cat >> "$tmp/bin/docker" <<'SH'
set -eu
printf '%s\n' "$*" >> "$MOCK_LOG"
if [ "$1" = pull ]; then
  case "$MOCK_MODE" in
    denied) echo 'denied: requested access'; exit 1 ;;
    network) echo 'network timeout'; exit 124 ;;
    budget) sleep 2; echo 'network timeout'; exit 124 ;;
    budget-success) sleep 2; exit 0 ;;
    retry)
      count=$(grep -c "^pull $2$" "$MOCK_LOG")
      if [ "$count" = 1 ]; then echo 'temporary network timeout'; exit 124; fi ;;
  esac
  exit 0
fi
if [ "$1" = image ]; then
  target=${@: -1}
  case "$target" in
    task-mall-*)
      if [ "$MOCK_MODE" = existing-mismatch ]; then echo sha256:different; exit 0; fi
      if [ "$MOCK_MODE" != existing ]; then exit 1; fi ;;
  esac
  case "$4" in
    '{{.Id}}')
      case "$target" in *-web:*) echo sha256:web ;; *) echo sha256:api ;; esac ;;
    *revision*)
      if [ "$MOCK_MODE" = mismatch ] && [ "$target" = sha256:web ]; then
        echo wrong-commit
      else
        echo "$RELEASE_VERSION"
      fi ;;
    *Architecture*)
      if [ "$MOCK_MODE" = platform ]; then echo linux/arm64; else echo linux/amd64; fi ;;
    *) exit 2 ;;
  esac
  exit 0
fi
if [ "$1" = tag ]; then exit 0; fi
echo "Unexpected Docker command: $*" >&2
exit 99
SH
chmod +x "$tmp/bin/timeout" "$tmp/bin/docker"

for mode in success retry existing denied network mismatch platform existing-mismatch; do
  export MOCK_MODE=$mode
  : > "$MOCK_LOG"
  result=0
  "$BASH" "$root/scripts/pull-production-images.sh" > "$tmp/output" 2>&1 || result=$?
  case "$mode" in
    success|retry|existing)
      [ "$result" -eq 0 ] || { cat "$tmp/output"; exit 1; }
      [ "$(grep -c '^tag ' "$MOCK_LOG")" -eq 2 ]
      if [ "$mode" = retry ]; then [ "$(grep -c '^pull ' "$MOCK_LOG")" -eq 4 ]; fi ;;
    *)
      [ "$result" -ne 0 ]
      ! grep -q '^tag ' "$MOCK_LOG"
      if [ "$mode" = denied ]; then [ "$(grep -c '^pull ' "$MOCK_LOG")" -eq 1 ]; fi
      if [ "$mode" = network ]; then [ "$(grep -c '^pull ' "$MOCK_LOG")" -eq 2 ]; fi ;;
  esac
  echo "PASS: $mode"
done

grep -q '^600s$' "$MOCK_TIMEOUT_LOG"
echo 'PASS: default per-attempt timeout is 600 seconds'

: > "$MOCK_LOG"
: > "$MOCK_TIMEOUT_LOG"
export MOCK_MODE=budget
if DEPLOY_PULL_BUDGET_SECONDS=1 "$BASH" "$root/scripts/pull-production-images.sh" > "$tmp/output" 2>&1; then exit 1; fi
[ "$(grep -c '^pull ' "$MOCK_LOG")" -eq 1 ]
! grep -q '^tag ' "$MOCK_LOG"
grep -q '^1s$' "$MOCK_TIMEOUT_LOG"
grep -q 'Total image download budget exhausted' "$tmp/output"
grep -q 'Image download timed out' "$tmp/output"
echo 'PASS: shared download budget caps attempts and prevents retagging'

: > "$MOCK_LOG"
export MOCK_MODE=budget-success
if DEPLOY_PULL_BUDGET_SECONDS=1 "$BASH" "$root/scripts/pull-production-images.sh" > "$tmp/output" 2>&1; then exit 1; fi
[ "$(grep -c '^pull ' "$MOCK_LOG")" -eq 1 ]
! grep -q '^tag ' "$MOCK_LOG"
grep -q 'Total image download budget exhausted' "$tmp/output"
echo 'PASS: budget is shared across API and Web images'

for invalid in 0 -1 abc 1201 999999999999999999999; do
  : > "$MOCK_LOG"
  if DEPLOY_PULL_TIMEOUT_SECONDS="$invalid" "$BASH" "$root/scripts/pull-production-images.sh" > "$tmp/output" 2>&1; then exit 1; fi
  if DEPLOY_PULL_BUDGET_SECONDS="$invalid" "$BASH" "$root/scripts/pull-production-images.sh" > "$tmp/output" 2>&1; then exit 1; fi
  [ ! -s "$MOCK_LOG" ]
done
echo 'PASS: invalid download limits fail before Docker operations'

: > "$MOCK_LOG"
export DEPLOY_DOCKER_CONFIG="$tmp/missing"
if "$BASH" "$root/scripts/pull-production-images.sh" > "$tmp/output" 2>&1; then exit 1; fi
[ ! -s "$MOCK_LOG" ]
echo 'PASS: missing credentials fail before Docker operations'

export DEPLOY_DOCKER_CONFIG="$tmp/auth"
export RELEASE_VERSION=latest
if "$BASH" "$root/scripts/pull-production-images.sh" > "$tmp/output" 2>&1; then exit 1; fi
[ ! -s "$MOCK_LOG" ]
echo 'PASS: invalid commit fails before Docker operations'
