#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Run interactively as the same OS user as the restricted deployment receiver.
export DOCKER_CONFIG="${DEPLOY_DOCKER_CONFIG:-/var/lib/task-mall/ghcr}"
command -v docker >/dev/null
install -d -m 700 "$DOCKER_CONFIG"
read -r -p 'GitHub username [maguangming1994]: ' registry_user </dev/tty
registry_user=${registry_user:-maguangming1994}
read -r -s -p 'GitHub classic token (read:packages only): ' registry_token </dev/tty
printf '\n' >/dev/tty
trap 'unset registry_token' EXIT
printf '%s' "$registry_token" | docker login ghcr.io --username "$registry_user" --password-stdin
unset registry_token
chmod 600 "$DOCKER_CONFIG/config.json"
echo "GHCR login saved to $DOCKER_CONFIG. No application secret was changed."
