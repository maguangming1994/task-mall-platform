#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077

# Run interactively as the OS user running the production receiver.
export DOCKER_CONFIG="${DEPLOY_DOCKER_CONFIG:-/var/lib/task-mall/tcr}"
command -v docker >/dev/null
install -d -m 700 "$DOCKER_CONFIG"
read -r -p 'TCR username from the console login command: ' registry_user </dev/tty
[ -n "$registry_user" ] || { echo 'TCR username is required.' >&2; exit 1; }
trap 'unset registry_password' EXIT
read -r -s -p 'TCR personal registry password: ' registry_password </dev/tty
printf '\n' >/dev/tty
[ -n "$registry_password" ] || { echo 'TCR password is required.' >&2; exit 1; }
printf '%s' "$registry_password" | docker login ccr.ccs.tencentyun.com --username "$registry_user" --password-stdin
unset registry_password
chmod 600 "$DOCKER_CONFIG/config.json"
echo "TCR login saved to $DOCKER_CONFIG. No application secret or running service was changed."
