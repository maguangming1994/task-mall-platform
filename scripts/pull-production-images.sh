#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_VERSION:?Set RELEASE_VERSION to the tested commit}"
[[ "$RELEASE_VERSION" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release commit' >&2; exit 1; }

readonly IMAGE_PREFIX=ghcr.io/maguangming1994/task-mall-platform
export DOCKER_CONFIG="${DEPLOY_DOCKER_CONFIG:-/var/lib/task-mall/ghcr}"
if [ ! -r "$DOCKER_CONFIG/config.json" ]; then
  echo 'GHCR login missing. Run scripts/server/configure-ghcr.sh as the deployment user first.' >&2
  exit 1
fi
command -v timeout >/dev/null || { echo 'GNU timeout is required' >&2; exit 1; }

pull_log=$(mktemp)
trap 'rm -f "$pull_log"' EXIT
declare -A image_ids

for kind in api web; do
  image="$IMAGE_PREFIX-$kind:$RELEASE_VERSION"
  pulled=false
  for attempt in 1 2; do
    echo "Pulling $image (attempt $attempt/2, limit 240s)"
    if timeout --kill-after=10s 240s docker pull "$image" 2>&1 | tee "$pull_log"; then
      pulled=true
      break
    fi
    if grep -Eiq 'unauthorized|denied|forbidden|manifest unknown' "$pull_log"; then
      echo 'GHCR rejected the image request. Check read:packages access and the image build job.' >&2
      exit 1
    fi
  done
  if [ "$pulled" != true ]; then
    echo "Image download failed: $image. No server-side build will be attempted." >&2
    exit 1
  fi

  image_id=$(docker image inspect --format '{{.Id}}' "$image")
  revision=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image_id")
  platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image_id")
  if [ "$revision" != "$RELEASE_VERSION" ] || [ "$platform" != linux/amd64 ]; then
    echo "Image metadata mismatch: $image (revision=$revision, platform=$platform)" >&2
    exit 1
  fi
  local_tag="task-mall-$kind:$RELEASE_VERSION"
  if existing_id=$(docker image inspect --format '{{.Id}}' "$local_tag" 2>/dev/null); then
    if [ "$existing_id" != "$image_id" ]; then
      echo "Refusing to replace an existing release image: $local_tag" >&2
      exit 1
    fi
  fi
  image_ids[$kind]=$image_id
done

# Freeze both verified IDs under the existing local names used by rollback.
for kind in api web; do
  docker tag "${image_ids[$kind]}" "task-mall-$kind:$RELEASE_VERSION"
  echo "Verified task-mall-$kind:$RELEASE_VERSION = ${image_ids[$kind]}"
done
