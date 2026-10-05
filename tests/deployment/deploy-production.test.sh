#!/usr/bin/env bash
set -Eeuo pipefail

root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/scripts" "$tmp/infra"
cp "$root/scripts/deploy-production.sh" "$tmp/scripts/"
cp "$root/infra/compose.production.yaml" "$tmp/infra/"
printf ':\n' > "$tmp/scripts/pull-production-images.sh"
touch "$tmp/infra/.env.production"
export RELEASE_VERSION=0123456789abcdef0123456789abcdef01234567
export MOCK_LOG="$tmp/commands.log"

docker() {
  printf '%s\n' "$*" >> "$MOCK_LOG"
  if [ "$1" = inspect ]; then
    case "$3" in
      *Running*) echo true ;;
      *Health*) echo healthy ;;
      *) return 99 ;;
    esac
    return
  fi
  [ "$1" = compose ] || return 99
  shift
  [ "$1" = --env-file ] || return 99
  shift 2
  [ "$1" = -f ] || return 99
  local config=$2
  shift 2
  case "$1" in
    config)
      # Both the API anchor and Web must remain local-only.
      [ "$(grep -c 'pull_policy: never' "$config")" -eq 2 ] ;;
    run)
      if [[ " $* " == *' --pull '* ]]; then
        echo 'unknown flag: --pull' >&2
        return 16
      fi
      [[ "$*" == 'run --rm --no-deps api php artisan '* || "$*" == 'run --rm --no-deps api timeout 600 php artisan '* ]] || return 99
      if [ "$MOCK_MODE" = migration-failure ]; then return 42; fi ;;
    up|exec) return 0 ;;
    ps) if [ "${2:-}" = -q ]; then echo container; fi ;;
    *) return 99 ;;
  esac
}
curl() {
  case "$*" in
    *release.json*) printf '{"release":"%s"}\n' "$RELEASE_VERSION" ;;
    *api/v1/campuses*) printf '{"code":"moe-4111010001","emblem_url":"https://example.invalid/emblem.png"}\n' ;;
  esac
  return 0
}
export -f docker curl

for mode in routine sync supplement migration-failure; do
  export MOCK_MODE=$mode SYNC_CAMPUS_DATA_ON_DEPLOY=false
  if [ "$mode" = sync ]; then export SYNC_CAMPUS_DATA_ON_DEPLOY=true; fi
  if [ "$mode" = supplement ]; then
    mkdir -p "$tmp/apps/api/database/data/campus-emblems-supplement"
    printf '{}\n' > "$tmp/apps/api/database/data/campus-emblems-supplement/manifest.json"
  fi
  : > "$MOCK_LOG"
  result=0
  "$BASH" "$tmp/scripts/deploy-production.sh" > "$tmp/output" 2>&1 || result=$?
  if [ "$mode" = migration-failure ]; then
    [ "$result" -eq 42 ]
    ! grep -q -- '--remove-orphans' "$MOCK_LOG"
  else
    if [ "$result" -ne 0 ]; then cat "$tmp/output"; exit 1; fi
    grep -q -- '--remove-orphans' "$MOCK_LOG"
    # routine: migrate + RbacSeeder + ReleaseControlSeeder = 3
    # sync:    再加 campuses:sync-directory + campuses:sync-branding = 5
    expected=3
    if [ "$mode" = sync ]; then expected=5; fi
    [ "$(grep -c 'run --rm --no-deps api php artisan' "$MOCK_LOG")" -eq "$expected" ]
    if [ "$mode" = supplement ]; then
      grep -q 'timeout 600 php artisan campuses:sync-branding --supplement --once' "$MOCK_LOG"
    fi
  fi
  echo "PASS: legacy Compose run compatibility ($mode)"
done
