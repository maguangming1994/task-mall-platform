# Production releases using private Tencent TCR images

## Confirmed repositories

The user created these PRIVATE personal-edition repositories in Guangzhou:

- `ccr.ccs.tencentyun.com/task-mall-platform/task-mall-api`
- `ccr.ccs.tencentyun.com/task-mall-platform/task-mall-web`

Do not purchase an enterprise instance, make these repositories public, disable
TLS verification, or send credentials to an unofficial mirror for this migration.
Personal edition has concurrency and capacity limits and no availability SLA.
Measure real upload/download performance before declaring migration successful.

## Before pushing the migration

1. In each repository's console, confirm the private visibility and namespace.
2. Read the registry username from the console's login command. It is not
   necessarily the GitHub username or the Tencent account display name.
3. Add `TCR_USERNAME` and `TCR_PASSWORD` as GitHub Actions `production`
   environment secrets. The password is the personal registry password set
   during initialization, not the Tencent cloud account login password.
   Do not put either value in Git, plain Actions variables or chat messages.
4. Log in on the server as the existing deployment user using
   `bash scripts/server/configure-tcr.sh`. It prompts for the password without
   echo and saves credentials to `/var/lib/task-mall/tcr/config.json` (0600)
   under a 0700 directory. It does not overwrite `/var/lib/task-mall/ghcr`.
   Personal-edition credentials may have broader scope than one repository;
   use the least privileges supported and do not claim these are read-only
   credentials without checking their actual permissions.
5. Only then push the tested migration and watch the image publication job.

If the new script is not yet present on the server, the equivalent interactive
login in the existing root terminal is:

```bash
(
set +x
set -e
umask 077
install -d -m 700 /var/lib/task-mall/tcr
read -r -p 'TCR username: ' registry_user
test -n "$registry_user"
DOCKER_CONFIG=/var/lib/task-mall/tcr docker login ccr.ccs.tencentyun.com --username "$registry_user"
chmod 600 /var/lib/task-mall/tcr/config.json
)
```

Enter the registry password at Docker's hidden password prompt. Only report
`Login Succeeded`; do not copy the password or Docker config into chat.

## Publication and rollback

GitHub builds Linux AMD64 API and Web images sequentially and tags them with
the full tested commit. Existing commit tags are reused on a rerun. The mutable
`buildcache` tag in each of the same two repositories is ONLY for build reuse;
deployment always pulls the commit tag. Inline cache avoids a separate BuildKit
cache artifact repository. Single-platform images are published without default
provenance/SBOM manifests for compatibility with the personal registry; commit
revision labels and platform checks remain mandatory. This does not add image
signing or attestations. Existing GitHub source provenance is not equivalent to
a signed artifact.

The workflow no longer needs GitHub Packages write permission or a GHCR token.
Existing deployment SSH secrets and `PRODUCTION_DEPLOY_ENABLED` stay unchanged.
Incremental Git bundles, the server deployment lock, PostgreSQL backups,
migrations and endpoint checks are retained. The receiver runs the deployment
script from the incoming commit, so the installed receiver need not change.

Server pulls use the dedicated TCR credential directory, sequential images,
at most two attempts per image, 600 seconds per attempt and a shared 1200-second
budget. Authentication/manifest errors stop immediately. Both image IDs must
pass commit/platform checks before either gets its local rollback tag.
Compose uses the existing `task-mall-api:<sha>` / `task-mall-web:<sha>` names.
Previously healthy local images remain available for rollback without GHCR.

An old failed Actions run still contains the old GHCR workflow. Do not rerun
that run to test TCR; use the new commit's run. Do not start manual pulls while
an automatic deployment owns the release lock, or remove the lock file.

## Acceptance

- API and Web publication steps succeed against the two PRIVATE TCR repos.
- Server logs explicitly pull from `ccr.ccs.tencentyun.com`, not GHCR.
- Server verifies the exact commit and Linux AMD64 platform for both images.
- The deployment succeeds and public `/release.json` equals the new full SHA.
- `/api/v1/health/ready` reports ready; `/` and `/admin/` load successfully.
- Test login and nearby school behavior in WeChat DevTools before issuing a new QR.

The production marker must not be edited manually to impersonate a deployment.
Until all checks pass, report the actual old version as still live.

## Failure evidence

GHCR run `36326033619` for `5ae0534` exhausted image download attempts even
after longer timeouts. Some layers completed but `92dc55c1de6a` retried and the
API image never completed. The receiver restored healthy `eb8330a` services.
IPv4 registry TLS probes succeeded; those small probes did not demonstrate
large-layer download health. No evidence supports treating IPv6 as the root
cause. The user subsequently created the private TCR repositories listed above.

## Local checks

```bash
bash -n scripts/server/configure-tcr.sh
bash -n scripts/pull-production-images.sh
bash tests/deployment/pull-production-images.test.sh
bash tests/deployment/create-release-bundle.test.sh
```

The mock Docker pull tests reject any repository other than the two exact TCR
targets and exercise success, retries, credential/manifest failures, bounded
downloads and rejection of mismatched release metadata. Mock tests do not prove
real TCR login or upload/download support; those require the acceptance above.
