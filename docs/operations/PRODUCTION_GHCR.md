# Production releases using private GHCR images

## Release path

1. Push reviewed changes to `main`. Frontend, API and deployment checks run first.
2. `Build and publish production images` builds Linux AMD64 images on GitHub,
   using private registry caches. It authenticates with the job's temporary
   `GITHUB_TOKEN` (`packages: write`), not a saved personal token.
3. Images are published at `ghcr.io/maguangming1994/task-mall-platform-api:<sha>`
   and `ghcr.io/maguangming1994/task-mall-platform-web:<sha>`. Keep both packages
   private. Source labels associate them with this repository. A rerun reuses
   an already-published commit tag rather than replacing it.
4. The existing restricted SSH receiver accepts `deploy <sha>` and the Git
   bundle. It retains the release lock, checks, database backup and rollback.
   The bundle contains only commits after the public production release marker.
   An invalid, missing or divergent base stops publication rather than silently
   uploading the entire Git history. Transfer byte counts are shown by `dd`.
5. The release script pulls both images, verifies the commit labels/platform,
   and tags the verified image IDs with the existing local rollback names.
   Production Compose has no build definitions. Application dependencies are
   never downloaded or compiled on the production server.
6. Migrations, container health, public endpoints, campus data and the exact
   `release.json` commit must pass before the receiver records success.

This works with the existing installed receiver, because it runs the deploy
script from the incoming commit. Replacing the receiver or granting general
SSH shell access is not required.

## One-time server authentication (required before the first release)

The existing five `PRODUCTION_SSH_*` secrets and
`PRODUCTION_DEPLOY_ENABLED=true` remain in use. The build job has package write
permission in the workflow. If organization policy prohibits that permission,
an administrator must allow publishing this repository's packages.

The server needs a GitHub classic personal access token with `read:packages`,
belonging to an account allowed to read both private packages. Do not paste
the token into chat, Git, Actions variables or a shell command line. Enter it
at the hidden prompt in the setup script, as the OS user running the receiver:

```bash
bash /path/to/this/checkout/scripts/server/configure-ghcr.sh
```

When the latest checkout is not yet available on the server, the equivalent
one-time interactive setup in the existing root terminal is:

```bash
install -d -m 700 /var/lib/task-mall/ghcr
read -r -s -p 'GitHub classic token (read:packages): ' registry_token; printf '\n'
printf '%s' "$registry_token" | DOCKER_CONFIG=/var/lib/task-mall/ghcr docker login ghcr.io -u maguangming1994 --password-stdin
unset registry_token
chmod 600 /var/lib/task-mall/ghcr/config.json
```

Docker stores the registry credential in this root-readable file, not in the
application environment or Git bundle. Revoke/replace it when it expires.
The GitHub job's temporary token cannot supply a persistent server login.

The server must reach `ghcr.io` and its layer download hosts over HTTPS. GHCR
does not guarantee fast access from every network in China. If image pulls
time out, verify connectivity or move the same build-and-pull flow to TCR;
do not restore server-side builds as a fallback.

## Frontend build variables

Set production environment variables in GitHub Actions:

- `VITE_H5_PUBLIC_URL`: defaults to `https://rwsc.jxgxnxs.cn`.
- `VITE_WECHAT_OA_APP_ID`: set if the installation relies on this build-time
  fallback; the backend public WeChat configuration remains preferred.
- API requests use `/api/v1` for the same-origin production gateway.

Values previously supplied only by the server's Compose build arguments
cannot affect prebuilt frontend assets. Never put AppSecret or other secrets
in any `VITE_*` variable.

## Failures and recovery

- SSH without server output can mean the receiver is still reading the Git
  bundle. The original full-history bundle measured 60,074,114 bytes; the
  `eb8330a` to `5355918` delta measured 8,609 bytes. That full upload was observed
  progressing from 15 MB to 16 MB over roughly two minutes, before image pulls.
  New runs use incremental bundles. Editing the workflow does not change an
  already-running job; avoid re-running an old full-bundle workflow.

- Build failure: inspect the separate image build job; production is untouched.
- `GHCR login missing`: finish the one-time server login, then rerun only the
  failed deployment job after the image job has succeeded.
- `denied`/`unauthorized`: check token scope, expiry, account/package access.
- `manifest unknown`: check the image job and exact commit tag.
- Network failures: two attempts per image, each bounded to 240 seconds.
  Logs show the image and pull progress; no local build fallback is allowed.
- Mismatched image revision/platform or an existing commit tag with different
  contents: stop before migrations. Investigate the package rather than
  overwriting a rollback image.
- Application verification failure: the existing receiver restores the
  previous local images; schema migrations remain forward-only.
- An older server-build deployment may still own the deployment slot. Inspect
  its processes before retrying; a lock file alone does not mean a live lock.

## Acceptance

The workflow being green is necessary but also verify the public marker:

```bash
curl -fsS https://rwsc.jxgxnxs.cn/release.json
curl -fsS https://rwsc.jxgxnxs.cn/api/v1/health/ready
```

The marker must equal the full tested commit, health must report `ready`, and
both `/` and `/admin/` must load. Then test login and nearby campus behavior in
WeChat DevTools against the production API and generate a fresh preview QR.
An old preview QR or a healthy previous release is not a new-release acceptance.

## Validation

```bash
bash -n scripts/deploy-production.sh
bash -n scripts/create-release-bundle.sh
bash -n scripts/pull-production-images.sh
bash -n scripts/server/configure-ghcr.sh
bash tests/deployment/pull-production-images.test.sh
bash tests/deployment/create-release-bundle.test.sh
```

The pull tests simulate success, temporary network failure/recovery, exhausted
retries, rejected credentials, wrong commit/platform and missing credentials.
They verify that failure never retags a partial pair of release images.

## Git network route

Use the project release script's command-scoped proxy detection. On this
workstation `http://127.0.0.1:8800` has been the successful Git HTTPS route.
Do not cycle through unverified mirrors or change the global Git proxy. Check
the remote `main` hash after pushing. No recurring monitoring is required.
