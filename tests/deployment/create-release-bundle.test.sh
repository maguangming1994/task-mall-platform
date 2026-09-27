#!/usr/bin/env bash
set -Eeuo pipefail

root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
git init -q "$tmp/source"
cd "$tmp/source"
git config user.name 'Deployment Test'
git config user.email 'deployment-test@example.invalid'
printf 'base content\n' > base.txt
git add base.txt
git commit -qm base
export BASE_SHA
BASE_SHA=$(git rev-parse HEAD)
original_base=$BASE_SHA

git init -q "$tmp/server"
git -C "$tmp/server" fetch -q "$tmp/source" "$BASE_SHA"
git -C "$tmp/server" checkout -q --detach FETCH_HEAD

printf 'new content\n' > new.txt
git add new.txt
git commit -qm new
export RELEASE_SHA
RELEASE_SHA=$(git rev-parse HEAD)
"$BASH" "$root/scripts/create-release-bundle.sh" "$tmp/release.bundle"
git -C "$tmp/server" bundle verify "$tmp/release.bundle"
git -C "$tmp/server" fetch -q "$tmp/release.bundle" refs/heads/task-mall-release:refs/task-mall/incoming
[ "$(git -C "$tmp/server" rev-parse refs/task-mall/incoming)" = "$RELEASE_SHA" ]
[ "$(git -C "$tmp/server" show refs/task-mall/incoming:base.txt)" = 'base content' ]
[ "$(git -C "$tmp/server" show refs/task-mall/incoming:new.txt)" = 'new content' ]
echo 'PASS: incremental bundle imports into a server that has only the base'

BASE_SHA=$RELEASE_SHA "$BASH" "$root/scripts/create-release-bundle.sh" "$tmp/rerun.bundle"
git -C "$tmp/server" bundle verify "$tmp/rerun.bundle"
echo 'PASS: rerunning the deployed commit still produces a valid bundle'

if BASE_SHA=invalid "$BASH" "$root/scripts/create-release-bundle.sh" "$tmp/invalid.bundle"; then exit 1; fi
if BASE_SHA=$RELEASE_SHA RELEASE_SHA=$original_base "$BASH" "$root/scripts/create-release-bundle.sh" "$tmp/downgrade.bundle"; then exit 1; fi
git checkout -q --detach "$BASE_SHA"
printf 'sibling content\n' > sibling.txt
git add sibling.txt
git commit -qm sibling
if BASE_SHA=$(git rev-parse HEAD) "$BASH" "$root/scripts/create-release-bundle.sh" "$tmp/divergent.bundle"; then exit 1; fi
echo 'PASS: invalid, downgraded and divergent bases are rejected'
