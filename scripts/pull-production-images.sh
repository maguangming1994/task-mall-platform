#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_VERSION:?Set RELEASE_VERSION to the tested commit}"
[[ "$RELEASE_VERSION" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release commit' >&2; exit 1; }

readonly IMAGE_PREFIX=ccr.ccs.tencentyun.com/task-mall-platform/task-mall
export DOCKER_CONFIG="${DEPLOY_DOCKER_CONFIG:-/var/lib/task-mall/tcr}"
if [ ! -r "$DOCKER_CONFIG/config.json" ]; then
  echo 'TCR login missing. Run scripts/server/configure-tcr.sh as the deployment user first.' >&2
  exit 1
fi
command -v timeout >/dev/null || { echo 'GNU timeout is required' >&2; exit 1; }

pull_timeout=${DEPLOY_PULL_TIMEOUT_SECONDS:-600}
pull_budget=${DEPLOY_PULL_BUDGET_SECONDS:-1200}
for value in "$pull_timeout" "$pull_budget"; do
  if [[ ! "$value" =~ ^[1-9][0-9]{0,3}$ ]] || (( value > 1200 )); then
    echo 'Pull timeout and total budget must be integers between 1 and 1200 seconds.' >&2
    exit 1
  fi
done
pull_deadline=$((SECONDS + pull_budget))

pull_log=$(mktemp)
trap 'rm -f "$pull_log"' EXIT
declare -A image_ids

for kind in api web; do
  image="$IMAGE_PREFIX-$kind:$RELEASE_VERSION"
  pulled=false
  for attempt in 1 2; do
    remaining=$((pull_deadline - SECONDS))
    if (( remaining <= 0 )); then
      echo "Total image download budget exhausted (${pull_budget}s). Check server connectivity to TCR before retrying." >&2
      exit 1
    fi
    attempt_timeout=$pull_timeout
    if (( remaining < attempt_timeout )); then attempt_timeout=$remaining; fi
    echo "Pulling $image (attempt $attempt/2, limit ${attempt_timeout}s, total remaining ${remaining}s)"
    pull_status=0
    timeout --kill-after=10s "${attempt_timeout}s" docker pull "$image" 2>&1 | tee "$pull_log" || pull_status=$?
    if [ "$pull_status" -eq 0 ]; then
      pulled=true
      break
    fi
    if [ "$pull_status" -eq 124 ] || [ "$pull_status" -eq 137 ]; then
      echo "Image download timed out after ${attempt_timeout}s; server-to-TCR transfer did not finish." >&2
    fi
    if grep -Eiq 'unauthorized|denied|forbidden|manifest unknown' "$pull_log"; then
      echo 'TCR rejected the image request. Check registry credentials, private repository access and the image build job.' >&2
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
