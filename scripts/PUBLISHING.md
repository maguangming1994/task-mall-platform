# Production publishing

Run the following command from the repository root after completing a change:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/publish-release.ps1 -Message "Describe this release"
```

The command preserves local work while rebasing `main` onto `origin/main`, stages the synchronized change set, runs the protected release checks, commits and pushes the release, then waits until the public production release marker matches the pushed commit. It also checks the production readiness endpoint, rebuilds the WeChat Mini Program, opens it in WeChat DevTools, and requests a preview QR code.

The QR image and metadata are written to `artifacts/wechat-preview/`. The directory is ignored by Git.

Before the first run, sign in to WeChat DevTools and leave its service port enabled. Set `WECHAT_DEVTOOLS_CLI` to the full path of `cli.bat` only when automatic discovery cannot find the installed DevTools CLI.

The release stops without publishing when a rebase conflict, protected check, production deployment, health check, or QR generation fails. Resolve the reported issue and rerun the same command; it never fabricates a successful deployment or preview code.
