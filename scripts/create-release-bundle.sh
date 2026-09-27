#!/usr/bin/env bash
set -Eeuo pipefail

: "${RELEASE_SHA:?Set RELEASE_SHA to the tested commit}"
: "${BASE_SHA:?Set BASE_SHA from the production release marker}"
bundle_file=${1:?Specify the output bundle path}

for sha in "$RELEASE_SHA" "$BASE_SHA"; do
  [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release/base commit' >&2; exit 1; }
  git cat-file -e "$sha^{commit}"
done
git merge-base --is-ancestor "$BASE_SHA" "$RELEASE_SHA" || {
  echo 'Production is not an ancestor of the requested release; refusing a downgrade or divergent release.' >&2
  exit 1
}

# Reruns still send the last commit so the receiver can finish its own checks.
if [ "$BASE_SHA" = "$RELEASE_SHA" ]; then
  BASE_SHA=$(git rev-parse "$RELEASE_SHA^")
fi
git branch --force task-mall-release "$RELEASE_SHA"
git bundle create "$bundle_file" refs/heads/task-mall-release "^$BASE_SHA"
git bundle verify "$bundle_file"
bundle_bytes=$(wc -c < "$bundle_file")
echo "Incremental release: $BASE_SHA -> $RELEASE_SHA ($bundle_bytes bytes)"
[ "$bundle_bytes" -le 536870912 ] || { echo 'Bundle exceeds receiver size limit' >&2; exit 1; }
